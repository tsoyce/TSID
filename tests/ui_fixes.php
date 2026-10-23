<?php
/**
 * Контрольный прогон 26.09 (v4.83): что нашлось и больше не должно вернуться.
 *
 * Владелец: «контрольный прогон проекта на наличие ошибок, багов, поломок,
 * уязвимостей, криворукости дизайна — и исправление всех этих недочётов».
 * Снимки всех разделов админки и кабинета на компьютере и телефоне дали
 * список ниже; каждая находка — строкой проверки. Разметочные правила
 * (баланс <div>, съеденный пробел, разметка в callout) — в templates_test.
 *
 * Запуск: php tests/ui_fixes.php  (сервер не нужен)
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\LoginFlow;
use App\Core\Resume;
use App\Core\View;
use App\Models\Client;

$passed = 0;
$failed = 0;
$src = static fn(string $rel): string => (string)file_get_contents(SSO_ROOT . '/' . $rel);
$app = $src('public/assets/css/app.css');

echo "── Экран входа по ключу возврата без сессии ──\n";
/* Боевая 26.09: сессия по дороге умерла, ключ rt в адресе есть — вход вернул
   бы на портал, а экран был безымянным: ни «Вы входите в …», ни темы портала. */
$made = [];
register_shutdown_function(static function () use (&$made): void { foreach ($made as $id) Client::purge($id); });
[$cid] = Client::create(['name' => 'Проба возврата', 'slug' => 'ui-fixes-' . getmypid(), 'base_url' => 'https://ui-fixes.example',
    'redirect_uris' => ['https://ui-fixes.example/cb'], 'post_logout_uris' => [], 'backchannel_uri' => '']);
$made[] = (int)$cid;
$client = Client::find((int)$cid);
$rt = Resume::issue(['response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'https://ui-fixes.example/cb',
    'scope' => 'openid', 'state' => 's', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256']);
$_SESSION = [];
$_GET = ['rt' => $rt];
$pending = LoginFlow::pendingClient();
ok($pending !== null && (int)$pending['id'] === (int)$cid, 'портал находится по ключу возврата, когда сессии нет');
$login = View::fetch('auth/login', ['pageTitle' => 'Вход', 'client' => $pending, 'popup' => false, 'identity' => '', 'captcha' => false]);
ok(str_contains($login, 'connector-head') && str_contains($login, 'Проба возврата'), 'и экран называет его: «Вы входите в …»');
$_GET = ['rt' => 'не-ключ'];
ok(LoginFlow::pendingClient() === null, 'чужая строка вместо ключа портала не называет');
$_GET = [];
$_SESSION = ['oauth_request' => ['client_id' => $client['client_id']]];
ok((int)(LoginFlow::pendingClient()['id'] ?? 0) === (int)$cid, 'сессия — по-прежнему первый источник');
$_SESSION = [];

echo "\n── Мелочи, найденные на снимках ──\n";
ok(str_contains($src('app/helpers.php'), "esc((\$r['actor'] ?? '') !== '' ? (string)\$r['actor'] : t('система'))"),
    'в журнале портала действие системы подписано словом, а не висячим «—» после заголовка с тире');
$docs = $src('templates/admin/settings/docs.php');
ok(str_contains($docs, "' <a href=\"/admin/settings/requisites\">' . esc(t('Заполнить →')) . '</a>', 'warning', true)"),
    'в «Документах» ссылка «Заполнить →» — ссылка, а не её HTML-код на экране');
$req = $src('templates/admin/requisites.php');
ok(!str_contains($req, '<p class="muted mb-16">') && str_contains($src('app/Controllers/Admin/SettingsController.php'),
    "'<a class=\"btn btn-secondary\" href=\"/admin/settings/section/docs\">' . esc(t('К документам →')) . '</a>'"),
    'в «Реквизитах» одно пояснение, ссылка на документы — кнопкой в шапке');
ok(str_contains($src('tests/admin_smoke.php'), "\\App\\Core\\Setting::set('branding.footer', (string)\$footerWas)"),
    'смоук возвращает подпись подвала, подставленную из файла настроек');

echo "\n── «Опасная зона» — в оболочке карточки ──\n";
/* Страница открывалась без колонки разделов — единственная из всех. */
$users = $src('app/Controllers/Admin/UsersController.php');
$clients = $src('app/Controllers/Admin/ClientsController.php');
$orgs = $src('app/Controllers/Admin/OrgsController.php');
ok(str_contains($users, "\$this->personPage(\$user, 'danger', [") && str_contains($clients, "\$this->clientPage(\$client, 'danger', [")
    && str_contains($orgs, "org_page(\$org, 'danger', 'admin/org/danger', ["),
    'человек, портал и организация рисуют её той же оболочкой, что остальные разделы');
$gone = array_filter(['user_danger', 'client_danger', 'org_danger'], static fn(string $t): bool => is_file(SSO_ROOT . "/templates/admin/$t.php"));
$here = array_filter(['user', 'client', 'org'], static fn(string $d): bool => is_file(SSO_ROOT . "/templates/admin/$d/danger.php"));
ok($gone === [] && count($here) === 3, 'шаблоны лежат рядом с разделами, старых нет');
$noHead = true;
foreach (['user', 'client', 'org'] as $d) {
    $t = $src("templates/admin/$d/danger.php");
    if (str_contains($t, 'breadcrumbs(') || str_contains($t, 'page-head') || str_contains($t, 'Вернуться к')) $noHead = false;
}
ok($noHead, 'своих крошек, заголовка и «← Вернуться» у них нет — это делает оболочка');

echo "\n── Телефон ──\n";
$shell = $src('templates/admin/object_shell.php');
ok(str_contains($shell, '<nav class="set-rail has-head"') && str_contains($shell, '<div class="set-rail-links">')
    && str_contains($app, '.set-rail.has-head { grid-auto-flow: row; grid-auto-columns: minmax(0, 1fr); overflow: visible;')
    && str_contains($app, '.set-rail.has-head .set-rail-links { grid-auto-flow: column; grid-auto-columns: max-content; overflow-x: auto; min-width: 0;'),
    'шапка карточки — над лентой разделов, и лента не раздувает страницу шире экрана (замер 375 из 375)');
ok(str_contains($app, "  .segmented.look-seg { grid-auto-flow: column; }"), 'значки темы и экрана предпросмотра — строкой, а не башенками');

echo "\n── Дашборд (v4.84) ──\n";
/* Под числом стояла одна кривая — у неудачных входов; три соседние плитки
   кончались пустым полем. */
$dash = $src('templates/admin/dashboard.php');
$dc = $src('app/Controllers/Admin/DashboardController.php');
ok(substr_count($dash, '<div class="sparkline"><?= DashboardController::sparkSvg(') === 4
    && str_contains($dash, "sparkSvg(\$regSpark, 120, 36,") && str_contains($dash, "sparkSvg(\$loginSpark, 120, 36,")
    && str_contains($dash, "sparkSvg(\$sessionSpark, 120, 36,"), 'у каждой из четырёх плиток — своя кривая за 14 дней');
/* v4.98: ряд и число берут вход из одного списка — Stats::LOGIN_EVENTS. */
ok(str_contains($dc, "\$loginSpark = array_values(Stats::perDay('audit_log', Stats::loginIn(), Stats::LOGIN_EVENTS, 14));")
    && str_contains($src('app/Core/Stats.php'), "->format('Y-m-d')] = 0;"),
    'входы считаются теми же событиями, что число над кривой; пустой день — ноль, а не пропуск');

echo "\n── Полоса «Сохранить изменения» (v4.89) ──\n";
/* Владелец 27.09: «старая болячка — кнопка "Сохранить изменения" с максимально
   уродским блоком». Полоса во всю ширину с чертой сверху: в стеклянной
   карточке — белая плита, на фоне страниц — шов через форму. */
ok((bool)preg_match('~\.form-actions \{[^}]*width: fit-content;[^}]*border-radius: calc\(var\(--radius-s\) \+ 8px\);[^}]*backdrop-filter: blur\(14px\)~s', $app),
    'полоса — пилюля по размеру кнопок, стеклом, а не лента во всю ширину');
ok(str_contains($app, ".form-actions.is-rest {\n  background: transparent; border-color: transparent; box-shadow: none;"),
    'на своём месте в конце формы — просто ряд кнопок, без подложки');
ok(!str_contains($app, '.bento-card .form-actions, .modal .form-actions { background: var(--surface); }'),
    'в карточке — без отдельной белой плиты');
$js = $src('public/assets/js/app.js');
ok(str_contains($js, "mark(e.intersectionRatio < 1 && e.boundingClientRect.bottom >= edge() - off - 1.5);")
    && str_contains($js, "rootMargin: '0px 0px -' + (off + 1) + 'px 0px'")
    && str_contains($js, "requestAnimationFrame(function () { bar.classList.add('is-ready'); });"),
    'пилюля — только пока полоса прилипла; первое состояние без перехода');
ok(str_contains(form_actions(), '<div class="form-actions"><button class="btn btn-primary" type="submit">'),
    'все формы берут полосу одним вызовом — вид один на всех');

echo "\n" . ($failed === 0 ? "✓ все $passed проверок" : "✗ провалов: $failed из " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
