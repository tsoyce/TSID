<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Чистка и картина занятого места (v4.0).
 *
 * Автоматика была и раньше: cron-задачи logs_rotate, exports_cleanup, expired и
 * сроки хранения в политиках. Не хватало двух вещей.
 *
 * Первая — возможность нажать кнопку. Всё упиралось в планировщик, а он после
 * установки настроен далеко не всегда: ровно эта грабля уже была с выгрузкой
 * данных в v3.5, где задание ждало cron, которого нет.
 *
 * Вторая — знать, что чистить. Решение «удалить логи» принималось вслепую:
 * может, логи занимают три мегабайта, а место съедают аватары удалённых
 * аккаунтов. Поэтому сначала измеряем, потом предлагаем.
 */
final class Housekeeping
{
    /**
     * Что сколько занимает.
     *
     * @return list<array{key: string, title: string, bytes: int, count: int, hint: string}>
     */
    public static function usage(): array
    {
        $out = [];

        $logs = self::dirSize(SSO_ROOT . '/storage/logs');
        $out[] = ['key' => 'logs', 'title' => t('Логи приложения'),
            'bytes' => $logs[0], 'count' => $logs[1],
            'hint' => t('Срок хранения — в политиках')];

        $exports = self::dirSize(SSO_ROOT . '/storage/exports');
        $out[] = ['key' => 'exports', 'title' => t('Выгрузки данных'),
            'bytes' => $exports[0], 'count' => $exports[1],
            'hint' => t('Живут сутки после сборки')];

        $avatars = self::dirSize(SSO_ROOT . '/storage/avatars');
        $out[] = ['key' => 'avatars', 'title' => t('Аватары и фотографии'),
            'bytes' => $avatars[0], 'count' => $avatars[1],
            'hint' => t('Удаляются вместе с аккаунтом')];

        /* Аудит — журнал безопасности, по умолчанию хранится вечно. Не чистим
           его молча, но показать объём обязаны: решение принимает владелец. */
        $out[] = ['key' => 'audit', 'title' => t('Журнал аудита'),
            'bytes' => 0, 'count' => self::rows('audit_log'),
            'hint' => t('По умолчанию хранится вечно')];
        $out[] = ['key' => 'mail', 'title' => t('Почтовая очередь'),
            'bytes' => 0, 'count' => self::rows('mail_queue'),
            'hint' => t('Отправленные убираются по сроку')];

        return $out;
    }

    /**
     * Убрать сирот: файлы без записей в базе.
     *
     * Появляются они не от небрежности, а от обрывов — упавший запрос между
     * записью файла и вставкой строки, прерванная анонимизация, ручное удаление
     * строки в базе. Раз в год такого набирается достаточно, чтобы заметить.
     *
     * @return array<string, int> что и сколько удалено
     */
    public static function purgeOrphans(): array
    {
        $removed = ['avatars' => 0, 'photos' => 0, 'exports' => 0];

        /* Каталоги аватаров именуются по public_id: каталог, которому не
           соответствует живой аккаунт, — след удалённого. */
        $known = array_flip(array_column(DB::all('SELECT public_id FROM {users}'), 'public_id'));
        foreach ((array)glob(SSO_ROOT . '/storage/avatars/*', GLOB_ONLYDIR) as $dir) {
            $name = basename((string)$dir);
            /* branding и clients — не аккаунты, их каталоги трогать нельзя */
            if (in_array($name, ['branding', 'clients'], true)) continue;
            if (isset($known[$name])) continue;
            foreach ((array)glob($dir . '/photos/*') as $f) { @unlink((string)$f); $removed['photos']++; }
            @rmdir($dir . '/photos');
            foreach ((array)glob($dir . '/*') as $f) { @unlink((string)$f); $removed['avatars']++; }
            @rmdir($dir);
        }

        /* Фотографии, чьи строки исчезли из галереи */
        if (Migrations::ready('user_photos')) {
            $paths = array_flip(array_column(DB::all('SELECT path FROM {user_photos}'), 'path'));
            foreach ((array)glob(SSO_ROOT . '/storage/avatars/*/photos/*.webp') as $f) {
                $rel = str_replace('\\', '/', substr((string)$f,
                    strlen(SSO_ROOT . '/storage/avatars/')));
                if (!isset($paths[$rel])) { @unlink((string)$f); $removed['photos']++; }
            }
        }

        /* Файлы выгрузок без живого задания */
        if (Migrations::ready('export_jobs')) {
            $files = array_flip(array_filter(array_column(
                DB::all('SELECT file_path FROM {export_jobs} WHERE file_path IS NOT NULL'), 'file_path')));
            foreach ((array)glob(SSO_ROOT . '/storage/exports/*') as $f) {
                $rel = 'exports/' . basename((string)$f);
                if (!isset($files[$rel])) { @unlink((string)$f); $removed['exports']++; }
            }
        }

        Audit::log('housekeeping.orphans', ['meta' => $removed]);
        return $removed;
    }

    /**
     * Убрать журналы приложения старше N дней.
     *
     * Хранить их вечно незачем: файл за каждый день, и разбор аварии дальше
     * недели не уходит. Сегодняшний день не трогаем никогда — в него пишут
     * прямо сейчас.
     *
     * @return array{files:int, bytes:int}
     */
    public static function purgeLogs(int $keepDays = 14): array
    {
        $keepDays = max(1, $keepDays);
        $edge = time() - $keepDays * 86400;
        $today = 'app-' . gmdate('Y-m-d') . '.log';
        $out = ['files' => 0, 'bytes' => 0];
        foreach ((array)glob(SSO_ROOT . '/storage/logs/app-*.log') as $f) {
            $name = basename((string)$f);
            if ($name === $today) continue;
            /* День берём из ИМЕНИ, а не из времени файла: время правится
               копированием, переносом, распаковкой архива — а имя нет. */
            if (!preg_match('~^app-(\d{4}-\d{2}-\d{2})\.log$~', $name, $m)) continue;
            if (strtotime($m[1] . ' 23:59:59 UTC') > $edge) continue;
            $out['bytes'] += (int)@filesize((string)$f);
            if (@unlink((string)$f)) $out['files']++;
        }
        return $out;
    }

    /**
     * Убрать отправленные письма из очереди.
     *
     * Очередь — это очередь, а не архив: отправленное письмо в ней больше ни
     * для чего не нужно, а экран «Почта» превращается в бесконечную ленту.
     * Неотправленные и упавшие не трогаем: по ним видно, что чинить.
     */
    public static function purgeSentMail(int $keepDays = 7): int
    {
        if (!DB::tableExists('mail_queue')) return 0;
        $edge = now_plus(-$keepDays * 86400);
        $n = (int)DB::val('SELECT COUNT(*) FROM {mail_queue} WHERE status = ? AND sent_at IS NOT NULL AND sent_at < ?',
            ['sent', $edge]);
        if ($n > 0) {
            DB::run('DELETE FROM {mail_queue} WHERE status = ? AND sent_at IS NOT NULL AND sent_at < ?',
                ['sent', $edge]);
        }
        return $n;
    }

    /** @return array{0: int, 1: int} байты и число файлов */
    private static function dirSize(string $dir): array
    {
        if (!is_dir($dir)) return [0, 0];
        $bytes = 0; $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $bytes += (int)$f->getSize();
            $count++;
        }
        return [$bytes, $count];
    }

    private static function rows(string $table): int
    {
        if (!DB::tableExists($table)) return 0;
        try {
            return (int)DB::val('SELECT COUNT(*) FROM {' . $table . '}');
        } catch (\Throwable) {
            return 0;
        }
    }

    /** «12,4 МБ» — читаемый размер. */
    public static function human(int $bytes): string
    {
        if ($bytes <= 0) return '—';
        $units = [t('Б'), t('КБ'), t('МБ'), t('ГБ')];
        $i = 0;
        $v = (float)$bytes;
        while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
        return ($i === 0 ? (string)(int)$v : number_format($v, 1, ',', ' ')) . ' ' . $units[$i];
    }
}
