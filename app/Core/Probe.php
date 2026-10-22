<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Client;

/**
 * Прозвон: работает ли то, чем пользуются, — а не «настроено ли» (v4.32).
 *
 * Проверки здоровья до сих пор отвечали на вопрос «в порядке ли хозяйство»:
 * жива база, есть ключи, пишется storage. Это не то же самое, что «работает
 * ли вход»: класс соцвхода может не доехать при заливке, `.well-known` —
 * попасть под правило веб-сервера, портал — молча не отвечать на события.
 * Каждый такой случай уже был, и ни один не виден по «настройкам».
 *
 * Здесь дёргаются настоящие адреса и смотрится настоящий ответ. Дорого — да,
 * поэтому вручную и по расписанию, а не на каждый показ страницы.
 */
final class Probe
{
    /** Секунд на попытку: прозвон не должен подвешивать экран. */
    private const TIMEOUT = 5;

    /**
     * Один прозвон.
     *
     * $judge получает (код, тело, дошло ли до приложения) и возвращает
     * [состояние, пояснение]. Третий довод объявлять необязательно: судьям,
     * которым он не нужен, PHP лишний довод просто не покажет.
     *
     * Открыт наружу, чтобы проверяемым был и сам прозвон: без этого «дошло ли
     * до приложения» пришлось бы принимать на веру, а именно на этом различии
     * держится разбор истории с discovery.
     *
     * @return array{name:string, status:string, note:string, ms:int, code:int}
     */
    public static function hit(string $name, string $url, callable $judge, bool $isSelf = false): array
    {
        $t = microtime(true);
        $head = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => 'SSO/' . SSO_VERSION . ' (self probe)',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$head): int {
                $head .= $line;
                return strlen($line);
            },
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t) * 1000);

        if ($code === 0) {
            /* Запрос к САМОМУ СЕБЕ на сервере с одним рабочим процессом ждёт
               освобождения того процесса, который его и делает, — то есть не
               дождётся никогда. Объявлять это поломкой нельзя: страница
               показала бы «всё сломано» там, где всё работает. Для чужого
               адреса молчание — настоящий отказ. */
            return ['name' => $name, 'ms' => $ms, 'code' => 0,
                    'status' => $isSelf ? 'warn' : 'fail',
                    'note' => $isSelf
                        ? t('проверить не вышло — возможно, сервер обслуживает один процесс. Откройте адрес в браузере.')
                        : t('не отвечает') . ($err !== '' ? ' (' . $err . ')' : '')];
        }
        /* Третьим доводом судье — дошёл ли запрос до приложения, четвёртым —
           чем представился сервер. Судьи, которым это неинтересно, объявляют
           два параметра и лишние просто не видят. */
        $server = preg_match('~^server:\s*(.+)$~mi', $head, $m) ? trim($m[1]) : '';
        /* Метка приложения — X-SSO-App с 4.89; прежняя X-TSOY-ID тоже засчитывается. */
        $app = (bool)preg_match('~^(X-SSO-App|X-TSOY-ID):~mi', $head);
        [$status, $note] = $judge($code, $body, $app, $server);
        return ['name' => $name, 'status' => $status, 'note' => $note, 'ms' => $ms, 'code' => $code];
    }

    /**
     * Свои адреса: то, за чем к нам ходят порталы и браузеры.
     *
     * @return list<array>
     */
    public static function self(): array
    {
        $base = rtrim(base_url(), '/');
        $out = [];

        $out[] = self::hit(t('Ключи подписи (jwks.json)'), $base . '/jwks.json',
            static function (int $code, string $body): array {
                if ($code !== 200) return ['fail', 'HTTP ' . $code];
                $j = json_decode($body, true);
                $n = is_array($j) ? count($j['keys'] ?? []) : 0;
                return $n > 0 ? ['ok', t('ключей: :n', ['n' => $n])]
                              : ['fail', t('ответ без ключей — порталы не проверят подпись')];
            }, true);

        /* Discovery живёт по пути, начинающемуся с точки, и потому регулярно
           попадает под правила вида «резать всё скрытое». Снаружи это выглядит
           как «SSO не работает», хотя маршрут на месте. */
        $out[] = self::hit(t('Discovery (.well-known)'), $base . '/.well-known/openid-configuration',
            static function (int $code, string $body, bool $ours, string $server = ''): array {
                if ($code === 200 && str_contains($body, 'authorization_endpoint')) return ['ok', t('отдаётся')];
                if (($code === 403 || $code === 404) && !$ours && stripos($server, 'nginx') !== false) {
                    /* На nginx причина почти всегда одна и та же, и она не про
                       скрытые файлы: весь префикс /.well-known/ перехвачен
                       блоком для выпуска сертификата, и запрос уходит в чужой
                       каталог вместо приложения. Совет про AllowOverride тут
                       бесполезен — nginx .htaccess не читает вовсе. Замерено
                       на живой установке: /.htaccess отдавал 403, а
                       /.well-known/* — 404 самого nginx, то есть работали два
                       разных правила. */
                    return ['warn', t('HTTP :code, и отвечал :server, а не приложение: путь /.well-known/ перехвачен конфигом — обычно это блок для выпуска сертификата. Оставьте ему только /.well-known/acme-challenge/, остальное отдайте приложению.', ['code' => $code, 'server' => $server])];
                }
                if (($code === 403 || $code === 404) && !$ours) {
                    /* Ответ без нашей подписи — до PHP запрос не дошёл. Правило
                       вида «RedirectMatch 404 /\..*$» рубит .well-known заодно
                       со скрытыми файлами, и починка тут не в коде.
                       Состояние «предупреждение», а не «отказ»: наш мост берёт
                       адреса из issuer напрямую и discovery не спрашивает —
                       подключённые порталы работают. Ломается подключение
                       чужих клиентов, которые ищут адреса сами, как велит
                       стандарт. Раньше здесь стояло «порталы не найдут ни один
                       адрес входа» — это пугало сверх меры. */
                    return ['warn', t('HTTP :code, и отвечало не приложение: веб-сервер не пропускает пути, начинающиеся с точки. Наш мост адреса знает и так, но сторонний клиент по стандарту их не найдёт.', ['code' => $code])];
                }
                if ($code === 404) return ['fail', t('HTTP 404 от приложения — маршрут не найден')];
                return ['fail', 'HTTP ' . $code];
            }, true);

        $out[] = self::hit(t('Экран входа'), $base . '/login',
            static fn(int $code): array => $code === 200
                ? ['ok', t('открывается')]
                : ['fail', t('HTTP :code — войти нельзя', ['code' => $code])], true);

        return $out;
    }

    /**
     * Порталы: их манифест и приёмник событий.
     *
     * Проверяем только то, что портал сам про себя объявил. Ходить по чужим
     * адресам, о которых нас не просили, мы не вправе.
     *
     * @return list<array>
     */
    public static function portals(int $limit = 10): array
    {
        $out = [];
        foreach (array_slice(Client::all(true), 0, $limit) as $c) {
            $base = rtrim((string)$c['base_url'], '/');
            if ($base === '') continue;

            $out[] = self::hit(t('Манифест :name', ['name' => $c['name']]),
                $base . '/.well-known/sso-portal.json',
                static function (int $code, string $body, bool $ours = false, string $server = ''): array {
                    if ($code === 200 && is_array(json_decode($body, true))) return ['ok', t('отдаётся')];
                    /* Причину называет тот же разбор, что и карточка портала:
                       один ответ на вопрос «почему манифеста нет», а не два. */
                    return ['warn', PortalManifest::diagnose($code, $server !== '' ? 'Server: ' . $server : '', $body)];
                });

            if (!empty($c['backchannel_uri'])) {
                /* Приёмник событий проверяем ЗАПРОСОМ БЕЗ ТОКЕНА: он обязан
                   отказать. Слать настоящее событие ради прозвона нельзя — оно
                   гасит живые сессии; для этого есть отдельная кнопка. */
                $out[] = self::hit(t('Приёмник событий :name', ['name' => $c['name']]),
                    (string)$c['backchannel_uri'],
                    static function (int $code): array {
                        if ($code >= 200 && $code < 500) return ['ok', t('отвечает (HTTP :code)', ['code' => $code])];
                        return ['fail', t('HTTP :code — выход и блокировка до портала не дойдут', ['code' => $code])];
                    });
            }
        }
        return $out;
    }

    /** Итог одним словом: худшее из состояний. */
    public static function worst(array $results): string
    {
        $worst = 'ok';
        foreach ($results as $r) {
            if (($r['status'] ?? '') === 'fail') return 'fail';
            if (($r['status'] ?? '') === 'warn') $worst = 'warn';
        }
        return $worst;
    }
}
