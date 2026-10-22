<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\DB;
use App\Core\View;

/** Админ-дашборд (§5.2): KPI, график регистраций (SVG), лента аудита, здоровье. */
final class DashboardController extends AdminBase
{
    /**
     * POST /admin/context — переключить область работы (v4.26).
     *
     * Возвращаем туда, откуда пришли: контекст меняет рамку, а не место, и
     * выбрасывать человека на дашборд с той страницы, где он работал, значило
     * бы наказывать за переключение.
     */
    public function context(): never
    {
        /* Только свой адрес: заголовок приходит от браузера, и уводить по нему
           на чужой сайт нельзя (открытый редирект). */
        $back = referer_path('/admin');

        $scope = \App\Core\Scope::parse((string)($_POST['scope'] ?? ''));
        if (!\App\Core\AdminContext::set($scope)) {
            \App\Core\Flash::error(t('Эта область вам не видна.'));
            redirect($back);
        }
        \App\Core\Flash::info(t('Область работы: :name', ['name' => \App\Core\AdminContext::label($scope)]));
        redirect($back);
    }

    public function index(): never
    {
        /* Делегированный админ без глобальных прав — сразу в карточку своего
           портала: дашборд по всей установке ему всё равно нечем наполнить.
           Спрашиваем именно про полномочия, а не про наличие сужения: сужение
           теперь бывает и у глобального админа, выбравшего организацию, и по
           прежнему условию его выбрасывало бы из дашборда в карточку. */
        $scope = $this->scopeClientIds();
        if (!Auth::isAdmin() && $scope !== null && $scope !== []) {
            redirect('/admin/clients/' . $scope[0]);
        }

        $today = gmdate('Y-m-d 00:00:00');
        $kpi = [
            'users_total' => (int)DB::val('SELECT COUNT(*) FROM {users} WHERE status != ?', ['deleted']),
            'users_new_7d' => (int)DB::val('SELECT COUNT(*) FROM {users} WHERE created_at >= ?', [now_plus(-7 * 86400)]),
            'logins_today' => (int)DB::val('SELECT COUNT(*) FROM {audit_log} WHERE event IN (?, ?) AND created_at >= ?',
                ['login.success', 'login.magic_link', $today]),
            'fails_today' => (int)DB::val('SELECT COUNT(*) FROM {audit_log} WHERE event = ? AND created_at >= ?',
                ['login.fail', $today]),
            'sessions_active' => (int)DB::val('SELECT COUNT(*) FROM {sessions} WHERE revoked_at IS NULL AND expires_at > ?', [now()]),
            'clients_total' => (int)DB::val('SELECT COUNT(*) FROM {clients} WHERE is_active = 1'),
        ];

        // Регистрации за 30 дней — для SVG-графика без чарт-библиотек (§5.2)
        $regRows = DB::all(
            'SELECT SUBSTR(created_at, 1, 10) AS d, COUNT(*) AS n FROM {users} WHERE created_at >= ? GROUP BY SUBSTR(created_at, 1, 10)',
            [now_plus(-30 * 86400)]);
        $byDay = array_column($regRows, 'n', 'd');
        $registrations = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $registrations[$d] = (int)($byDay[$d] ?? 0);
        }

        /* Кривые за 14 дней — у каждой плитки своя (v4.84): под числом стояла
           одна кривая неудачных входов, три соседние плитки кончались пустым
           полем. Ряд — по дням, пустой день — ноль, а не пропуск. */
        $perDay = static function (string $table, string $where, array $params): array {
            $rows = DB::all('SELECT SUBSTR(created_at, 1, 10) AS d, COUNT(*) AS n FROM {' . $table . '} WHERE ' . $where
                . ' AND created_at >= ? GROUP BY SUBSTR(created_at, 1, 10)', array_merge($params, [now_plus(-14 * 86400)]));
            $byDay = array_column($rows, 'n', 'd');
            $out = [];
            for ($i = 13; $i >= 0; $i--) $out[] = (int)($byDay[gmdate('Y-m-d', time() - $i * 86400)] ?? 0);
            return $out;
        };
        $failSpark = $perDay('audit_log', 'event = ?', ['login.fail']);
        $loginSpark = $perDay('audit_log', 'event IN (?, ?)', ['login.success', 'login.magic_link']);
        $sessionSpark = $perDay('sessions', '1 = 1', []);
        $regSpark = array_slice(array_values($registrations), -14);

        $audit = DB::all(
            'SELECT a.*, u.email AS actor_email, o.email AS on_email, c.name AS client_name
             FROM {audit_log} a
             LEFT JOIN {users} u ON u.id = a.actor_user_id
             LEFT JOIN {users} o ON o.id = a.on_user_id
             LEFT JOIN {clients} c ON c.id = a.client_id
             ORDER BY a.id DESC LIMIT 10');

        View::render('admin/dashboard', [
            'pageTitle' => t('Дашборд'),
            'kpi' => $kpi,
            'registrations' => $registrations,
            'failSpark' => $failSpark,
            'loginSpark' => $loginSpark,
            'sessionSpark' => $sessionSpark,
            'regSpark' => $regSpark,
            'audit' => $audit,
            'health' => HealthController::checks(true),
            'onboarding' => self::onboarding(),
        ]);
    }

    /**
     * Чеклист первых шагов после установки. Ничего не навязывает: каждый пункт —
     * ссылка на нужный экран, карточка прячется кнопкой или сама, когда всё сделано.
     * @return array{done:int, total:int, steps:array, hidden:bool}
     */
    public static function onboarding(): array
    {
        if (\App\Core\Setting::get('onboarding.hidden') === '1') {
            return ['done' => 0, 'total' => 0, 'steps' => [], 'hidden' => true];
        }
        $mailHost = (string)setting('mail.host', config('mail.host', ''));
        $mailDriver = (string)setting('mail.driver', config('mail.driver', 'smtp'));
        $hb = (string)\App\Core\Setting::get('cron.heartbeat', '');
        $me = \App\Core\Auth::realUser();

        $steps = [
            [
                'label' => t('Подключить первый портал'),
                'hint' => t('Мастер за два клика выдаст реквизиты и готовый PHP-kit'),
                'done' => (int)DB::val('SELECT COUNT(*) FROM {clients}') > 0,
                'url' => '/admin/clients',
                'cta' => t('Подключить'),
                'icon' => 'portal',
            ],
            [
                'label' => t('Настроить отправку почты'),
                'hint' => t('Без SMTP не уйдут письма подтверждения и сброса пароля'),
                'done' => $mailHost !== '' || $mailDriver === 'log',
                'url' => '/admin/settings/section/smtp',
                'cta' => t('Настроить'),
                'icon' => 'mail',
            ],
            [
                'label' => t('Поставить cron'),
                'hint' => t('Очередь писем, ретраи событий и чистки живут на планировщике'),
                'done' => $hb !== '' && strtotime($hb . ' UTC') > time() - 600,
                'url' => '/admin/health',
                'cta' => t('Проверить'),
                'icon' => 'clock',
            ],
            [
                'label' => t('Удалить каталог /install'),
                'hint' => t('После установки он больше не нужен и лишний раз светится наружу'),
                'done' => !is_dir(SSO_ROOT . '/install'),
                'url' => '/admin/health',
                'cta' => t('Как удалить'),
                'icon' => 'shield',
            ],
            [
                'label' => t('Включить 2FA или passkey'),
                'hint' => t('Рекомендация, а не требование — вход останется рабочим и без них'),
                'done' => !empty($me['totp_enabled_at']) || \App\Models\Passkey::countForUser((int)($me['id'] ?? 0)) > 0,
                'url' => '/profile/security',
                'cta' => t('Включить'),
                'icon' => 'key',
            ],
        ];
        $done = count(array_filter($steps, fn($s) => $s['done']));
        return ['done' => $done, 'total' => count($steps), 'steps' => $steps, 'hidden' => $done === count($steps)];
    }

    /** Спрятать чеклист навсегда (кнопка «Скрыть»). */
    public function hideOnboarding(): never
    {
        $this->requireGlobalAdmin();
        \App\Core\Setting::set('onboarding.hidden', '1');
        redirect('/admin');
    }

    /**
     * SVG-график: polyline по дням. Чистый SVG, без библиотек (§5.2).
     *
     * Три вещи, на которых он спотыкался до v3.1.1:
     *  1. preserveAspectRatio="none" растягивает и обводку — спарклайн шириной
     *     120 в карточке на 236px давал линию 4px вместо 2. Лечится
     *     vector-effect="non-scaling-stroke".
     *  2. Ряд из одних нулей рисовался жирной линией по самому низу и читался
     *     как артефакт вёрстки, а не как «событий не было». Теперь у пустого
     *     ряда отдельный вид: тонкий пунктир и подпись для скринридера.
     *  3. Пустой массив ронял страницу: range(0, -1) возвращает [0, -1],
     *     и array_combine падал на разной длине.
     */
    public static function chartSvg(array $series, int $w = 720, int $h = 160, string $label = ''): string
    {
        $values = array_values($series);
        $n = count($values);
        if ($n === 0) return self::emptyChartSvg($w, $h, $label);

        $max = max(1, ...$values);
        $allZero = $max === 1 && array_sum($values) === 0;
        $pad = 4;
        /* Нулевая линия приподнята над краем: прижатая к границе она выглядит
           подчёркиванием карточки, а не графиком. */
        $base = $h - $pad - 1;
        $points = [];
        foreach ($values as $i => $v) {
            $x = $pad + ($w - 2 * $pad) * ($n > 1 ? $i / ($n - 1) : 0.5);
            $y = $base - ($base - $pad) * ($v / $max);
            $points[] = round($x, 1) . ',' . round($y, 1);
        }
        $line = implode(' ', $points);

        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img"'
            . ' aria-label="' . esc($label !== '' ? $label : t('График')) . '">';
        if ($allZero) {
            $svg .= '<line x1="' . $pad . '" y1="' . $base . '" x2="' . ($w - $pad) . '" y2="' . $base . '"'
                . ' stroke="var(--line-strong)" stroke-width="2" stroke-dasharray="4 5"'
                . ' vector-effect="non-scaling-stroke"/>';
        } else {
            $area = $line . ' ' . round($w - $pad, 1) . ',' . $base . ' ' . $pad . ',' . $base;
            $svg .= '<polygon points="' . $area . '" fill="var(--ink-soft)"/>'
                . '<polyline points="' . $line . '" fill="none" stroke="var(--ink)" stroke-width="2"'
                . ' stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
        }
        return $svg . '</svg>';
    }

    /** Нет ни одной точки — рисуем пунктир, а не пустоту и не фатал. */
    private static function emptyChartSvg(int $w, int $h, string $label): string
    {
        $y = round($h / 2, 1);
        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img"'
            . ' aria-label="' . esc($label !== '' ? $label : t('Данных пока нет')) . '">'
            . '<line x1="4" y1="' . $y . '" x2="' . ($w - 4) . '" y2="' . $y . '"'
            . ' stroke="var(--line-strong)" stroke-width="2" stroke-dasharray="4 5"'
            . ' vector-effect="non-scaling-stroke"/></svg>';
    }

    public static function sparkSvg(array $values, int $w = 120, int $h = 36, string $label = ''): string
    {
        return self::chartSvg(array_values($values), $w, $h, $label);
    }
}
