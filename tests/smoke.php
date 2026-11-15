<?php
/** Smoke test, run INSIDE the roundcubemail container: docker exec -e FO_USER=... -e FO_PASS=... roundcubemail php /tmp/smoke.php — black-box autologon-free, asserts what the browser would see. Credentials via env only, never in the repo. */

$base = 'http://127.0.0.1';
$user = getenv('FO_USER') ?: '';
$pass = getenv('FO_PASS') ?: '';
$jar  = '/tmp/folderorder-smoke-cookies.txt';
@unlink($jar);

function fetch(string $url, ?string $post = null, array $headers = []): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
    ]);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, $body];
}

// Row order as the browser reads it: anchor texts of the folders table, in document order.
function row_order(string $html): array
{
    preg_match_all('/<a href="#">([^<]+)<\/a>/', $html, $m);

    return array_slice($m[1], 0, 5);
}

$ok = true;
$checks = [];

// Unauthenticated: the save endpoint must refuse — core CSRF/session gate, not ours.
[$code, $refused] = fetch("{$base}/?_task=settings&_action=plugin.folderorder-save",
    http_build_query(['_order' => '["INBOX"]']));
$checks['save refused without session'] = str_contains($refused, 'session') || $code >= 400;

if ($user === '' || $pass === '') {
    echo "FO_USER/FO_PASS unset — running unauthenticated checks only\n";
} else {
    [$code, $login] = fetch("{$base}/?_task=login");
    $token = preg_match('/name="_token" value="([^"]+)"/', $login, $m) ? $m[1] : '';
    $checks['login page served'] = $code === 200 && $token !== '';

    [$code, $after] = fetch("{$base}/?_task=login",
        http_build_query(['_task' => 'login', '_action' => 'login', '_token' => $token, '_user' => $user, '_pass' => $pass]));
    $checks['login succeeded'] = $code === 200 && str_contains($after, 'task=mail') !== false || str_contains($after, 'settings');

    // Folders page must inline our arrows (regression: include_script silently never loaded).
    [$code, $folders] = fetch("{$base}/?_task=settings&_action=folders");
    $checks['arrows inlined on folders page'] = str_contains($folders, 'rcfo-arrows');
    $rtoken = preg_match('/"request_token":"([^"]+)"/', $folders, $m) ? $m[1] : '';
    $checks['request token extracted'] = $rtoken !== '';

    // Save a known order, then assert the server render follows it.
    $order = ['Drafts', 'Junk', 'Sent', 'Trash', 'INBOX'];
    $want  = ['Drafts', 'Junk', 'Sent', 'Trash', 'Inbox']; // Elastic displays INBOX as Inbox
    [$code, $saved] = fetch("{$base}/?_task=settings&_action=plugin.folderorder-save",
        http_build_query(['_token' => $rtoken, '_order' => json_encode($order)]),
        ['X-Roundcube-Request: ' . $rtoken]); // plain $.post without this header gets HTML, not JSON
    $checks['save returns json ok'] = $code === 200 && str_contains($saved, '"exec":""');

    [$code, $reread] = fetch("{$base}/?_task=settings&_action=folders");
    $checks['render follows saved order'] = row_order($reread) === $want;

    // Broken payload must keep the previous order — never wipe.
    [$code, $junk] = fetch("{$base}/?_task=settings&_action=plugin.folderorder-save",
        http_build_query(['_token' => $rtoken, '_order' => '["INBOX",,,,broken']),
        ['X-Roundcube-Request: ' . $rtoken]);
    [$code, $after_junk] = fetch("{$base}/?_task=settings&_action=folders");
    $checks['broken payload keeps order'] = row_order($after_junk) === $want;
}

foreach ($checks as $name => $passed) {
    echo ($passed ? '  ok   ' : '  FAIL ') . $name . "\n";
    $ok = $ok && $passed;
}

@unlink($jar);
echo $ok ? "SMOKE PASS\n" : "SMOKE FAIL\n";
exit($ok ? 0 : 1);
