<?php

declare(strict_types=1);

use BinktermPHP\ExperiencePresentation;
use BinktermPHP\TelnetServer\DoorHandler;
use BinktermPHP\TelnetServer\TerminalShellInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/DoorHandler.php';

final class DoorHandlerEndParticipationTest extends TestCase
{
    private static function translator(): callable
    {
        return static function (string $key, array $params = [], string $fallback = ''): string {
            $text = $fallback !== '' ? $fallback : $key;
            foreach ($params as $name => $value) {
                $text = str_replace('{' . $name . '}', (string)$value, $text);
            }
            return $text;
        };
    }

    /** @return array<string,mixed> */
    private static function experience(string $backendType = 'native'): array
    {
        return [
            'id' => 'green-dragon',
            'name' => 'The Green Dragon Inn',
            'description' => 'A social door.',
            'category' => 'game',
            'backend' => ['type' => $backendType, 'id' => 'green-dragon'],
            'capabilities' => [
                'multiplayer' => true,
                'conversation' => ['type' => 'chat_room', 'room_id' => 42],
            ],
            'capacity' => ['max_sessions' => 20],
            'policy' => ['enabled' => true, 'credit_cost' => 0],
            'surfaces' => ['web' => 'full', 'telnet' => 'full'],
        ];
    }

    /** @return array<string,mixed> */
    private static function player(): array
    {
        return [
            'user_id' => 10,
            'username' => 'alice',
            'session_id' => 'door-session',
            'node' => 1,
        ];
    }

    /** @return array<string,mixed> */
    private static function state(array $experience, array $players): array
    {
        return [
            'experience' => $experience,
            'active' => $players !== [],
            'session_count' => count($players),
            'player_count' => count($players),
            'players' => $players,
        ];
    }

    /** @return array<string,mixed> */
    private static function view(bool $participating, string $backendType = 'native'): array
    {
        $experience = self::experience($backendType);
        $player = $participating ? self::player() : null;
        $state = self::state($experience, $player === null ? [] : [$player]);
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, $player);

        return DoorHandler::composeExperienceDetailView(
            $experience,
            $presentation,
            $state,
            [],
            $player,
            self::translator(),
            true
        );
    }

    public function testEndActionIsVisibleOnlyWhenSharedPresentationAllowsIt(): void
    {
        $participating = self::view(true);
        $notParticipating = self::view(false);

        self::assertTrue($participating['actions']['can_end']);
        self::assertContains('e', $participating['actions']['keys']);
        self::assertStringContainsString(
            'End Participation',
            implode('', array_column($participating['status_segments'], 'text'))
        );
        self::assertFalse($notParticipating['actions']['can_end']);
        self::assertNotContains('e', $notParticipating['actions']['keys']);
    }

    public function testRloginDoesNotReceiveInventedEndSemantics(): void
    {
        $view = self::view(true, 'rlogin');

        self::assertFalse($view['actions']['can_end']);
        self::assertNotContains('e', $view['actions']['keys']);
        self::assertStringNotContainsString(
            'End Participation',
            implode('', array_column($view['status_segments'], 'text'))
        );
    }

    public function testConfirmedEndCallsBackendAndRecomposesUpdatedDetail(): void
    {
        $before = self::view(true);
        $after = self::view(false);
        $views = [$before, $after];
        $reloads = 0;
        $endCalls = 0;
        $panelOptions = [];

        $shell = $this->createMock(TerminalShellInterface::class);
        $panelCall = 0;
        $shell->method('showScrollablePanel')->willReturnCallback(
            function (...$args) use (&$panelCall, &$panelOptions): string {
                $panelOptions[] = $args[4];
                return $panelCall++ === 0 ? 'end_participation' : 'quit';
            }
        );
        $shell->expects($this->once())
            ->method('showConfirmDialog')
            ->willReturn('y');
        $shell->expects($this->never())->method('showAlert');

        $reload = static function () use (&$reloads, $views): array {
            return $views[min($reloads++, 1)];
        };
        $onEnd = static function () use (&$endCalls): ?string {
            $endCalls++;
            return null;
        };

        $this->runLoop($shell, $reload, $onEnd);

        self::assertSame(1, $endCalls);
        self::assertSame(2, $reloads);
        self::assertSame('end_participation', $panelOptions[0]['extra_keys']['e']);
        self::assertArrayNotHasKey('e', $panelOptions[1]['extra_keys']);
        self::assertSame('launch', $panelOptions[1]['extra_keys']['g']);
    }

    public function testCancelledEndDoesNotMutateAndKeepsDetailAvailable(): void
    {
        $view = self::view(true);
        $reloads = 0;
        $endCalls = 0;

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showScrollablePanel')->willReturnOnConsecutiveCalls('end_participation', 'quit');
        $shell->expects($this->once())
            ->method('showConfirmDialog')
            ->willReturn('n');
        $shell->expects($this->never())->method('showAlert');

        $reload = static function () use (&$reloads, $view): array {
            $reloads++;
            return $view;
        };
        $onEnd = static function () use (&$endCalls): ?string {
            $endCalls++;
            return null;
        };

        $this->runLoop($shell, $reload, $onEnd);

        self::assertSame(0, $endCalls);
        self::assertSame(2, $reloads);
    }

    public function testBackendFailureShowsErrorAndKeepsDetailAvailable(): void
    {
        $view = self::view(true);
        $reloads = 0;

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showScrollablePanel')->willReturnOnConsecutiveCalls('end_participation', 'quit');
        $shell->method('showConfirmDialog')->willReturn('y');
        $shell->expects($this->once())
            ->method('showAlert')
            ->with(
                null,
                $this->anything(),
                'The Green Dragon Inn',
                'Unable to end participation: Backend unavailable',
                'error'
            );

        $reload = static function () use (&$reloads, $view): array {
            $reloads++;
            return $view;
        };

        $this->runLoop($shell, $reload, static fn (): ?string => 'Backend unavailable');

        self::assertSame(2, $reloads);
    }

    public function testPlayPeopleAndConversationActionsRemainIntact(): void
    {
        $view = self::view(false);
        $view['actions']['can_people'] = true;
        $log = [];

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showScrollablePanel')->willReturnOnConsecutiveCalls('launch', 'people', 'conversation', 'quit');

        $reload = static fn (): array => $view;
        $onLaunch = static function () use (&$log): void {
            $log[] = 'launch';
        };
        $onSocial = static function (string $action) use (&$log): void {
            $log[] = $action;
        };

        $this->runLoop($shell, $reload, static fn (): ?string => null, $onLaunch, $onSocial);

        self::assertSame(['launch', 'people', 'conversation'], $log);
    }

    public function testTelnetMutationUsesExistingAuthenticatedEndEndpointAndCsrf(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../telnet/src/DoorHandler.php');
        $method = $this->between(
            $source,
            'protected function endExperienceParticipation(',
            'private function runExperienceDetailLoop('
        );

        self::assertStringContainsString("'POST'", $method);
        self::assertStringContainsString("'/api/experiences/' . rawurlencode(\$experienceId) . '/end'", $method);
        self::assertStringContainsString('$csrfToken', $method);
        self::assertStringNotContainsString('ExperienceParticipation::end(', $method);
    }

    private function runLoop(
        TerminalShellInterface $shell,
        callable $reload,
        callable $onEnd,
        ?callable $onLaunch = null,
        ?callable $onSocial = null
    ): void {
        $method = new ReflectionMethod(DoorHandler::class, 'runExperienceDetailLoop');
        $method->setAccessible(true);
        $handler = (new ReflectionClass(DoorHandler::class))->newInstanceWithoutConstructor();
        $state = ['locale' => 'en'];
        $conn = null;

        $method->invokeArgs($handler, [
            $conn,
            &$state,
            $shell,
            $reload,
            $onLaunch ?? static function (): void {},
            self::translator(),
            $onSocial,
            $onEnd,
        ]);
    }

    private function between(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        self::assertNotFalse($startPosition);
        $endPosition = strpos($source, $end, $startPosition + strlen($start));
        self::assertNotFalse($endPosition);

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
