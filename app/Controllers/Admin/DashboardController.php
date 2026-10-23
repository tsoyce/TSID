<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Stats;
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

        /* Сутки — местные, вход — любой из LOGIN_EVENTS (v4.98): см. Stats.
           Период — выбор человека (v4.99): до этого всё было «сегодня» над
           кривой «за 14 дней», и число с кривой говорили про разное. */
        $days = Stats::period($_GET['p'] ?? null);
        [$since, $prevSince] = Stats::bounds($days);
        $login = [Stats::loginIn(), Stats::LOGIN_EVENTS];
        $pair = static fn(string $table, string $where, array $params): array => [
            Stats::count($table, $where, $params, $since),
            Stats::count($table, $where, $params, $prevSince, $since),
        ];
        $kpi = [
            'logins' => $pair('audit_log', $login[0], $login[1]),
            'users_new' => $pair('users', '1 = 1', []),
            'fails' => $pair('audit_log', 'event = ?', ['login.fail']),
            'sessions_active' => (int)DB::val('SELECT COUNT(*) FROM {sessions} WHERE revoked_at IS NULL AND expires_at > ?', [now()]),
            'users_total' => (int)DB::val('SELECT COUNT(*) FROM {users} WHERE status != ?', ['deleted']),
        ];

        /* Кривые — у каждой плитки своя (v4.84), за тот же период, что и число. */
        $loginSpark = array_values(Stats::series('audit_log', $login[0], $login[1], $days));
        $regSpark = array_values(Stats::series('users', '1 = 1', [], $days));
        $failSpark = array_values(Stats::series('audit_log', 'event = ?', ['login.fail'], $days));
        $sessionSpark = array_values(Stats::series('sessions', '1 = 1', [], $days));

        $audit = DB::all(
            'SELECT a.*, u.email AS actor_email, o.email AS on_email, c.name AS client_name
             FROM {audit_log} a
             LEFT JOIN {users} u ON u.id = a.actor_user_id
             LEFT JOIN {users} o ON o.id = a.on_user_id
             LEFT JOIN {clients} c ON c.id = a.client_id
             ORDER BY a.id DESC LIMIT 10');

        View::render('admin/dashboard', [
            'pageTitle' => t('Дашборд'),
            'days' => $days,
            'kpi' => $kpi,
            'byMethod' => Stats::loginsByMethod($days),
            'failSpark' => $failSpark,
            'loginSpark' => $loginSpark,
            'sessionSpark' => $sessionSpark,
            'regSpark' => $regSpark,
            'portals' => Stats::portals($since),
            'protection' => Stats::protection(),
            'attention' => self::attention(HealthController::checks(true), $kpi['fails'][0], $days),
            'audit' => $audit,
            'onboarding' => self::onboarding(),
        ]);
    }

    /**
     * «Аналитика» (v5.1): как входят и регистрируются — то, что пульт
     * показывает одной цифрой, здесь разложено: воронка, часы, причины
     * неудач, браузеры и системы. Тот же период, те же местные сутки.
     */
    public function analytics(): never
    {
        $this->requireGlobalAdmin();
        $days = Stats::period($_GET['p'] ?? null);
        [$since] = Stats::bounds($days);
        /* Часы недели за сутки не сложатся в картину — для «сегодня» берём неделю. */
        $heatSince = $days < 7 ? Stats::dayStart(6) : $since;
        $byMethod = Stats::loginsByMethod($days);
        View::render('admin/analytics', [
            'pageTitle' => t('Аналитика'),
            'days' => $days,
            'funnel' => Stats::funnel($since),
            'heat' => Stats::heatmap($heatSince),
            'heatDays' => max(7, $days),
            'reasons' => Stats::failReasons($since),
            'devices' => Stats::devices($since),
            'methods' => array_map('array_sum', $byMethod),
        ]);
    }

    /**
     * «Система» (v5.2): сколько запросов, как быстро и с какими ошибками
     * отвечает приложение, как уходит почта. Источник — журнал запросов
     * (activity_log): он и так пишет время и ответ каждого запроса.
     */
    public function system(): never
    {
        $this->requireGlobalAdmin();
        $days = Stats::period($_GET['p'] ?? null);
        [$since] = Stats::bounds($days);
        /* Стук сканеров — не нагрузка приложения: в «Запросах» его нет. */
        $human = 'NOT (user_id IS NULL AND status IN (404, 405))';
        View::render('admin/analytics_system', [
            'pageTitle' => t('Система'),
            'days' => $days,
            'requests' => Stats::series('activity_log', $human, [], $days),
            'errors' => Stats::series('activity_log', 'status >= ?', [500], $days),
            'speed' => Stats::speed($since),
            'slow' => Stats::slowPaths($since),
            'mail' => Stats::byStatus('mail_queue', '1 = 1', [], $since),
        ]);
    }

    /**
     * «Требует внимания» (v4.99): только то, что не в порядке, — со ссылкой,
     * куда идти. Раньше здоровье стояло списком из шести зелёных строк, и
     * единственная красная терялась среди них.
     * @return list<array{level:string, title:string, note:string, url:string}>
     */
    public static function attention(array $health, int $fails, int $days): array
    {
        $out = [];
        foreach ($health as $h) {
            if ($h['status'] === 'ok') continue;
            $out[] = ['level' => $h['status'] === 'fail' ? 'fail' : 'warn', 'title' => (string)$h['label'],
                'note' => (string)$h['note'], 'url' => '/admin/health'];
        }
        /* Всплеск неудачных входов: за период втрое больше обычного для такого
           же отрезка за прошлые 28 дней, и не меньше 20 — единицы не тревога. */
        $base = Stats::count('audit_log', 'event = ?', ['login.fail'], Stats::dayStart(27 + $days), Stats::dayStart($days - 1));
        $usual = $base / 28 * $days;
        if ($fails >= 20 && $fails > 3 * $usual) {
            $out[] = ['level' => 'warn', 'title' => t('Неудачных входов больше обычного'),
                'note' => t(':n за период, обычно около :u', ['n' => $fails, 'u' => (int)round($usual)]), 'url' => '/admin/logs?event=login.fail'];
        }
        $stuck = (int)DB::val('SELECT COUNT(*) FROM {mail_queue} WHERE status = ? AND created_at < ?', ['pending', now_plus(-900)]);
        if ($stuck > 0) {
            $out[] = ['level' => 'warn', 'title' => t('Письма ждут отправки'),
                'note' => t('в очереди дольше 15 минут: :n', ['n' => $stuck]), 'url' => '/admin/logs/mail'];
        }
        $events = (int)DB::val('SELECT COUNT(*) FROM {client_events} WHERE status = ? AND created_at >= ?', ['failed', Stats::dayStart(6)]);
        if ($events > 0) {
            $out[] = ['level' => 'warn', 'title' => t('Порталы не приняли события'),
                'note' => t('за 7 дней: :n', ['n' => $events]), 'url' => '/admin/clients'];
        }
        return $out;
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

    /**
     * Столбцы с накоплением по способам входа (v4.99). Чистый SVG, как и
     * кривые: ось с круглыми делениями, сетка, подписи дат. Подсказку по
     * наведению рисует app.js из data-атрибутов столбца; таблица-двойник
     * лежит рядом для чтения с экрана.
     *
     * @param array<string, array<string,int>> $byMethod событие => [ключ => n]
     */
    public static function barsSvg(array $byMethod, int $days, string $label): string
    {
        $W = 720; $H = 220; $L = 36; $R = 4; $T = 8; $B = 24;
        $series = array_values($byMethod);
        $keys = array_keys($series[0] ?? []);
        $n = count($keys);
        if ($n === 0) return self::emptyChartSvg($W, $H, $label);
        $totals = [];
        foreach ($keys as $k) $totals[$k] = array_sum(array_map(static fn($s) => (int)$s[$k], $series));
        $max = max($totals);

        /* Деления круглые (1, 2, 5 × 10ⁿ), не больше пяти: подпись «37» над
           сеткой читается как погрешность, «40» — как шкала. */
        $step = 1;
        foreach ([1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000, 10000, 20000, 50000, 100000] as $c) {
            $step = $c;
            if ($c * 4 >= $max) break;
        }
        $top = max($step, (int)ceil($max / $step) * $step);
        $y = static fn(float $v): float => round($T + ($H - $T - $B) * (1 - $v / $top), 1);

        $svg = '<svg class="bars" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . esc($label) . '">';
        for ($g = 0; $g <= $top; $g += $step) {
            $svg .= '<line class="chart-grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y($g) . '" y2="' . $y($g) . '"/>'
                . '<text class="chart-axis" x="' . ($L - 6) . '" y="' . ($y($g) + 4) . '" text-anchor="end">' . $g . '</text>';
        }
        $bw = ($W - $L - $R) / $n;
        $gap = $n > 40 ? 1 : 2;
        foreach ($keys as $i => $k) {
            $x = round($L + $i * $bw + $gap / 2, 1);
            $w = max(1, round($bw - $gap, 1));
            $tip = self::tick((string)$k, $days);
            $vals = array_map(static fn($s) => (int)$s[$k], $series);
            $svg .= '<g class="bar-col" data-tip="' . esc($tip) . '" data-values="' . implode(',', $vals) . '">';
            $acc = 0;
            foreach ($vals as $j => $v) {
                if ($v === 0) continue;
                $y0 = $y($acc); $acc += $v; $y1 = $y($acc);
                /* Зазор 1px между слоями — граница слоя видна и без цвета. */
                $svg .= '<rect class="s' . ($j + 1) . '" x="' . $x . '" y="' . $y1 . '" width="' . $w . '" height="' . max(0.5, round($y0 - $y1 - 1, 1)) . '"/>';
            }
            $svg .= '<rect class="bar-hit" x="' . round($L + $i * $bw, 1) . '" y="' . $T . '" width="' . round($bw, 1) . '" height="' . ($H - $T - $B) . '"/></g>';
        }
        /* Подписи по оси X: шесть-семь штук, первая и последняя — всегда. */
        $ticks = min($n, $days === 1 ? 7 : 6);
        for ($t = 0; $t < $ticks; $t++) {
            $i = $ticks > 1 ? (int)round($t * ($n - 1) / ($ticks - 1)) : 0;
            $k = $keys[$i];
            $text = self::tick((string)$k, $days);
            $anchor = $t === 0 ? 'start' : ($t === $ticks - 1 ? 'end' : 'middle');
            $tx = $t === 0 ? $L : ($t === $ticks - 1 ? $W - $R : round($L + ($i + .5) * $bw, 1));
            $svg .= '<text class="chart-axis" x="' . $tx . '" y="' . ($H - 6) . '" text-anchor="' . $anchor . '">' . esc($text) . '</text>';
        }
        return $svg . '</svg>';
    }

    /** Подпись ключа ряда: «14:00» или «23.10». Ключ уже местный — пояс не трогаем. */
    private static function tick(string $k, int $days): string
    {
        return $days === 1 ? $k . ':00' : substr($k, 8, 2) . '.' . substr($k, 5, 2);
    }
}
