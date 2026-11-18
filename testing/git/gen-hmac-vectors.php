<?php
/**
 * Regenerates testing/git/hmac-vectors.json, the shared HMAC test vectors for
 * the GitHost plugin and the git broker. Uses only PHP built-ins (hash_hmac,
 * hash), never either side's implementation, so the vectors are an independent
 * oracle.
 *
 *   php testing/git/gen-hmac-vectors.php > testing/git/hmac-vectors.json
 *
 * The broker vendors a copy at tests/fixtures/hmac-vectors.json; both sides pin
 * the file's sha256, so after regenerating, update HMAC_VECTORS_SHA256 in the
 * broker's tests/hmac_vectors.rs and GitHost/Test/HmacTest.php and recopy.
 */

const SECRET = 'githost-test-secret-0123456789abcdef';
const WRONG_SECRET = 'githost-wrong-secret-fedcba9876543210';
const NOW = 1763400000;

function canonical(string $ts, string $nonce, string $method, string $path, string $body): string
{
    return $ts . "\n" . $nonce . "\n" . $method . "\n" . $path . "\n" . hash('sha256', $body);
}

/** A request as sent: signed over $signed (defaults to the sent fields), with $secret. */
function req(array $sent, array $signed = [], string $secret = SECRET): array
{
    $s = array_merge($sent, $signed);
    return [
        'method' => $sent['method'],
        'path' => $sent['path'],
        'body' => $sent['body'],
        'headers' => [
            'X-GitHost-Timestamp' => $sent['ts'],
            'X-GitHost-Nonce' => $sent['nonce'],
            'X-GitHost-Signature' => hash_hmac('sha256', canonical($s['ts'], $s['nonce'], $s['method'], $s['path'], $s['body']), $secret),
        ],
    ];
}

$ts = (string) NOW;
$post = ['method' => 'POST', 'path' => '/authz', 'body' => '{"user_id":7,"project_id":3,"access":"write"}', 'ts' => $ts, 'nonce' => 'n-valid-0000000000000001'];
$del = ['method' => 'DELETE', 'path' => '/tokens?task_id=12', 'body' => '', 'ts' => $ts, 'nonce' => 'n-query-0000000000000002'];
$at = fn (array $r, int $t, string $nonce) => array_merge($r, ['ts' => (string) $t, 'nonce' => $nonce]);

$cases = [
    ['name' => 'valid', 'now' => NOW, 'steps' => [req($post)], 'expect' => ['accept']],
    ['name' => 'valid_with_query', 'now' => NOW, 'steps' => [req($del)], 'expect' => ['accept']],
    ['name' => 'tampered_body', 'now' => NOW,
     'steps' => [req(array_merge($post, ['body' => '{"user_id":7,"project_id":3,"access":"admin"}']), ['body' => $post['body']])],
     'expect' => ['reject']],
    ['name' => 'tampered_path', 'now' => NOW,
     'steps' => [req(array_merge($post, ['path' => '/authz2']), ['path' => '/authz'])], 'expect' => ['reject']],
    ['name' => 'tampered_query', 'now' => NOW,
     'steps' => [req(array_merge($del, ['path' => '/tokens?task_id=13']), ['path' => '/tokens?task_id=12'])], 'expect' => ['reject']],
    ['name' => 'tampered_method', 'now' => NOW,
     'steps' => [req(array_merge($post, ['method' => 'PUT']), ['method' => 'POST'])], 'expect' => ['reject']],
    ['name' => 'wrong_secret', 'now' => NOW, 'steps' => [req($post, [], WRONG_SECRET)], 'expect' => ['reject']],
    ['name' => 'ts_plus_61', 'now' => NOW, 'steps' => [req($at($post, NOW + 61, 'n-skew-00000000000000p61'))], 'expect' => ['reject']],
    ['name' => 'ts_minus_61', 'now' => NOW, 'steps' => [req($at($post, NOW - 61, 'n-skew-00000000000000m61'))], 'expect' => ['reject']],
    ['name' => 'ts_plus_60', 'now' => NOW, 'steps' => [req($at($post, NOW + 60, 'n-skew-00000000000000p60'))], 'expect' => ['accept']],
    ['name' => 'ts_minus_60', 'now' => NOW, 'steps' => [req($at($post, NOW - 60, 'n-skew-00000000000000m60'))], 'expect' => ['accept']],
    // Signed as sent; only the strict digits-only ts parse rejects it.
    ['name' => 'ts_not_numeric', 'now' => NOW, 'steps' => [req(array_merge($post, ['ts' => NOW . '.0', 'nonce' => 'n-skew-0000000000000nan']))], 'expect' => ['reject']],
    // Signed as sent; a newline in the nonce would make the canonical string ambiguous.
    ['name' => 'nonce_with_newline', 'now' => NOW, 'steps' => [req($at($post, NOW, "n-bad-000000000000000\nx"))], 'expect' => ['reject']],
    ['name' => 'replayed_nonce', 'now' => NOW, 'steps' => [req($post), req($post)], 'expect' => ['accept', 'reject']],
    ['name' => 'rejected_request_does_not_burn_nonce', 'now' => NOW,
     'steps' => [req(array_merge($post, ['body' => '{}']), ['body' => $post['body']]), req($post)], 'expect' => ['reject', 'accept']],
    ['name' => 'nonce_reusable_after_120s', 'now' => NOW,
     'steps' => [
         req($post),
         req($at($post, NOW + 120, $post['nonce'])) + ['now' => NOW + 120],
         req($at($post, NOW + 121, $post['nonce'])) + ['now' => NOW + 121],
     ],
     'expect' => ['accept', 'reject', 'accept']],
];

$signing = [];
foreach ([$post, $del] as $r) {
    $c = canonical($r['ts'], $r['nonce'], $r['method'], $r['path'], $r['body']);
    $signing[] = [
        'secret' => SECRET, 'ts' => $r['ts'], 'nonce' => $r['nonce'], 'method' => $r['method'],
        'path' => $r['path'], 'body' => $r['body'], 'canonical' => $c, 'signature' => hash_hmac('sha256', $c, SECRET),
    ];
}

echo json_encode([
    'version' => 1,
    'description' => 'Shared HMAC vectors for GitHost <-> broker. signature = hex(HMAC-SHA256(secret, ts \\n nonce \\n METHOD \\n path?query \\n sha256hex(body))). ts within +-60 s of now; a nonce seen in the last 120 s is rejected; a rejected request never records its nonce. A step may override the case now.',
    'secret' => SECRET,
    'max_skew_seconds' => 60,
    'nonce_ttl_seconds' => 120,
    'signing' => $signing,
    'cases' => $cases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
