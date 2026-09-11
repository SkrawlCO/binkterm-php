<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Explicit disposable Slice 5 HTTP fixture; never uses application DB defaults. */
final class TathamBbsLaunchTest extends TestCase
{
    private string $cookie = '';
    private string $csrf = '';

    private function post(string $path, array $body, bool $form = false): array
    {
        $base = getenv('TATHAM_BBS_TEST_BASE');
        if ($base !== 'http://127.0.0.1:43822') {
            throw new RuntimeException('Explicit disposable Slice 5 fixture required');
        }
        $headers = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json') . "\r\n";
        if ($this->cookie !== '') $headers .= 'Cookie: ' . $this->cookie . "\r\n";
        if ($this->csrf !== '') $headers .= 'X-CSRF-Token: ' . $this->csrf . "\r\n";
        $context = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true,
            'timeout' => 15, 'header' => $headers,
            'content' => $form ? http_build_query($body) : json_encode($body, JSON_THROW_ON_ERROR)]]);
        $raw = file_get_contents($base . $path, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        foreach ($http_response_header as $header) {
            if (preg_match('/^Set-Cookie: (binktermphp_session=[^;]+)/i', $header, $match)) {
                $this->cookie = $match[1];
            }
        }
        return [(int)$status[1], json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
    }

    public function testGroupedTerminalLaunchUsesNativeMemberAndKeepsSurfaceGate(): void
    {
        [$status, $login] = $this->post('/api/auth/login', ['username' => 'slice5b', 'password' => 'slice5-caller-test']);
        self::assertSame(200, $status);
        $this->csrf = $login['csrf_token'];
        self::assertNotSame('', $this->cookie);
        foreach (['web', 'invalid'] as $surface) {
            [$status] = $this->post('/api/door/launch', ['door' => 'tatham', 'surface' => $surface], true);
            self::assertSame(403, $status);
        }
        [$status, $launch] = $this->post('/api/door/launch', ['door' => 'tatham', 'surface' => 'terminal'], true);
        self::assertSame(200, $status);
        self::assertTrue($launch['success']);
        self::assertSame('native', $launch['session']['door_type']);
        self::assertNotEmpty($launch['session']['session_id']);
        [$status, $end] = $this->post('/api/door/end', ['session_id' => $launch['session']['session_id']], true);
        self::assertSame(200, $status);
        self::assertTrue($end['success']);
    }
}
