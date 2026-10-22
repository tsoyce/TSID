<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Цела ли поставка: всё ли доехало при заливке (v4.32).
 *
 * Заливка по FTP обрывается тихо. Часть файлов на месте, часть нет, установка
 * при этом выглядит рабочей — до первого обращения к тому, чего не хватает. На
 * боевой так пропал один класс соцвхода, и весь экран входа отдавал 500: список
 * провайдеров собирался безусловно.
 *
 * Само падение вылечено (провайдер без файла теперь пропускается), но вопрос
 * «а всё ли доехало» остаётся, и отвечать на него по одному классу — гадание.
 * Опись `dist-manifest.json` кладётся в архив сборщиком; здесь она сверяется с
 * тем, что лежит на диске.
 *
 * Размер, а не хэш: хэш ловил бы и правку файла на месте — а это законно, у
 * себя шаблон править никто не запрещал. Недоехавший файл — не законно.
 *
 * Разбор боевого случая заставил различать не два состояния, а три. Заливка
 * оборвалась на середине: часть файлов новая, часть осталась от прошлой версии.
 * Проверка «есть файл — и ладно» объявила такую установку целой, хотя половина
 * лечений в ней просто отсутствовала: код старый, а версия в подвале новая.
 *
 *   · нет файла        — красное: упадёт при первом обращении;
 *   · размер не тот    — жёлтое: либо файл старый (заливка оборвалась), либо
 *                        правлен на месте — законно, но знать об этом надо;
 *   · размер совпал    — зелёное.
 */
final class Delivery
{
    private const MANIFEST = '/dist-manifest.json';

    /** Есть ли опись. Установка из git её не имеет — и это не беда. */
    public static function hasManifest(): bool
    {
        return is_file(SSO_ROOT . self::MANIFEST);
    }

    /**
     * Сверка с описью.
     *
     * @return array{ok:bool, known:bool, version:?string, total:int,
     *               missing:list<string>, changed:list<string>}
     */
    public static function verify(): array
    {
        $empty = ['ok' => true, 'known' => false, 'version' => null,
                  'total' => 0, 'missing' => [], 'changed' => []];
        if (!self::hasManifest()) return $empty;

        $raw = @file_get_contents(SSO_ROOT . self::MANIFEST);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) return $empty;

        $missing = [];
        $changed = [];
        foreach ($data['files'] as $rel => $size) {
            $abs = SSO_ROOT . '/' . $rel;
            if (!is_file($abs)) { $missing[] = (string)$rel; continue; }
            $actual = (int)@filesize($abs);
            if ($actual === (int)$size) continue;
            /* Ноль байт называем отдельно: это не «правил у себя», а оборванная
               передача — соединение успело создать файл и не успело налить. */
            $changed[] = (string)$rel . ' (' . ($actual === 0
                ? t('0 байт — не долился')
                : t(':actual вместо :size', ['actual' => $actual, 'size' => (int)$size])) . ')';
        }
        sort($missing);
        sort($changed);

        return [
            /* Красным считается только отсутствие. Разошедшийся размер — повод
               посмотреть, а не объявлять установку сломанной: у того, кто правил
               шаблон под себя, всё работает, и пугать его нечем. */
            'ok' => $missing === [],
            'known' => true,
            'version' => isset($data['version']) ? (string)$data['version'] : null,
            'total' => count($data['files']),
            'missing' => $missing,
            'changed' => $changed,
        ];
    }

    /**
     * Коротко для полосы состояния: не хватает ли файлов.
     *
     * Спрашивается на КАЖДОЙ странице администратора, поэтому ответ кэшируется:
     * полная сверка — это под тысячу обращений к диску, и на общем хостинге они
     * не бесплатны. Кэш живёт десять минут и сбрасывается сам, когда меняется
     * опись, — то есть сразу после новой заливки.
     *
     * @return array{missing:int, first:string}|null
     */
    public static function gap(): ?array
    {
        if (!self::hasManifest()) return null;

        $stamp = (int)@filemtime(SSO_ROOT . self::MANIFEST);
        $cacheFile = SSO_ROOT . '/storage/cache/delivery-gap.json';
        $cached = is_file($cacheFile) ? json_decode((string)@file_get_contents($cacheFile), true) : null;
        if (is_array($cached) && ($cached['stamp'] ?? 0) === $stamp
            && time() - (int)($cached['at'] ?? 0) < 600) {
            return $cached['gap'] ?? null;
        }

        $v = self::verify();
        $gap = $v['missing'] === []
            ? null
            : ['missing' => count($v['missing']), 'first' => $v['missing'][0]];
        @file_put_contents($cacheFile,
            json_encode(['stamp' => $stamp, 'at' => time(), 'gap' => $gap]));
        return $gap;
    }

    /**
     * Не в недоехавшем ли файле дело.
     *
     * Разбор второго случая подряд. Сначала не доехал класс соцвхода и уронил
     * экран входа. Потом не доехал `app/Core/Incident.php` — и упала ровно та
     * страница, на которую страница 500 отправляет за подробностями. Круг
     * замкнулся: ошибка говорит «событие найдётся в журнале», журнал по этому
     * номеру не открывается, и узнать причину неоткуда.
     *
     * Ответ берётся из САМОГО СООБЩЕНИЯ, без описи: «Class "App\Core\Incident"
     * not found» — это готовый путь `app/Core/Incident.php`, и остаётся
     * спросить диск. Поэтому работает и на установке из git, и когда не доехала
     * сама опись. Опись добавляет лишь второе предложение — сколько ещё файлов
     * недостаёт, потому что недоехавший файл почти никогда не один.
     *
     * @return array{file:string, others:int}|null
     */
    public static function blame(\Throwable $e): ?array
    {
        $msg = $e->getMessage();
        $rel = null;

        if (preg_match('~(?:Class|Interface|Trait|Enum)\s+"([^"]+)"\s+not found~', $msg, $m)) {
            $cls = $m[1];
            /* Автозагрузчик кладёт App\Foo\Bar в app/Foo/Bar.php — обратное
               преобразование ровно такое же. */
            if (str_starts_with($cls, 'App\\')) {
                $rel = 'app/' . str_replace('\\', '/', substr($cls, 4)) . '.php';
            }
        } elseif (preg_match("~Failed opening required '([^']+)'~", $msg, $m)) {
            $abs = str_replace('\\', '/', $m[1]);
            $root = str_replace('\\', '/', SSO_ROOT) . '/';
            $rel = str_starts_with($abs, $root) ? substr($abs, strlen($root)) : $abs;
        } else {
            /* Общий случай: в сообщении назван путь к файлу .php, и файла нет.
               Так ловится «Шаблон не найден: templates/admin/orgs.php» и всё,
               что появится потом с той же бедой. Проверка одна — есть ли файл,
               — поэтому ложно сработать на существующем пути не может. */
            /* Класс широкий и с флагом «u»: в пути бывает кириллица, а `\w` без
               него её не берёт — путь тогда не находится вовсе. */
            if (preg_match_all('~[^\s"\'<>()]+\.php~u', $msg, $m)) {
                foreach ($m[0] as $cand) {
                    $cand = ltrim(str_replace('\\', '/', $cand), '/');
                    if ($cand !== '' && !is_file(SSO_ROOT . '/' . $cand)) { $rel = $cand; break; }
                }
            }
        }

        if ($rel === null || is_file(SSO_ROOT . '/' . $rel)) return null;

        $others = 0;
        $v = self::verify();
        if ($v['known']) {
            $others = count($v['missing']);
            if (in_array($rel, $v['missing'], true)) $others--;
        }
        return ['file' => $rel, 'others' => max(0, $others)];
    }
}
