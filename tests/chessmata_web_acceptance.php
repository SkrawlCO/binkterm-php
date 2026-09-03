<?php

/**
 * Chessmata (Crossroads Experience #4) -- Slice 4 graphical Web acceptance.
 *
 * Disposable and self-cleaning. Proves the graphical-Web identity hand-off
 * against the REAL self-hosted service and the REAL reverse-proxy chain:
 *
 *   authenticated BinkTerm Web caller
 *     -> /games/chessmata-web  (generic WebDoor route -> webdoor_play.twig iframe)
 *       -> public_html/webdoors/chessmata-web/index.php  (fail-closed bootstrap)
 *         -> POST web-credential.php -> ChessmataWebSession::issue()
 *           -> ChessmataIdentity::webCredential()  (JWT, NOT the cmk_ key)
 *       -> seeds the OFFICIAL upstream SPA's own localStorage key
 *       -> /chessmata/  (same-origin, SAMEORIGIN-framable)  == same account as Telnet
 *
 * Run inside binkterm-app as the php-fpm user:
 *   docker exec -u www-data binkterm-app php /var/www/html/tests/chessmata_web_acceptance.php
 *
 * The browser leg (real graphical board + one real move) additionally runs if a
 * headless Chromium + playwright-core is present; otherwise it is skipped with a
 * note (the PHP + header + identity legs still run).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Crossroads\ChessmataIdentity;
use BinktermPHP\Crossroads\ChessmataWebSession;
use BinktermPHP\Database;

$pdo = Database::getInstance()->getPdo();
$broker = new ChessmataIdentity($pdo, null, null);

// In-container this reaches the binkterm-app Caddy (:80) -> chessmata:9029 and
// php-fpm: enough for the WebDoor fail-closed / hand-off / identity / Caddy-hop
// framing checks. The host-Apache CSP-scoping half of the header policy is
// proven with a host-side `curl https://binkterm.l33test.com/chessmata/` (see
// the Slice 4 report) -- Apache is not in the container's request path.
$base = getenv('CM_ACC_BASE') ?: 'http://localhost';
$viaApache = str_starts_with($base, 'https://');

$pass = 0;
$fail = 0;
$createdUserIds = [];
$createdChessmataIds = [];

function ok(string $label, bool $cond): void
{
    global $pass, $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

/** @return array{code:int, body:string, headers:string} */
function http(string $method, string $url, array $opts = []): array
{
    $extra = str_starts_with($url, 'https://binkterm.l33test.com')
        ? ['--resolve', 'binkterm.l33test.com:443:127.0.0.1'] : [];
    $cmd = array_merge(
        ['curl', '-sS', '-D', '-', '-o', '/tmp/.cm_s4_body', '-w', "\n%{http_code}", '-X', $method],
        $extra,
        $opts,
        [$url]
    );
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    $body = (string)@file_get_contents('/tmp/.cm_s4_body');
    @unlink('/tmp/.cm_s4_body');
    $parts = explode("\n", trim($out));
    $code = (int)array_pop($parts);

    return ['code' => $code, 'body' => $body, 'headers' => implode("\n", $parts)];
}

function makeAdmin(PDO $pdo, array &$sink): array
{
    $un = 'cmS4acc_' . substr(bin2hex(random_bytes(5)), 0, 10);
    $pdo->prepare(
        'INSERT INTO users (username, password_hash, email, real_name, is_active, is_admin, created_at)
         VALUES (?, ?, ?, ?, true, true, NOW())'
    )->execute([$un, 'x', $un . '@example.invalid', ucfirst($un)]);
    $id = (int)$pdo->query('SELECT id FROM users WHERE username = ' . $pdo->quote($un))->fetchColumn();
    $sink[] = $id;
    $sid = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO user_sessions (session_id, user_id, expires_at, ip_address, user_agent, last_activity, service)
         VALUES (?, ?, NOW() + INTERVAL '1 hour', ?, ?, NOW(), 'web')"
    )->execute([$sid, $id, '198.51.100.' . random_int(2, 250), 'cmS4-acceptance']);

    return ['id' => $id, 'username' => $un, 'session' => $sid];
}

echo "=== Chessmata Slice 4 graphical-Web acceptance ===\n";
echo "broker key configured : " . var_export(ChessmataIdentity::isAvailable(), true) . "\n\n";

$pyDriver = sys_get_temp_dir() . '/cm_s4_drv_' . bin2hex(random_bytes(4)) . '.cjs';

try {
    $a = makeAdmin($pdo, $createdUserIds);
    $b = makeAdmin($pdo, $createdUserIds);
    echo "[A] BinkTerm user {$a['id']}   [B] BinkTerm user {$b['id']}\n";

    // === 1. header policy for /chessmata/* (framing) ========================
    $h = http('GET', "$base/chessmata/");
    $hl = strtolower($h['headers']);
    ok('1  /chessmata/ carries no X-Frame-Options: DENY (stripped at the binkterm-app hop)',
        !str_contains($hl, 'x-frame-options: deny'));
    ok('   /chessmata/ declares frame-ancestors \'self\' (explicit same-origin framing)',
        str_contains($hl, "frame-ancestors 'self'"));
    ok('   /chessmata/ serves the app\'s own SPA CSP (blob:, raw.githack)',
        str_contains($hl, 'raw.githack.com') && str_contains($hl, 'worker-src'));
    if ($viaApache) {
        ok('2  X-Frame-Options is SAMEORIGIN (Apache global) with no DENY',
            str_contains($hl, 'x-frame-options: sameorigin'));
        ok('   the strict site CSP is NOT layered on /chessmata/*',
            !str_contains($h['headers'], "connect-src 'self' wss://binkterm.l33test.com"));
        $root = http('GET', "$base/login");
        ok('   a non-/chessmata route keeps the full site CSP unchanged',
            str_contains($root['headers'], "connect-src 'self' wss://binkterm.l33test.com")
            && !str_contains(strtolower($root['headers']), 'frame-ancestors'));
    } else {
        echo "  (Apache CSP-scoping half proven host-side: curl https://binkterm.l33test.com/chessmata/)\n";
    }

    // === 2. WebDoor fail-closed ============================================
    ok('3  index.php refuses an unauthenticated caller (403)',
        http('GET', "$base/webdoors/chessmata-web/index.php")['code'] === 403);
    ok('4  web-credential.php is POST-only (GET -> 405)',
        http('GET', "$base/webdoors/chessmata-web/web-credential.php")['code'] === 405);
    ok('5  web-credential.php refuses an unauthenticated POST (401)',
        http('POST', "$base/webdoors/chessmata-web/web-credential.php")['code'] === 401);
    ok('6  web-credential.php refuses a cross-origin POST even with a cookie (403)',
        http('POST', "$base/webdoors/chessmata-web/web-credential.php", [
            '-b', 'binktermphp_session=' . $a['session'],
            '-H', 'Sec-Fetch-Site: cross-site', '-H', 'Origin: https://evil.example',
        ])['code'] === 403);

    // === 3. authenticated hand-off ========================================
    $wc = http('POST', "$base/webdoors/chessmata-web/web-credential.php", [
        '-b', 'binktermphp_session=' . $a['session'],
        '-H', 'Sec-Fetch-Site: same-origin', '-H', 'Origin: ' . $base,
    ]);
    $j = json_decode($wc['body'], true) ?: [];
    ok('7  authenticated same-origin POST returns the hand-off (200)', $wc['code'] === 200);
    ok('   hand-off token is a JWT, never the cmk_ API key',
        str_starts_with((string)($j['access_token'] ?? ''), 'eyJ') && !str_contains($wc['body'], 'cmk_'));
    ok('   hand-off names the upstream SPA storage key + same-origin path',
        ($j['storage_key'] ?? '') === 'chessmata_auth_token' && ($j['client_path'] ?? '') === '/chessmata/');
    ok('   web-credential.php response is Cache-Control: no-store',
        str_contains(strtolower($wc['headers']), 'cache-control: no-store'));
    $createdChessmataIds[] = $j['chessmata_user_id'] ?? '';

    // === 4. identity convergence: web == telnet ==========================
    $acct = $broker->resolve($a['id']);
    $termKey = $broker->terminalCredential($a['id']);
    ok('8  the Web hand-off account == the Telnet/CLI account for the same BinkTerm user',
        ($j['chessmata_user_id'] ?? '') === $acct->chessmataUserId);
    foreach (['web JWT' => $j['access_token'], 'telnet cmk_' => $termKey] as $label => $tok) {
        $me = http('GET', "$base/chessmata/api/auth/me", ['-H', 'Authorization: Bearer ' . $tok]);
        $mj = json_decode($me['body'], true) ?: [];
        ok("   $label -> /api/auth/me id == the mapped account",
            ($mj['id'] ?? '') === $acct->chessmataUserId);
    }

    // === 5. no secret in any app/system log ==============================
    $key = (string)$j['access_token'];
    $grep = shell_exec('grep -rIl ' . escapeshellarg($key) . ' /var/www/html/data/logs /var/log 2>/dev/null | head');
    ok('9  the Web JWT appears in no app / system log', trim((string)$grep) === '');

    // === 6. browser leg (optional) ======================================
    $chrome = trim((string)shell_exec(
        'ls /root/openglad-assay/pw-browsers/*/chrome-linux64/chrome 2>/dev/null | head -1'
    ));
    $pwCore = '/root/.npm/_npx/e41f203b7505f1fb/node_modules/playwright-core';
    if ($chrome !== '' && is_dir($pwCore)) {
        file_put_contents($pyDriver, browserDriver($pwCore, $chrome));
        file_put_contents('/tmp/.cm_s4_sess.json', json_encode([
            'a' => $a, 'b' => $b, 'expectId' => $acct->chessmataUserId,
        ]));
        $out = (string)shell_exec('node ' . escapeshellarg($pyDriver) . ' 2>&1');
        @unlink('/tmp/.cm_s4_sess.json');
        echo "  --- browser leg ---\n";
        foreach (explode("\n", $out) as $ln) {
            if (preg_match('/^\s{2}(PASS|FAIL)\s{2}(.+)$/', $ln, $m)) {
                ok($m[2], $m[1] === 'PASS');
            } elseif (trim($ln) !== '') {
                echo "     $ln\n";
            }
        }
    } else {
        echo "  (browser leg skipped: no headless chromium / playwright-core on this host)\n";
        echo "  (run scratchpad/pw_min_proof.cjs against two disposable admin sessions for the graphical proof)\n";
    }
} catch (\Throwable $e) {
    $fail++;
    echo "  EXCEPTION  " . $e->getMessage() . "\n";
} finally {
    echo "\n[cleanup]\n";
    @unlink($pyDriver);
    foreach ($createdUserIds as $id) {
        $broker->forget($id);
        $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    echo "  local: forgot " . count($createdUserIds) . " mappings, deleted " . count($createdUserIds) . " test users + sessions\n";
    echo "  remote: chessmata ids to sweep -> " . implode(', ', array_filter($createdChessmataIds)) . "\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);


function browserDriver(string $pwCore, string $chrome): string
{
    $pwCoreJs = addslashes($pwCore);
    $chromeJs = addslashes($chrome);

    return <<<JS
const { chromium } = require('$pwCoreJs');
const fs = require('fs');
const S = JSON.parse(fs.readFileSync('/tmp/.cm_s4_sess.json','utf8'));
const BASE = 'https://binkterm.l33test.com';
const ok = (l,c)=>console.log((c?'  PASS  ':'  FAIL  ')+l);
const enter = async (ctx)=>{ const p=await ctx.newPage();
  await p.goto(BASE+'/games/chessmata-web',{waitUntil:'domcontentloaded',timeout:30000});
  let f=null; for(let i=0;i<40&&!f;i++){await p.waitForTimeout(500);f=p.frames().find(x=>x.url().endsWith('/chessmata/'));}
  await p.waitForTimeout(5000); return {p,f}; };
const me = (f)=>f.evaluate(async()=>{const t=localStorage.getItem('chessmata_auth_token');
  const r=await fetch('/chessmata/api/auth/me',{headers:{Authorization:'Bearer '+t}}); return r.ok?r.json():null;});
(async()=>{
  const b = await chromium.launch({executablePath:'$chromeJs',headless:true,args:['--no-sandbox']});
  const c1 = await b.newContext({ignoreHTTPSErrors:true});
  await c1.addCookies([{name:'binktermphp_session',value:S.a.session,url:BASE}]);
  const urls=[]; const {p:p1,f:f1}=await (async()=>{const p=await c1.newPage();
    p.on('request',r=>urls.push(r.url()));
    await p.goto(BASE+'/games/chessmata-web',{waitUntil:'domcontentloaded',timeout:30000});
    let f=null;for(let i=0;i<40&&!f;i++){await p.waitForTimeout(500);f=p.frames().find(x=>x.url().endsWith('/chessmata/'));}
    await p.waitForTimeout(5000);return {p,f};})();
  ok('B1 iframe enters the OFFICIAL SPA at same-origin /chessmata/', !!f1);
  if(!f1){await b.close();process.exit(1);}
  const v = await f1.evaluate(()=>({canvas:!!document.querySelector('canvas'),
    len:(document.getElementById('root')||{}).innerHTML?.length||0,
    body:document.body.innerText, tok:localStorage.getItem('chessmata_auth_token')||''}));
  ok('B2 graphical board renders (canvas + app mounted)', v.canvas && v.len>800);
  ok('B3 no Chessmata login/register prompt; caller name shown',
     !/Login\\s*\\/\\s*Sign\\s*Up/i.test(v.body) && v.body.includes(S.a.username));
  ok('B4 SPA holds a JWT (not a cmk_ key)', v.tok.startsWith('eyJ'));
  const m1 = await me(f1);
  ok('B5 graphical client authed as the SAME account id as Telnet', m1 && m1.id===S.expectId);
  const c2 = await b.newContext({ignoreHTTPSErrors:true});
  await c2.addCookies([{name:'binktermphp_session',value:S.b.session,url:BASE}]);
  const {f:f2}=await enter(c2);
  const m2 = await me(f2);
  ok('B6 a different BinkTerm identity -> a distinct Chessmata account', m2 && m2.id && m2.id!==S.expectId);
  const g = await f1.evaluate(async()=>{const A='/chessmata/api',t=localStorage.getItem('chessmata_auth_token');
    const H={'Content-Type':'application/json',Authorization:'Bearer '+t};
    const c=await (await fetch(A+'/games',{method:'POST',headers:H,body:JSON.stringify({playerId:crypto.randomUUID(),displayName:'a'})})).json();
    return {sid:c.sessionId,pid:c.playerId};});
  const j = await f2.evaluate(async(sid)=>{const A='/chessmata/api',t=localStorage.getItem('chessmata_auth_token');
    const H={'Content-Type':'application/json',Authorization:'Bearer '+t};
    const r=await (await fetch(A+'/games/'+sid+'/join',{method:'POST',headers:H,body:JSON.stringify({playerId:crypto.randomUUID(),displayName:'b'})})).json();
    return {pid:r.playerId};}, g.sid);
  const mv1 = await f1.evaluate(async(o)=>{const A='/chessmata/api',t=localStorage.getItem('chessmata_auth_token');
    const H={'Content-Type':'application/json',Authorization:'Bearer '+t};
    return (await fetch(A+'/games/'+o.sid+'/move',{method:'POST',headers:H,body:JSON.stringify({playerId:o.pid,from:'e2',to:'e4'})})).json();}, {sid:g.sid,pid:g.pid});
  await new Promise(r=>setTimeout(r,1500));
  const mv2 = await f2.evaluate(async(o)=>{const A='/chessmata/api',t=localStorage.getItem('chessmata_auth_token');
    const H={'Content-Type':'application/json',Authorization:'Bearer '+t};
    return (await fetch(A+'/games/'+o.sid+'/move',{method:'POST',headers:H,body:JSON.stringify({playerId:o.pid,from:'e7',to:'e5'})})).json();}, {sid:g.sid,pid:j.pid});
  await new Promise(r=>setTimeout(r,1500));
  const seen = await f1.evaluate(async(sid)=>{const r=await fetch('/chessmata/api/games/'+sid+'/moves');
    const j=await r.json(); return (j.moves||[]).map(x=>(x.from||'')+(x.to||'')).join(',');}, g.sid);
  ok('B7 one real move each way through the graphical clients -> self-hosted board recorded e2e4,e7e5',
     /e2e4/.test(seen)&&/e7e5/.test(seen) && mv1 && !mv1.error && mv2 && !mv2.error);
  ok('B8 no token in any request URL / browser history',
     !urls.some(u=>/access_token=|chessmata_auth_token=|Bearer|eyJhbGciOiJ/.test(u)));
  let end=false; p1.on('request',r=>{if(r.url().includes('/api/webdoor/session/end'))end=true;});
  await p1.goto(BASE+'/games',{waitUntil:'domcontentloaded',timeout:20000});
  await p1.waitForTimeout(1500);
  ok('B9 leaving the WebDoor fires /api/webdoor/session/end', end);
  const {f:f1b}=await enter(c1);
  const m1b = f1b && await me(f1b);
  ok('B10 relaunch resolves to the same Chessmata account', m1b && m1b.id===S.expectId);
  await b.close();
})().catch(e=>{console.error('DRIVER FATAL',e.message);process.exit(2);});
JS;
}
