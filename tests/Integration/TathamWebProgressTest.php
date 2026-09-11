<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Run only against the disposable Slice 3 HTTP fixture, never application defaults. */
final class TathamWebProgressTest extends TestCase
{
    private const PAYLOAD = "SAVEFILE:41:Simon Tatham's Portable Puzzle Collection\nVERSION :1:1\nGAME    :8:Light Up\nPARAMS  :10:7x7b20s4d0\nCPARAMS :10:7x7b20s4d0\nDESC    :17:cBd0c1hBe2h1c0d0c\nNSTATES :1:6\nSTATEPOS:1:5\nMOVE    :4:L0,0\nMOVE    :4:I1,0\nMOVE    :4:L5,0\nMOVE    :4:I0,1\nMOVE    :4:I2,1\n";

    private function post(array $body, string $session = 'slice2-proof', string $csrf = 'slice3-csrf'): array
    {
        $base = getenv('TATHAM_WEB_TEST_BASE');
        if ($base !== 'http://127.0.0.1:43822') {
            throw new RuntimeException('Explicit isolated Slice 3 HTTP fixture required');
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true,
            'timeout' => 8, 'header' => "Content-Type: application/json\r\nCookie: binktermphp_session=$session\r\nX-CSRF-Token: $csrf\r\n",
            'content' => json_encode($body, JSON_THROW_ON_ERROR)]]);
        $response = file_get_contents($base . '/webdoors/tatham-web/api.php', false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
        return [(int)$match[1], json_decode($response, true, 32, JSON_THROW_ON_ERROR)];
    }

    public function testAuthenticationCsrfCanonicalParityIsolationAndConflicts(): void
    {
        self::assertSame(401, $this->post(['action' => 'acquire'], 'anonymous')[0]);
        self::assertSame(403, $this->post(['action' => 'acquire'], 'slice2-proof', 'wrong')[0]);
        [$code, $a] = $this->post(['action' => 'acquire', 'user_id' => 2]);
        self::assertSame(200, $code);
        self::assertArrayHasKey('owner_token', $a);
        try {
            [$code, $conflict] = $this->post(['action' => 'acquire']);
            self::assertSame(409, $code);
            self::assertArrayNotHasKey('owner_token', $conflict);
            [$code, $b] = $this->post(['action' => 'acquire'], 'slice3-other', 'slice3-other-csrf');
            self::assertSame(200, $code);
            self::assertNotSame($a['attempt_id'], $b['attempt_id']);
            self::assertEmpty($b['data']);
            $write = ['action' => 'save', 'owner_token' => $a['owner_token'],
                'attempt_id' => $a['attempt_id'], 'revision' => $a['revision'], 'payload' => self::PAYLOAD];
            self::assertSame(409, $this->post($write, 'slice3-other', 'slice3-other-csrf')[0]);
            [$code, $saved] = $this->post($write);
            self::assertSame(200, $code);
            self::assertSame($a['revision'] + 1, $saved['revision']);
            self::assertSame(self::PAYLOAD, $saved['data']['payload']);
            self::assertFalse($saved['data']['completed']);
            self::assertNull($saved['data']['completed_at']);
            self::assertSame(409, $this->post($write)[0]);
            $invalid = $write;
            $invalid['revision'] = $saved['revision'];
            $invalid['payload'] .= 'trailing junk';
            self::assertSame(422, $this->post($invalid)[0]);
            [$code, $renewed] = $this->post(['action' => 'renew', 'owner_token' => $a['owner_token']]);
            self::assertSame(200, $code);
            self::assertSame($saved['revision'], $renewed['revision']);
            self::assertSame(self::PAYLOAD, $renewed['data']['payload']);
            self::assertSame(200, $this->post(['action' => 'release', 'owner_token' => $a['owner_token']])[0]);
            [$code, $next] = $this->post(['action' => 'acquire']);
            self::assertSame(200, $code);
            self::assertSame(self::PAYLOAD, $next['data']['payload']);
            self::assertSame($saved['revision'], $next['revision']);
            self::assertSame(409, $this->post(['action' => 'release', 'owner_token' => $a['owner_token']])[0]);
            self::assertSame(200, $this->post(['action' => 'release', 'owner_token' => $next['owner_token']])[0]);
        } finally {
            $this->post(['action' => 'release', 'owner_token' => $a['owner_token']]);
            if (isset($b['owner_token'])) {
                $this->post(['action' => 'release', 'owner_token' => $b['owner_token']], 'slice3-other', 'slice3-other-csrf');
            }
        }
    }
}
