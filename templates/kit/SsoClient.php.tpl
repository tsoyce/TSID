<?php
/**
 * {{APP_NAME}} SSO — клиент для {{CLIENT_NAME}}. Один файл, без зависимостей.
 * Умеет: discovery, PKCE, обмен кода, проверку id_token (RS256 по JWKS),
 * userinfo, refresh, кэш JWKS в файл на 24 ч.
 *
 * Правила (§7 integration.md): hash_equals для сравнений, токены не логируются.
 */
class SsoClient
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;

        /* Незаполненный плейсхолдер ловим здесь, а не на /token. Иначе сервер
           {{APP_NAME}} отвечает сухим invalid_client, и человек идёт искать
           поломку в карточке портала — тогда как всё дело в том, что секрет
           не вписан. Ошибка обязана называть причину, а не симптом. */
        foreach (['issuer', 'client_id', 'client_secret', 'redirect_uri'] as $k) {
            $v = (string)($cfg[$k] ?? '');
            if ($v === '' || str_contains($v, '{{')) {
                throw new RuntimeException(
                    /* Скобки обязательны: «»» — многобайтный знак, и PHP
                       затягивает его в имя переменной. «$k»» разбиралось как
                       переменная `$k»`, то есть подсказка теряла именно то
                       слово, ради которого писалась. */
                    "Подключение к {{APP_NAME}} не настроено: не задан «{$k}». "
                    . 'Запустите sso/install.php по ссылке установки из карточки портала '
                    . 'или впишите значение вручную.'
                );
            }
        }

        if (!is_dir($cfg['var_dir'])) {
            @mkdir($cfg['var_dir'], 0750, true);
        }
    }

    /* ---------- Поток входа ---------- */

    /** Сгенерировать state/nonce/verifier в сессию и вернуть authorize_url. */
    public function buildAuthorizeUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $_SESSION['sso_state'] = $state;
        $_SESSION['sso_nonce'] = $nonce;
        $_SESSION['sso_verifier'] = $verifier;
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return $this->cfg['issuer'] . '/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->cfg['client_id'],
            'redirect_uri' => $this->cfg['redirect_uri'],
            'scope' => $this->cfg['scopes'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Обработка callback: сверка state, обмен кода, проверка id_token. Возвращает claims. */
    public function handleCallback(string $code, string $state): array
    {
        if (!isset($_SESSION['sso_state']) || !hash_equals($_SESSION['sso_state'], $state)) {
            throw new RuntimeException('state mismatch — начните вход заново');
        }
        $verifier = $_SESSION['sso_verifier'] ?? '';
        $nonce = $_SESSION['sso_nonce'] ?? '';
        unset($_SESSION['sso_state'], $_SESSION['sso_verifier']);

        $tokens = $this->post($this->cfg['issuer'] . '/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->cfg['redirect_uri'],
            'client_id' => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'code_verifier' => $verifier,
        ]);
        if (isset($tokens['error'])) {
            throw new RuntimeException('token endpoint: ' . $tokens['error'] . ' — ' . ($tokens['error_description'] ?? ''));
        }
        $claims = $this->verifyJwt($tokens['id_token'] ?? '');
        if (($claims['nonce'] ?? '') === '' || !hash_equals($nonce, (string)$claims['nonce'])) {
            throw new RuntimeException('nonce mismatch');
        }
        unset($_SESSION['sso_nonce']);
        $claims['_tokens'] = [
            'access_token' => $tokens['access_token'] ?? null,
            'refresh_token' => $tokens['refresh_token'] ?? null, // храните в БД, не в cookie
            /* Сырой id_token нужен на выходе: /logout принимает его как
               id_token_hint и по нему понимает, кого именно провожает. Без
               него выход получается безымянным, и «выйти везде» не работает.
               Хранить — в серверной сессии, не в cookie. */
            'id_token' => $tokens['id_token'] ?? null,
            'expires_in' => $tokens['expires_in'] ?? 0,
        ];
        return $claims;
    }

    /** Claims из /userinfo по access_token. */
    public function userinfo(string $accessToken): array
    {
        return $this->request('GET', $this->cfg['issuer'] . '/userinfo', null,
            ['Authorization: Bearer ' . $accessToken]);
    }

    /** Обновление токенов (offline_access). */
    public function refresh(string $refreshToken): array
    {
        return $this->post($this->cfg['issuer'] . '/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
        ]);
    }

    /* ---------- Проверка JWT (id_token и event_token) ---------- */

    /** Полная проверка: подпись RS256 по JWKS (kid), iss, aud, exp. */
    public function verifyJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) throw new RuntimeException('это не JWT');
        [$h64, $p64, $s64] = $parts;
        $header = json_decode($this->b64($h64), true);
        $claims = json_decode($this->b64($p64), true);
        $sig = $this->b64($s64);
        if (!is_array($header) || !is_array($claims)) throw new RuntimeException('JWT не разбирается');
        if (($header['alg'] ?? '') !== 'RS256') throw new RuntimeException('ожидался RS256');

        $pem = $this->keyPem((string)($header['kid'] ?? ''));
        $ok = openssl_verify($h64 . '.' . $p64, $sig, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            // возможно, ключи ротировались — сброс кэша и вторая попытка
            $pem = $this->keyPem((string)($header['kid'] ?? ''), true);
            if (openssl_verify($h64 . '.' . $p64, $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) {
                throw new RuntimeException('подпись не сходится');
            }
        }
        if (($claims['iss'] ?? '') !== $this->cfg['issuer']) throw new RuntimeException('iss mismatch');
        $aud = $claims['aud'] ?? '';
        $audOk = is_array($aud) ? in_array($this->cfg['client_id'], $aud, true)
            : hash_equals($this->cfg['client_id'], (string)$aud);
        if (!$audOk) throw new RuntimeException('aud mismatch');
        if (isset($claims['exp']) && time() > (int)$claims['exp'] + 60) throw new RuntimeException('токен протух');
        return $claims;
    }

    /** Публичный ключ по kid из JWKS (файловый кэш 24 ч). */
    private function keyPem(string $kid, bool $forceRefresh = false): string
    {
        if ($kid === '') throw new RuntimeException('нет kid в заголовке');
        $cache = $this->cfg['var_dir'] . '/jwks.json';
        $jwks = null;
        if (!$forceRefresh && is_file($cache) && filemtime($cache) > time() - 86400) {
            $jwks = json_decode((string)file_get_contents($cache), true);
        }
        if (!is_array($jwks) || !$this->jwksHas($jwks, $kid)) {
            $jwks = $this->request('GET', $this->cfg['issuer'] . '/jwks.json');
            file_put_contents($cache, json_encode($jwks));
        }
        foreach ($jwks['keys'] ?? [] as $k) {
            if (($k['kid'] ?? '') === $kid && ($k['kty'] ?? '') === 'RSA') {
                return $this->jwkToPem($k);
            }
        }
        throw new RuntimeException('ключ kid=' . $kid . ' не найден в JWKS');
    }

    private function jwksHas(array $jwks, string $kid): bool
    {
        foreach ($jwks['keys'] ?? [] as $k) {
            if (($k['kid'] ?? '') === $kid) return true;
        }
        return false;
    }

    /** JWK (n, e) → PEM: сборка DER SubjectPublicKeyInfo руками. */
    private function jwkToPem(array $jwk): string
    {
        $n = $this->b64((string)$jwk['n']);
        $e = $this->b64((string)$jwk['e']);
        $int = function (string $bin): string {
            if ($bin !== '' && (ord($bin[0]) & 0x80)) $bin = "\x00" . $bin;
            return $this->asn1(0x02, $bin);
        };
        $rsa = $this->asn1(0x30, $int($n) . $int($e));
        $bitString = $this->asn1(0x03, "\x00" . $rsa);
        $algo = $this->asn1(0x30, hex2bin('06092a864886f70d0101010500')); // rsaEncryption + NULL
        $spki = $this->asn1(0x30, $algo . $bitString);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function asn1(int $type, string $content): string
    {
        $len = strlen($content);
        if ($len < 0x80) $lenBytes = chr($len);
        elseif ($len < 0x100) $lenBytes = "\x81" . chr($len);
        else $lenBytes = "\x82" . chr($len >> 8) . chr($len & 0xFF);
        return chr($type) . $lenBytes . $content;
    }

    private function b64(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/'), true);
        if ($d === false) throw new RuntimeException('base64url не декодируется');
        return $d;
    }

    /* ---------- HTTP ---------- */

    private function post(string $url, array $fields): array
    {
        return $this->request('POST', $url, http_build_query($fields));
    }

    private function request(string $method, string $url, ?string $body = null, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(
                $body !== null ? ['Content-Type: application/x-www-form-urlencoded'] : [], $headers),
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException('SSO недоступен: ' . $err);
        $json = json_decode((string)$resp, true);
        if (!is_array($json)) throw new RuntimeException('SSO ответил не-JSON');
        return $json;
    }

    /* ---------- Локальная сессия и sid-реестр (для backchannel logout) ---------- */

    /** Запомнить связку sid → id локальной сессии (файлово, без БД). */
    public function rememberSid(string $sid, string $sub = ''): void
    {
        if ($sid === '') return;
        file_put_contents($this->sidFile($sid), session_id());
        /* Индекс по человеку: события про человека целиком — удалён,
           заблокирован, доступ закрыт — приходят без sid, только с sub. Без
           индекса мост не мог погасить ни одной сессии такого человека (v4.62). */
        if ($sub !== '') {
            file_put_contents($this->subFile($sub), basename($this->sidFile($sid)) . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** Погасить локальную сессию по sid (вызывается из backchannel.php). */
    public function killSessionBySid(string $sid): void
    {
        $this->killByFile($this->sidFile($sid));
    }

    /** Погасить ВСЕ локальные сессии человека. @return int сколько погашено */
    public function killSessionsBySub(string $sub): int
    {
        if ($sub === '') return 0;
        $index = $this->subFile($sub);
        if (!is_file($index)) return 0;
        $n = 0;
        foreach ((array)file($index, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $name) {
            if (preg_match('~^sid-[0-9a-f]{32}$~', (string)$name) === 1
                && $this->killByFile($this->cfg['var_dir'] . '/' . $name)) {
                $n++;
            }
        }
        @unlink($index);
        return $n;
    }

    private function killByFile(string $file): bool
    {
        if (!is_file($file)) return false;
        $sessId = trim((string)file_get_contents($file));
        @unlink($file);
        if ($sessId === '' || !preg_match('~^[A-Za-z0-9,-]{16,128}$~', $sessId)) return false;
        // подменяем текущую сессию на целевую и уничтожаем её
        $current = session_id();
        session_write_close();
        session_id($sessId);
        session_start();
        unset($_SESSION['sso_user']);
        session_destroy();
        if ($current !== '' && $current !== $sessId) {
            session_id($current);
            @session_start();
            session_write_close();
        }
        return true;
    }

    private function sidFile(string $sid): string
    {
        return $this->cfg['var_dir'] . '/sid-' . substr(hash('sha256', $sid), 0, 32);
    }

    private function subFile(string $sub): string
    {
        return $this->cfg['var_dir'] . '/sub-' . substr(hash('sha256', $sub), 0, 32);
    }
}
