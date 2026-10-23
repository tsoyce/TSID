<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Log;
use App\Core\Migrations;

/**
 * Галерея фотографий аккаунта (v3.8).
 *
 * Соцпровайдеры отдают ссылку на фото при каждом входе. До v3.8 она оседала в
 * linked_identities.meta и не использовалась: данные собирались и
 * выбрасывались. Теперь фото копятся, а человек выбирает из них аватарку.
 *
 * Правило, ради которого всё это и написано так, а не иначе: **аватарка сама
 * не меняется**. Поставил человек своё фото — вход через Яндекс пополнит
 * галерею, но не подменит выбранное. Автоматически аватарка ставится ровно в
 * одном случае: её нет вообще.
 */
final class UserPhoto
{
    /** Хранимый размер: тот же, что у самого большого аватара. */
    private const SIDE = 512;

    /** Больше — не фотография профиля, а способ занять диск. */
    private const MAX_BYTES = 3 * 1024 * 1024;

    /**
     * Откуда разрешено скачивать — по провайдерам.
     *
     * Ссылку даёт ответ провайдера, то есть внешние данные, а скачивание по
     * произвольному адресу с сервера — это SSRF: чужой или подменённый ответ
     * заставил бы SSO ходить во внутреннюю сеть. Поэтому хост сверяется со
     * списком, редиректы не выполняются (иначе список обходится первым же
     * 302), и схема только https.
     *
     * @var array<string, list<string>>
     */
    private const HOSTS = [
        'telegram' => ['t.me', '.telesco.pe', '.cdn-telegram.org'],
        'vk'       => ['.userapi.com', '.vk.com', '.vk.me'],
        'yandex'   => ['avatars.yandex.net', 'avatars.mds.yandex.net'],
        'github'   => ['avatars.githubusercontent.com'],
    ];

    public static function ready(): bool
    {
        return Migrations::ready('user_photos');
    }

    /** @return list<array> фото аккаунта, новые сверху */
    public static function forUser(int $userId): array
    {
        if (!self::ready()) return [];
        return DB::all('SELECT * FROM {user_photos} WHERE user_id = ? ORDER BY id DESC', [$userId]);
    }

    /**
     * Забрать фото у провайдера, если такого ещё нет.
     *
     * Вызывается на входе, поэтому обязана быть быстрой и незаметной: любая
     * ошибка гасится, вход из-за фотографии не ломается и не задерживается.
     *
     * @return int|null id добавленного фото или null, если добавлять нечего
     */
    public static function fetchFromProvider(array $user, string $provider, ?string $url): ?int
    {
        if (!self::ready() || $url === null || $url === '') return null;
        if (!isset(self::HOSTS[$provider])) return null;

        /* Уже забирали эту самую ссылку — второй раз не ходим. Провайдер меняет
           её, когда человек меняет фото, и этого достаточно. */
        $ref = mb_substr($url, 0, 255);
        $exists = DB::val('SELECT id FROM {user_photos} WHERE user_id = ? AND source = ? AND source_ref = ?',
            [(int)$user['id'], $provider, $ref]);
        if ($exists !== null) return null;

        try {
            $bytes = self::download($url, self::HOSTS[$provider]);
            if ($bytes === null) return null;
            $id = self::store($user, $bytes, $provider, $ref);
            if ($id === null) return null;
            /* Аватарки нет вообще — ставим первое, что приехало. Это не
               подмена: заменять нечего. */
            if (empty($user['avatar_path'])) self::makeCurrent($user, $id);
            Audit::log('photo.added', ['actor' => (int)$user['id'], 'on' => (int)$user['id'],
                'meta' => ['source' => $provider]]);
            return $id;
        } catch (\Throwable $e) {
            /* Фотография — украшение. Вход из-за неё не падает. */
            Log::warning('photo.fetch_failed', ['provider' => $provider, 'why' => $e->getMessage()]);
            return null;
        }
    }

    /** Положить в галерею готовые байты изображения. @return int|null id */
    public static function store(array $user, string $bytes, string $source, ?string $ref): ?int
    {
        if (!self::ready()) return null;
        $img = \App\Core\Image::fromBytes($bytes);
        if ($img === false) return null;

        $w = imagesx($img); $h = imagesy($img);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIDE, self::SIDE);
        imagecopyresampled($dst, $img, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2),
            self::SIDE, self::SIDE, $side, $side);
        imagedestroy($img);

        $dir = self::dir($user);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        /* Шестнадцатеричное имя, а не base64url: раздача проверяет файл строгой
           маской ^[a-f0-9]{16}\.webp$, и «-» с «_» в неё не входят. */
        $name = bin2hex(random_bytes(8)) . '.webp';
        imagewebp($dst, $dir . '/' . $name, 85);
        imagedestroy($dst);

        return DB::insert('user_photos', [
            'user_id' => (int)$user['id'],
            'source' => $source,
            'source_ref' => $ref,
            'path' => $user['public_id'] . '/photos/' . $name,
            'created_at' => now(),
        ]);
    }

    /**
     * Сделать фото аватаркой: пересобрать 512/128/32 из файла галереи.
     * Пути аватара не меняются, поэтому всё, что их использует — от userinfo до
     * писем, — продолжает работать без единой правки.
     */
    public static function makeCurrent(array $user, int $photoId): bool
    {
        if (!self::ready()) return false;
        $photo = DB::row('SELECT * FROM {user_photos} WHERE id = ? AND user_id = ?',
            [$photoId, (int)$user['id']]);
        if (!$photo) return false;

        $src = SSO_ROOT . '/storage/avatars/' . $photo['path'];
        if (!is_file($src)) return false;
        $img = @imagecreatefromwebp($src);
        if ($img === false) return false;

        $dir = SSO_ROOT . '/storage/avatars/' . $user['public_id'];
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        foreach ([512, 128, 32] as $size) {
            $dstImg = imagecreatetruecolor($size, $size);
            imagecopyresampled($dstImg, $img, 0, 0, 0, 0, $size, $size, self::SIDE, self::SIDE);
            imagewebp($dstImg, "$dir/$size.webp", 85);
            imagedestroy($dstImg);
        }
        imagedestroy($img);

        User::update((int)$user['id'], [
            'avatar_path' => $user['public_id'] . '/512.webp',
            'avatar_photo_id' => $photoId,
        ]);
        return true;
    }

    /** Убрать фото из галереи вместе с файлом. */
    public static function remove(array $user, int $photoId): bool
    {
        if (!self::ready()) return false;
        $photo = DB::row('SELECT * FROM {user_photos} WHERE id = ? AND user_id = ?',
            [$photoId, (int)$user['id']]);
        if (!$photo) return false;

        @unlink(SSO_ROOT . '/storage/avatars/' . $photo['path']);
        DB::run('DELETE FROM {user_photos} WHERE id = ?', [$photoId]);

        /* Удалили то, что стояло аватаркой — аватарку тоже снимаем, иначе
           профиль показывал бы картинку, которой в галерее уже нет. */
        if ((int)($user['avatar_photo_id'] ?? 0) === $photoId) {
            foreach ([512, 128, 32] as $size) {
                @unlink(SSO_ROOT . '/storage/avatars/' . $user['public_id'] . "/$size.webp");
            }
            User::update((int)$user['id'], ['avatar_path' => null, 'avatar_photo_id' => null]);
        }
        return true;
    }

    /** Подпись источника для галереи. */
    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'telegram' => 'Telegram', 'vk' => 'VK', 'yandex' => t('Яндекс'), 'github' => 'GitHub',
            default => t('загружено'),
        };
    }

    private static function dir(array $user): string
    {
        return SSO_ROOT . '/storage/avatars/' . $user['public_id'] . '/photos';
    }

    /**
     * Скачать картинку строго с разрешённого хоста.
     * @param list<string> $allowed точные хосты или суффиксы вида «.userapi.com»
     */
    private static function download(string $url, array $allowed): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') return null;
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') return null;

        $ok = false;
        foreach ($allowed as $rule) {
            if ($rule[0] === '.' ? str_ends_with($host, $rule) : $host === $rule) { $ok = true; break; }
        }
        if (!$ok) {
            Log::warning('photo.host_rejected', ['host' => $host]);
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            /* Редиректы не выполняем: иначе разрешённый хост первым же 302
               отправил бы нас куда угодно, и список хостов ничего не значил. */
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            /* Вход не должен ждать чужой сервер: три секунды на всё. */
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            CURLOPT_USERAGENT => 'SSO/' . SSO_VERSION,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $code !== 200) return null;
        /* MAXFILESIZE верит заголовку Content-Length; без него сервер может
           прислать больше обещанного — проверяем по факту. */
        if (strlen($body) > self::MAX_BYTES) return null;
        return $body;
    }
}
