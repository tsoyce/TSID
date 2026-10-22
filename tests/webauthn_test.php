<?php
/**
 * WebAuthn: синтетические векторы. Генерируем EC P-256 ключ, собираем CBOR
 * руками (регистрация) и подписываем assertion — полный цикл без браузера.
 * Запуск: php -d extension=pdo_sqlite -d extension=sodium tests/webauthn_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Cbor;
use App\Core\Crypto;
use App\Core\Keys;
use App\Core\WebAuthn;

$fails = 0;
function check(bool $ok, string $name): void
{
    global $fails;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
    if (!$ok) $fails++;
}

/* ---- Мини-энкодер CBOR (только для теста) ---- */
function chead(int $major, int $len): string
{
    if ($len < 24) return chr(($major << 5) | $len);
    if ($len < 256) return chr(($major << 5) | 24) . chr($len);
    return chr(($major << 5) | 25) . pack('n', $len);
}
function cint(int $v): string { return $v >= 0 ? chead(0, $v) : chead(1, -1 - $v); }
function cbytes(string $s): string { return chead(2, strlen($s)) . $s; }
function ctext(string $s): string { return chead(3, strlen($s)) . $s; }
function cmap(array $pairs): string
{
    $out = chead(5, count($pairs));
    foreach ($pairs as [$k, $v]) $out .= $k . $v;
    return $out;
}

echo "CBOR-декодер:\n";
$roundtrip = cmap([[ctext('a'), cint(-7)], [ctext('b'), cbytes("\x01\x02")]]);
$dec = Cbor::decode($roundtrip);
check($dec === ['a' => -7, 'b' => "\x01\x02"], 'map/int/bytes роундтрип');
check(Cbor::decode(cint(1000)) === 1000, 'uint16');
check(Cbor::decode(cint(-257)) === -257, 'отрицательные (RS256 alg)');

echo "Регистрация ключа (attestation none, ES256):\n";
$args = Keys::opensslArgs();
$key = openssl_pkey_new($args + ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
if ($key === false) { echo "  ✗ не удалось создать EC-ключ: " . openssl_error_string() . "\n"; exit(1); }
$det = openssl_pkey_get_details($key);
$x = str_pad($det['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y = str_pad($det['ec']['y'], 32, "\x00", STR_PAD_LEFT);

$rpIdHash = hash('sha256', WebAuthn::rpId(), true);
$credId = random_bytes(16);
$cose = cmap([
    [cint(1), cint(2)],      // kty EC2
    [cint(3), cint(-7)],     // alg ES256
    [cint(-1), cint(1)],     // crv P-256
    [cint(-2), cbytes($x)],
    [cint(-3), cbytes($y)],
]);
$authData = $rpIdHash . chr(0x45) . pack('N', 0)          // UP+UV+AT, count 0
    . str_repeat("\x00", 16) . pack('n', strlen($credId)) . $credId . $cose;
$attObj = cmap([
    [ctext('fmt'), ctext('none')],
    [ctext('attStmt'), cmap([])],
    [ctext('authData'), cbytes($authData)],
]);
$challenge = random_bytes(32);
$clientData = json_encode([
    'type' => 'webauthn.create',
    'challenge' => Crypto::b64url($challenge),
    'origin' => WebAuthn::origin(),
]);

$cred = WebAuthn::verifyRegistration(Crypto::b64url($clientData), Crypto::b64url($attObj), $challenge);
check($cred['credential_id'] === Crypto::b64url($credId), 'credential_id извлечён');
check($cred['alg'] === -7, 'алгоритм ES256');
check(str_contains($cred['public_key'], 'BEGIN PUBLIC KEY'), 'COSE → PEM собран');
$reparsed = openssl_pkey_get_public($cred['public_key']);
check($reparsed !== false && openssl_pkey_get_details($reparsed)['ec']['x'] === $det['ec']['x'], 'PEM содержит ту же точку');

try {
    WebAuthn::verifyRegistration(Crypto::b64url($clientData), Crypto::b64url($attObj), random_bytes(32));
    check(false, 'чужой challenge отбит');
} catch (\Throwable) {
    check(true, 'чужой challenge отбит');
}

echo "Вход по ключу (assertion):\n";
$challenge2 = random_bytes(32);
$clientData2 = json_encode([
    'type' => 'webauthn.get',
    'challenge' => Crypto::b64url($challenge2),
    'origin' => WebAuthn::origin(),
]);
$authData2 = $rpIdHash . chr(0x05) . pack('N', 1);        // UP+UV, count 1
openssl_sign($authData2 . hash('sha256', $clientData2, true), $sig, $key, OPENSSL_ALGO_SHA256);

$newCount = WebAuthn::verifyAssertion(
    Crypto::b64url($clientData2), Crypto::b64url($authData2), Crypto::b64url($sig),
    $challenge2, $cred['public_key'], 0);
check($newCount === 1, 'подпись сошлась, sign_count=1');

try {
    WebAuthn::verifyAssertion(Crypto::b64url($clientData2), Crypto::b64url($authData2),
        Crypto::b64url(substr($sig, 0, -4) . "\x00\x00\x00\x00"), $challenge2, $cred['public_key'], 0);
    check(false, 'подделанная подпись отбита');
} catch (\Throwable) {
    check(true, 'подделанная подпись отбита');
}
try {
    $evil = json_encode(['type' => 'webauthn.get', 'challenge' => Crypto::b64url($challenge2), 'origin' => 'https://evil.example']);
    WebAuthn::verifyAssertion(Crypto::b64url($evil), Crypto::b64url($authData2), Crypto::b64url($sig),
        $challenge2, $cred['public_key'], 0);
    check(false, 'чужой origin отбит');
} catch (\Throwable) {
    check(true, 'чужой origin отбит');
}
try {
    WebAuthn::verifyAssertion(Crypto::b64url($clientData2), Crypto::b64url($authData2), Crypto::b64url($sig),
        $challenge2, $cred['public_key'], 5);
    check(false, 'клон-детект: sign_count не вырос');
} catch (\Throwable) {
    check(true, 'клон-детект: sign_count не вырос');
}

echo "\n" . ($fails === 0 ? "WEBAUTHN OK\n" : "WEBAUTHN FAILED: $fails\n");
exit($fails === 0 ? 0 : 1);
