<?php

declare(strict_types=1);

namespace App\Core;

/**
 * WebAuthn (passkeys) без зависимостей: attestation 'none', алгоритмы ES256/RS256.
 * Discoverable credentials → вход с телефона по QR (hybrid) работает из коробки.
 * Чистые функции: сессиями и БД занимается контроллер.
 */
final class WebAuthn
{
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;

    /** rpId — хост issuer'а (без порта и схемы). */
    public static function rpId(): string
    {
        return (string)(parse_url(base_url(), PHP_URL_HOST) ?: 'localhost');
    }

    public static function origin(): string
    {
        $p = parse_url(base_url());
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'localhost');
        if (isset($p['port'])) $origin .= ':' . $p['port'];
        return $origin;
    }

    /* ---------- Регистрация ключа ---------- */

    /** Опции для navigator.credentials.create(). Challenge хранит вызывающий. */
    public static function registrationOptions(array $user, array $existingCredentialIds, string $challenge): array
    {
        return [
            'challenge' => Crypto::b64url($challenge),
            'rp' => ['id' => self::rpId(), 'name' => Setting::appName()],
            'user' => [
                'id' => Crypto::b64url($user['public_id']),
                'name' => $user['email'],
                'displayName' => user_name($user),
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => self::ALG_ES256],
                ['type' => 'public-key', 'alg' => self::ALG_RS256],
            ],
            'authenticatorSelection' => [
                'residentKey' => 'required',        // discoverable: вход без ввода логина
                'userVerification' => 'preferred',
            ],
            'excludeCredentials' => array_map(
                fn($id) => ['type' => 'public-key', 'id' => $id],
                $existingCredentialIds
            ),
            'timeout' => 120000,
            'attestation' => 'none',
        ];
    }

    /**
     * Проверка ответа create(). Возвращает
     * ['credential_id'=>b64url, 'public_key'=>PEM, 'alg'=>int, 'sign_count'=>int].
     */
    public static function verifyRegistration(string $clientDataJsonB64, string $attestationObjectB64,
                                              string $expectedChallenge): array
    {
        $clientData = self::checkClientData($clientDataJsonB64, 'webauthn.create', $expectedChallenge);
        $attObj = Crypto::b64urlDecode($attestationObjectB64);
        if ($attObj === false) throw new \RuntimeException('attestationObject не декодируется');
        $att = Cbor::decode($attObj);
        if (!is_array($att) || !isset($att['authData']) || !is_string($att['authData'])) {
            throw new \RuntimeException('attestationObject без authData');
        }
        $auth = self::parseAuthData($att['authData'], true);
        return [
            'credential_id' => Crypto::b64url($auth['credential_id']),
            'public_key' => self::coseToPem($auth['cose']),
            'alg' => (int)($auth['cose'][3] ?? self::ALG_ES256),
            'sign_count' => $auth['sign_count'],
        ];
    }

    /* ---------- Вход по ключу ---------- */

    /** Опции для navigator.credentials.get(): пустой allowCredentials → QR/hybrid доступен. */
    public static function authOptions(string $challenge): array
    {
        return [
            'challenge' => Crypto::b64url($challenge),
            'rpId' => self::rpId(),
            'allowCredentials' => [],
            'userVerification' => 'preferred',
            'timeout' => 120000,
        ];
    }

    /**
     * Проверка assertion. $storedPem/$storedSignCount — из БД по credential_id.
     * Возвращает новый sign_count. Бросает исключение при любом несовпадении.
     */
    public static function verifyAssertion(string $clientDataJsonB64, string $authenticatorDataB64,
                                           string $signatureB64, string $expectedChallenge,
                                           string $storedPem, int $storedSignCount): int
    {
        self::checkClientData($clientDataJsonB64, 'webauthn.get', $expectedChallenge);
        $authData = Crypto::b64urlDecode($authenticatorDataB64);
        $sig = Crypto::b64urlDecode($signatureB64);
        $clientDataRaw = Crypto::b64urlDecode($clientDataJsonB64);
        if ($authData === false || $sig === false || $clientDataRaw === false) {
            throw new \RuntimeException('Поля assertion не декодируются');
        }
        $parsed = self::parseAuthData($authData, false);

        $key = openssl_pkey_get_public($storedPem);
        if ($key === false) throw new \RuntimeException('Сохранённый публичный ключ не читается');
        $signedPayload = $authData . hash('sha256', $clientDataRaw, true);
        if (openssl_verify($signedPayload, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('Подпись passkey не сходится');
        }
        // signCount: синхронизируемые passkeys (iCloud/Google) часто держат 0 — это норма.
        // Ненулевой счётчик, который не вырос, — возможный клон: наверх, пусть аудитят.
        $new = $parsed['sign_count'];
        if ($new !== 0 && $storedSignCount !== 0 && $new <= $storedSignCount) {
            throw new \RuntimeException('sign_count не вырос — возможное клонирование ключа');
        }
        return $new;
    }

    /* ---------- Внутренности ---------- */

    private static function checkClientData(string $b64, string $expectedType, string $expectedChallenge): array
    {
        $raw = Crypto::b64urlDecode($b64);
        if ($raw === false) throw new \RuntimeException('clientDataJSON не декодируется');
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException('clientDataJSON не разбирается');
        if (($data['type'] ?? '') !== $expectedType) throw new \RuntimeException('clientData.type неверен');
        $challenge = (string)($data['challenge'] ?? '');
        if (!hash_equals(Crypto::b64url($expectedChallenge), $challenge)) {
            throw new \RuntimeException('challenge не совпал (протух или подделан)');
        }
        if (!hash_equals(self::origin(), (string)($data['origin'] ?? ''))) {
            throw new \RuntimeException('origin не совпал: ' . ($data['origin'] ?? '—'));
        }
        return $data;
    }

    /** authData: rpIdHash(32) flags(1) signCount(4) [attestedCredentialData]. */
    private static function parseAuthData(string $bin, bool $expectCredential): array
    {
        if (strlen($bin) < 37) throw new \RuntimeException('authData слишком короткий');
        $rpIdHash = substr($bin, 0, 32);
        if (!hash_equals(hash('sha256', self::rpId(), true), $rpIdHash)) {
            throw new \RuntimeException('rpIdHash не совпал с ' . self::rpId());
        }
        $flags = ord($bin[32]);
        if (!($flags & 0x01)) throw new \RuntimeException('User Presence не подтверждён');
        $signCount = unpack('N', substr($bin, 33, 4))[1];
        $result = ['flags' => $flags, 'sign_count' => (int)$signCount];

        if ($expectCredential) {
            if (!($flags & 0x40)) throw new \RuntimeException('Нет attested credential data');
            if (strlen($bin) < 55) throw new \RuntimeException('authData без credential data');
            $credIdLen = unpack('n', substr($bin, 53, 2))[1];
            $result['credential_id'] = substr($bin, 55, $credIdLen);
            if (strlen($result['credential_id']) !== $credIdLen) throw new \RuntimeException('credentialId обрезан');
            $offset = 55 + $credIdLen;
            $result['cose'] = Cbor::decode($bin, $offset);
        }
        return $result;
    }

    /** COSE-ключ → SPKI PEM. EC2 P-256 или RSA (§ COSE: RFC 9052). */
    public static function coseToPem(mixed $cose): string
    {
        if (!is_array($cose)) throw new \RuntimeException('COSE-ключ не map');
        $kty = (int)($cose[1] ?? 0);
        if ($kty === 2) { // EC2
            if ((int)($cose[-1] ?? 0) !== 1) throw new \RuntimeException('Поддерживается только кривая P-256');
            $x = (string)($cose[-2] ?? '');
            $y = (string)($cose[-3] ?? '');
            if (strlen($x) !== 32 || strlen($y) !== 32) throw new \RuntimeException('Координаты EC-точки не 32 байта');
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            return self::derToPem($der);
        }
        if ($kty === 3) { // RSA
            $n = (string)($cose[-1] ?? '');
            $e = (string)($cose[-2] ?? '');
            if ($n === '' || $e === '') throw new \RuntimeException('RSA-ключ без n/e');
            $int = function (string $b): string {
                if ($b !== '' && (ord($b[0]) & 0x80)) $b = "\x00" . $b;
                return self::asn1(0x02, $b);
            };
            $rsa = self::asn1(0x30, $int($n) . $int($e));
            $spki = self::asn1(0x30,
                self::asn1(0x30, hex2bin('06092a864886f70d0101010500')) . self::asn1(0x03, "\x00" . $rsa));
            return self::derToPem($spki);
        }
        throw new \RuntimeException('Неподдерживаемый тип COSE-ключа: kty=' . $kty);
    }

    private static function asn1(int $type, string $content): string
    {
        $len = strlen($content);
        if ($len < 0x80) $lenBytes = chr($len);
        elseif ($len < 0x100) $lenBytes = "\x81" . chr($len);
        else $lenBytes = "\x82" . chr($len >> 8) . chr($len & 0xFF);
        return chr($type) . $lenBytes . $content;
    }

    private static function derToPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
