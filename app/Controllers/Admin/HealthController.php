<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\DB;
use App\Core\Keys;
use App\Core\Setting;
use App\Core\View;

/** /admin/health (§11.3): БД, storage, ключи, SMTP, cron, PHP, гигиена. */
final class HealthController extends AdminBase
{
    /**
     * Раздел «Здоровья» в его оболочке.
     *
     * Здоровье — не объект, но разделов у него четыре, и вопрос у каждого свой.
     * Механизм тот же, что у настроек и объектов: у раздела свой адрес.
     */
    private function page(string $section, array $data, string $headActions = ''): never
    {
        object_page(\App\Core\HealthMap::class, ['id' => 0], $section,
            'admin/health/' . $section, $data, $headActions);
    }

    public function index(): never
    {
        $this->requireGlobalAdmin();
        $this->page('overview', [
            'pageTitle' => t('Здоровье'),
            'checks' => self::checks(),
            'apiToken' => (string)\App\Core\Setting::get('health.api_token', ''),
        ], '<a class="btn btn-secondary" href="/admin/health">' . esc(t('Обновить')) . '</a>');
    }

    /** Выдать (или перевыпустить) токен опроса. Только владелец. */
    public function token(): never
    {
        $this->requireSuperadmin();
        $token = \App\Core\Crypto::randomToken(24);
        \App\Core\Setting::set('health.api_token', $token);
        \App\Core\Setting::flush();
        \App\Core\Audit::log('admin.health.token', ['meta' => ['rotated' => true]]);
        \App\Core\Flash::success(t('Токен выдан. Прежний, если был, перестал работать.'));
        redirect('/admin/health');
    }

    /** Что ломается и как часто. */
    public function errors(): never
    {
        $this->requireGlobalAdmin();
        $this->requirePerm('logs.read');
        $this->page('errors', [
            'pageTitle' => t('Ошибки — здоровье'),
            'digest' => \App\Core\ErrorDigest::top(),
        ]);
    }

    /** Всё ли доехало при заливке. */
    public function delivery(): never
    {
        $this->requireGlobalAdmin();
        $this->page('delivery', [
            'pageTitle' => t('Поставка — здоровье'),
            'delivery' => \App\Core\Delivery::verify(),
        ]);
    }

    /**
     * Прозвон. Запускается кнопкой, а не при показе: это настоящие запросы
     * наружу, и делать их на каждое открытие страницы значит стучаться к
     * чужим порталам всякий раз, когда кто-то заглянул в раздел.
     */
    public function probe(): never
    {
        $this->requireGlobalAdmin();
        $run = ($_GET['run'] ?? '') === '1';
        $this->page('probe', [
            'pageTitle' => t('Прозвон — здоровье'),
            'run' => $run,
            'self' => $run ? \App\Core\Probe::self() : [],
            'portals' => $run ? \App\Core\Probe::portals() : [],
        ], $run ? '<a class="btn btn-secondary" href="/admin/health/probe?run=1">'
            . esc(t('Прозвонить ещё раз')) . '</a>' : '');
    }

    /**
     * GET /health.json — состояние установки машиной, а не глазами.
     *
     * Ради одного: чтобы «что у вас не работает» можно было спросить, а не
     * пересказывать. Скриншот экрана здоровья не показывает ни версии, ни
     * непринятых миграций, ни того, что половина файлов не доехала, — а всё
     * это ровно те причины, по которым установка ведёт себя странно.
     *
     * Токен обязателен и выдаётся владельцем: наружу уходит устройство
     * установки — версия, состав, чего не хватает. Для публичного «жив ли
     * сервер» есть /status, и он без токена.
     *
     * Только чтение. Секретов в ответе нет: ни ключей, ни адресов почты, ни
     * имён людей — состояние, а не содержимое.
     */
    public function api(): never
    {
        header('Cache-Control: no-store');
        $token = (string)\App\Core\Setting::get('health.api_token', '');
        $given = (string)($_GET['token'] ?? '');
        if ($given === '' && preg_match('~^Bearer\s+(.+)$~i',
                (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
            $given = trim($m[1]);
        }
        /* hash_equals, а не ===: обычное сравнение выходит на первом же
           несовпавшем байте, и токен подбирается посимвольно по времени. */
        if ($token === '' || $given === '' || !hash_equals($token, $given)) {
            json_response(['error' => 'unauthorized'], 401);
        }

        $checks = self::checks();
        $delivery = \App\Core\Delivery::verify();
        $digest = \App\Core\ErrorDigest::top(10);

        $worst = 'ok';
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') { $worst = 'fail'; break; }
            if ($c['status'] === 'warn') $worst = 'warn';
        }
        if (!$delivery['ok']) $worst = 'fail';

        $body = [
            'status' => $worst,
            'time' => gmdate('c'),
            'version' => SSO_VERSION,
            'php' => PHP_VERSION,
            'checks' => $checks,
            'delivery' => $delivery,
            'errors' => [
                'day' => $digest['day'],
                'week_count' => array_sum(array_column($digest['week'], 'count')),
            ],
        ];

        /* Событие по номеру — по отдельной просьбе.
           Сводка отвечает «что ломается и как часто», но чинить по названию
           ошибки нельзя: нужен файл и строка. Без этого разбор упирается в
           пересылку кусков журнала руками, а половина причин видна только в
           трассировке. Номер берётся из сводки выше — там он у каждой строки. */
        $inc = trim((string)($_GET['incident'] ?? ''));
        if ($inc !== '') {
            if (!\App\Core\Incident::valid($inc)) {
                json_response(['error' => 'bad_incident'], 400);
            }
            $body['incident'] = ['id' => $inc, 'events' => array_map(
                static fn(array $h): array => [
                    'file' => $h['file'], 'line' => $h['line'],
                    'entry' => \App\Core\ErrorDigest::sanitize($h['entry']),
                ],
                \App\Core\Incident::find($inc)
            )];
        }

        /* Прозвон — по отдельной просьбе: он ходит наружу, к чужим порталам, и
           делать это на каждый опрос значит стучаться к ним с частотой
           мониторинга. */
        if (($_GET['probe'] ?? '') === '1') {
            $body['probe'] = ['self' => \App\Core\Probe::self(), 'portals' => \App\Core\Probe::portals()];
            if (\App\Core\Probe::worst($body['probe']['self']) === 'fail') $body['status'] = 'fail';
        }

        json_response($body);
    }

    /** @return array<int, array{label:string, status:string, note:string}> */
    public static function checks(bool $quick = false): array
    {
        $checks = [];
        $add = function (string $label, string $status, string $note = '') use (&$checks) {
            $checks[] = ['label' => $label, 'status' => $status, 'note' => $note];
        };

        // БД: ping + latency + миграции
        try {
            $t = microtime(true);
            DB::val('SELECT 1');
            $ms = (int)round((microtime(true) - $t) * 1000);
            $applied = array_column(DB::all('SELECT version FROM {migrations}'), 'version');
            $files = array_map(fn($f) => basename($f, '.sql'), (array)glob(SSO_ROOT . '/migrations/*.sql'));
            $missing = array_diff($files, $applied);
            if ($missing !== []) {
                $add(t('База данных'), 'warn', t('Не применены миграции: :list — запустите в /admin/tools.', ['list' => implode(', ', $missing)]));
            } else {
                $add(t('База данных'), $ms < 50 ? 'ok' : 'warn', "ping {$ms} ms" . ($ms >= 50 ? t(' — медленно') : ''));
            }
        } catch (\Throwable $e) {
            $add(t('База данных'), 'fail', $e->getMessage());
        }

        // Storage
        $free = @disk_free_space(SSO_ROOT . '/storage');
        $writable = is_writable(SSO_ROOT . '/storage') && is_writable(SSO_ROOT . '/storage/logs');
        if (!$writable) $add('Storage', 'fail', t('storage/ не доступен на запись'));
        elseif ($free !== false && $free < 500 * 1024 * 1024) $add('Storage', 'warn', t('Свободно меньше 500 МБ: :n МБ', ['n' => round($free / 1048576)]));
        else $add('Storage', 'ok', $free !== false ? t('свободно :n ГБ', ['n' => round($free / 1073741824, 1)]) : '');

        // Ключи
        try {
            $kid = Keys::activeKid();
            Keys::privatePem($kid);
            $age = Keys::activeAgeDays();
            $add(t('Ключи подписи'), $age < 180 ? 'ok' : 'warn',
                t('kid=:kid, возраст :age дн.', ['kid' => $kid, 'age' => $age]) . ($age >= 180 ? t(' — пора ротировать (§3.4)') : ''));
        } catch (\Throwable $e) {
            $add(t('Ключи подписи'), 'fail', $e->getMessage());
        }

        // SMTP (кэш 15 мин, чтобы не долбить)
        if (!$quick) {
            $cached = Setting::get('health.smtp');
            $cachedAt = Setting::get('health.smtp_at', '');
            if ($cachedAt !== '' && strtotime($cachedAt . ' UTC') > time() - 900 && $cached !== null) {
                [$s, $n] = explode('|', (string)$cached, 2) + ['warn', ''];
                $add('SMTP', $s, $n . t(' (кэш)'));
            } else {
                [$s, $n] = self::smtpProbe();
                Setting::set('health.smtp', "$s|$n");
                Setting::set('health.smtp_at', now());
                $add('SMTP', $s, $n);
            }
        }

        // Cron heartbeat
        $hb = (string)Setting::get('cron.heartbeat', '');
        if ($hb === '') $add('Cron', 'fail', t('Heartbeat ещё не появлялся — планировщик не настроен (Приложение D).'));
        elseif (strtotime($hb . ' UTC') < time() - 600) $add('Cron', 'warn', t('Heartbeat старше 10 минут: :when', ['when' => fmt_ago($hb)]));
        else $add('Cron', 'ok', t('последний :when', ['when' => fmt_ago($hb)]));

        // PHP
        $missing = array_filter(['pdo_mysql', 'openssl', 'mbstring', 'curl', 'gd'],
            fn($e) => !extension_loaded($e) && !($e === 'pdo_mysql' && DB::driver() === 'sqlite'));
        $add('PHP ' . PHP_VERSION, $missing === [] ? 'ok' : 'fail',
            $missing === [] ? (extension_loaded('sodium') ? t('все расширения на месте') : t('без sodium (шифрование через openssl)'))
                : t('нет расширений: :list', ['list' => implode(', ', $missing)]));

        // Гигиена
        $issues = [];
        if (is_dir(SSO_ROOT . '/install')) $issues[] = t('каталог /install ещё на сервере — удалите его');
        if (ini_get('display_errors') === '1' && config('app.env') !== 'debug') $issues[] = t('display_errors включён');
        if (!is_https() && config('app.env') === 'production' && PHP_SAPI !== 'cli') $issues[] = t('запрос пришёл без HTTPS');
        $add(t('Гигиена'), $issues === [] ? 'ok' : 'warn', implode('; ', $issues));

        return $checks;
    }

    private static function smtpProbe(): array
    {
        $driver = (string)setting('mail.driver', config('mail.driver', 'smtp'));
        if ($driver === 'log') return ['ok', t('транспорт: файл-лог (разработка)')];
        $host = (string)setting('mail.host', config('mail.host', ''));
        $port = (int)setting('mail.port', config('mail.port', 587));
        if ($host === '') return ['warn', t('SMTP не настроен — письма копятся в очереди')];
        $fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
        if ($fp === false) return ['fail', "connect $host:$port — $errstr"];
        fclose($fp);
        return ['ok', "connect $host:$port"];
    }
}
