<?php

declare(strict_types=1);

namespace App\Core;

/**
 * RSA-ключи подписи: storage/keys/k{ts}.pem (+ .pub.pem), kid = k<unixtime>.
 * Ротация (§3.4): новый ключ подписывает, старый остаётся в JWKS 48 ч, потом архив.
 * Реестр: settings key 'keys.registry' = {kid: {created, retired_at|null}}.
 */
final class Keys
{
    public static function dir(): string
    {
        return rtrim((string)config('keys.dir', SSO_ROOT . '/storage/keys'), '/\\');
    }

    public static function activeKid(): string
    {
        $kid = (string)Setting::get('keys.active_kid', config('keys.active_kid', ''));
        if ($kid === '') throw new \RuntimeException('Активный ключ подписи не настроен');
        return $kid;
    }

    /** Windows-сборки PHP не находят openssl.cnf сами — подсовываем свой. */
    public static function opensslArgs(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') return [];
        $candidates = [
            getenv('OPENSSL_CONF') ?: '',
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            dirname(PHP_BINARY) . '/openssl.cnf',
        ];
        foreach ($candidates as $c) {
            if ($c !== '' && is_file($c)) return ['config' => $c];
        }
        $fallback = SSO_ROOT . '/storage/cache/openssl.cnf';
        if (!is_file($fallback)) {
            if (!is_dir(dirname($fallback))) @mkdir(dirname($fallback), 0750, true);
            file_put_contents($fallback, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
        }
        return ['config' => $fallback];
    }

    /** Генерация новой пары. Возвращает kid. Используется инсталлятором и ротацией. */
    public static function generate(?string $dir = null): string
    {
        $dir = $dir ?? self::dir();
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $kid = 'k' . time();
        $args = self::opensslArgs();
        $res = openssl_pkey_new($args + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) throw new \RuntimeException('Не удалось сгенерировать RSA-ключ: ' . openssl_error_string());
        openssl_pkey_export($res, $privatePem, null, $args);
        $details = openssl_pkey_get_details($res);
        file_put_contents("$dir/$kid.pem", $privatePem);
        file_put_contents("$dir/$kid.pub.pem", $details['key']);
        @chmod("$dir/$kid.pem", 0600);
        @chmod("$dir/$kid.pub.pem", 0644);
        return $kid;
    }

    /** Ротация из админки: новый активный, старый — retiring на 48 ч. */
    public static function rotate(): string
    {
        $old = self::activeKid();
        $new = self::generate();
        $registry = Setting::getJson('keys.registry');
        $registry[$new] = ['created' => now(), 'retired_at' => null];
        if (isset($registry[$old])) {
            $registry[$old]['retired_at'] = now();
        } else {
            $registry[$old] = ['created' => now(), 'retired_at' => now()];
        }
        Setting::set('keys.registry', $registry);
        Setting::set('keys.active_kid', $new);
        return $new;
    }

    /** kid'ы для JWKS: активный + retiring младше 48 ч. Протухшие — в архив. */
    public static function publishedKids(): array
    {
        $active = self::activeKid();
        $kids = [$active];
        $registry = Setting::getJson('keys.registry');
        $changed = false;
        foreach ($registry as $kid => $info) {
            if ($kid === $active) continue;
            $retired = $info['retired_at'] ?? null;
            if ($retired === null) continue;
            if (strtotime($retired . ' UTC') + 48 * 3600 > time()) {
                if (is_file(self::dir() . "/$kid.pub.pem")) $kids[] = $kid;
            } else {
                self::archive($kid);
                unset($registry[$kid]);
                $changed = true;
            }
        }
        if ($changed) Setting::set('keys.registry', $registry);
        return $kids;
    }

    private static function archive(string $kid): void
    {
        $dir = self::dir();
        $arch = "$dir/archive";
        if (!is_dir($arch)) @mkdir($arch, 0700, true);
        foreach (["$kid.pem", "$kid.pub.pem"] as $f) {
            if (is_file("$dir/$f")) @rename("$dir/$f", "$arch/$f");
        }
    }

    public static function privatePem(string $kid): string
    {
        if (!preg_match('~^k\d+$~', $kid)) throw new \RuntimeException('Некорректный kid');
        $file = self::dir() . "/$kid.pem";
        if (!is_file($file)) throw new \RuntimeException("Приватный ключ $kid не найден");
        return (string)file_get_contents($file);
    }

    public static function publicPem(string $kid): ?string
    {
        if (!preg_match('~^k\d+$~', $kid)) return null;
        $file = self::dir() . "/$kid.pub.pem";
        if (!is_file($file)) {
            $file = self::dir() . "/archive/$kid.pub.pem"; // хвосты старых токенов
            if (!is_file($file)) return null;
        }
        return (string)file_get_contents($file);
    }

    /** JWKS-документ: {keys: [{kty,use,alg,kid,n,e}]}. */
    public static function jwks(): array
    {
        $keys = [];
        foreach (self::publishedKids() as $kid) {
            $pem = self::publicPem($kid);
            if ($pem === null) continue;
            $res = openssl_pkey_get_public($pem);
            if ($res === false) continue;
            $det = openssl_pkey_get_details($res);
            $keys[] = [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $kid,
                'n' => Crypto::b64url($det['rsa']['n']),
                'e' => Crypto::b64url($det['rsa']['e']),
            ];
        }
        return ['keys' => $keys];
    }

    /** Возраст активного ключа в днях (для health: > 180 — пора ротировать). */
    public static function activeAgeDays(): int
    {
        $kid = self::activeKid();
        $ts = (int)substr($kid, 1);
        return (int)floor((time() - $ts) / 86400);
    }
}
