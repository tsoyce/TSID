<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Keys;
use App\Core\Setting;

/**
 * /metrics — формат Prometheus для внешнего мониторинга.
 * Защищён тем же токеном, что и /cron (метрики раскрывают размеры инсталляции).
 */
final class MetricsController
{
    public function metrics(): never
    {
        $expected = (string)config('cron.token', '');
        $token = (string)($_GET['token'] ?? '');
        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit("# доступ по токену: /metrics?token=…\n");
        }

        $now = now();
        $day = now_plus(-86400);
        $m = [];

        $m[] = ['sso_users_total', 'Пользователи по статусам', 'gauge', array_map(
            fn($r) => [['status' => $r['status']], (int)$r['n']],
            DB::all('SELECT status, COUNT(*) AS n FROM {users} GROUP BY status'))];

        $m[] = ['sso_sessions_active', 'Живые SSO-сессии', 'gauge', [[[],
            (int)DB::val('SELECT COUNT(*) FROM {sessions} WHERE revoked_at IS NULL AND expires_at > ?', [$now])]]];

        $m[] = ['sso_logins_24h', 'Входы за сутки', 'counter', [
            [['result' => 'success'], (int)DB::val(
                'SELECT COUNT(*) FROM {audit_log} WHERE ' . \App\Core\Stats::loginIn() . ' AND created_at >= ?',
                array_merge(\App\Core\Stats::LOGIN_EVENTS, [$day]))],
            [['result' => 'fail'], (int)DB::val(
                'SELECT COUNT(*) FROM {audit_log} WHERE event = ? AND created_at >= ?', ['login.fail', $day])],
        ]];

        $m[] = ['sso_clients_total', 'Подключённые порталы', 'gauge', [
            [['active' => 'true'], (int)DB::val('SELECT COUNT(*) FROM {clients} WHERE is_active = 1')],
            [['active' => 'false'], (int)DB::val('SELECT COUNT(*) FROM {clients} WHERE is_active = 0')],
        ]];

        $m[] = ['sso_queue_size', 'Очереди: письма, события порталов, вебхуки', 'gauge', [
            [['queue' => 'mail'], (int)DB::val('SELECT COUNT(*) FROM {mail_queue} WHERE status = ?', ['pending'])],
            [['queue' => 'mail_failed'], (int)DB::val('SELECT COUNT(*) FROM {mail_queue} WHERE status = ?', ['failed'])],
            [['queue' => 'client_events'], (int)DB::val('SELECT COUNT(*) FROM {client_events} WHERE status = ?', ['pending'])],
            [['queue' => 'webhooks'], (int)DB::val('SELECT COUNT(*) FROM {webhook_deliveries} WHERE status = ?', ['pending'])],
        ]];

        $m[] = ['sso_2fa_users', 'Пользователи со вторым фактором', 'gauge', [
            [['method' => 'totp'], (int)DB::val('SELECT COUNT(*) FROM {users} WHERE totp_enabled_at IS NOT NULL')],
            [['method' => 'passkey'], (int)DB::val('SELECT COUNT(DISTINCT user_id) FROM {passkeys}')],
        ]];

        $hb = (string)Setting::get('cron.heartbeat', '');
        $m[] = ['sso_cron_age_seconds', 'Секунд с последнего запуска cron', 'gauge',
            [[[], $hb === '' ? -1 : time() - strtotime($hb . ' UTC')]]];

        $m[] = ['sso_signing_key_age_days', 'Возраст активного ключа подписи', 'gauge', [[[],
            (function () { try { return Keys::activeAgeDays(); } catch (\Throwable) { return -1; } })()]]];

        $m[] = ['sso_up', 'Инсталляция отвечает', 'gauge', [[[], 1]]];

        /* Имена sso_* с 4.89. Прежние tsoyid_* отдаются рядом теми же числами,
           чтобы графики и тревоги, настроенные на них, не ослепли в день
           обновления; уйдут в 5.0. */
        foreach ($m as [$name, $help, $type, $samples]) {
            $m[] = ['tsoyid_' . substr($name, 4), 'Прежнее имя ' . $name . ' (до 5.0)', $type, $samples];
        }

        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        header('Cache-Control: no-store');
        echo $this->render($m);
        exit;
    }

    /** @param array<int, array{0:string,1:string,2:string,3:array}> $metrics */
    private function render(array $metrics): string
    {
        $out = '';
        foreach ($metrics as [$name, $help, $type, $samples]) {
            $out .= "# HELP $name $help\n# TYPE $name $type\n";
            foreach ($samples as [$labels, $value]) {
                $label = '';
                if ($labels !== []) {
                    $pairs = [];
                    foreach ($labels as $k => $v) $pairs[] = $k . '="' . str_replace('"', '', (string)$v) . '"';
                    $label = '{' . implode(',', $pairs) . '}';
                }
                $out .= $name . $label . ' ' . $value . "\n";
            }
        }
        return $out;
    }
}
