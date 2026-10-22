<?php
/** Базовый layout: шапка + контент + футер. Переменные: $content, $pageTitle. */
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Setting;

$user = Auth::check() ? Auth::user() : null;
$appName = Setting::appName();
$isAdminArea = str_starts_with((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/admin');
/* Фон страниц (v4.84) — до <!DOCTYPE>: YouTube на фоне дописывает frame-src в политику. */
$backdrop = \App\Core\Backdrop::layer($isAdminArea ? 'admin' : 'cabinet');
?>
<!DOCTYPE html>
<?php $themeDefault = App\Core\Appearance::lookEverywhere() ? App\Core\Bridge::defaultTheme(null) : null; ?>
<html lang="<?= esc(lang()) ?>"<?= $themeDefault !== null ? ' data-theme-default="' . esc($themeDefault) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?= esc(branding_theme_color()) ?>">
<?php /* Заголовок и тосты переводятся при выводе (v4.74): контроллеры передают
         их строкой, и в английском интерфейсе они шли по-русски. */ ?>
<title><?= esc(($pageTitle ?? '') !== '' ? t($pageTitle) . ' — ' . $appName : $appName) ?></title>
<?= styles_head() ?>
<link rel="icon" href="<?= esc(branding_favicon_href()) ?>">
<?= pwa_head() ?>
<?= branding_accent_style() ?>
<?php /* Оформление на весь сайт (v4.77) — если владелец не оставил его одному входу. */ ?>
<?= App\Core\Appearance::lookEverywhere() ? App\Core\Bridge::themeStyle(null) : '' ?>
<script nonce="<?= csp_nonce() ?>">
(function(){var t=localStorage.getItem('sso-theme')||localStorage.getItem('tsoy-theme')||document.documentElement.getAttribute('data-theme-default')||'auto';
if(t==='dark'||(t==='auto'&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.dataset.theme='dark';})();
</script>
<?= js_i18n() ?>
</head>
<body<?= $backdrop !== '' ? ' class="has-backdrop"' : '' ?>>
<?= $backdrop ?>
<?= skip_link() ?>
<?php
/* Код обновили, а миграцию не применили — самая частая беда при апгрейде.
   Раньше это выглядело как россыпь 500-х на случайных страницах, и понять
   причину было невозможно. Теперь состояние названо вслух, и кнопка рядом. */
$pendingMigrations = Auth::isAdmin() ? \App\Core\Migrations::pending() : [];

/* Та же беда, что с миграциями, и такая же незаметная: файлы не доехали при
   заливке. Четыре раза подряд это выглядело одинаково — россыпь 500-х на
   случайных страницах, — и каждый раз узнавали об этом, наткнувшись. Раздел
   «Поставка» знает ответ, но туда надо догадаться пойти; здесь состояние
   названо вслух там, где администратор и так стоит. */
$deliveryGap = Auth::isAdmin() ? \App\Core\Delivery::gap() : null;
?>
<?php
/* Полосы состояния собираются здесь, а выводятся ПОД островком — первым, что
   человек видит на странице. Раньше они стояли самым верхом документа и
   исчезали под островком: он `position: fixed` у верхнего края и перекрывал
   их целиком. Ни «над», ни «под» из этого не получалось — получалось «за».
   Внутри потока страницы перекрывать их больше нечем при любой ширине и
   любом числе строк, а измерять высоту скриптом не приходится. */
ob_start();
?>
<?php if ($pendingMigrations !== []): ?>
<div class="state-bar state-bar-warn">
  <span><?= icon('alert-triangle', 16) ?>
    <?= esc(t('Схема базы отстаёт от кода: не применено миграций — :n. Часть разделов будет недоступна.',
        ['n' => count($pendingMigrations)])) ?>
    <span class="mono hint"><?= esc(implode(', ', $pendingMigrations)) ?></span></span>
  <form method="post" action="/admin/migrate"><?= csrf_field() ?><?= back_field() ?>
    <button class="btn btn-s btn-primary" type="submit"><?= esc(t('Применить сейчас')) ?></button>
  </form>
</div>
<?php endif; ?>

<?php if ($deliveryGap !== null): ?>
<div class="state-bar state-bar-warn">
  <span><?= icon('download', 16) ?>
    <?= esc(t('Поставка неполная: не хватает файлов — :n. Разделы, которым они нужны, будут падать.',
        ['n' => $deliveryGap['missing']])) ?>
    <span class="mono hint"><?= esc($deliveryGap['first']) ?></span></span>
  <a class="btn btn-s btn-secondary" href="/admin/health/delivery"><?= esc(t('Показать все')) ?></a>
</div>
<?php endif; ?>

<?php /* Почта не подтверждена. Полоса, а не строчка в профиле: письмо теряется
         постоянно — попадает в спам, отбивается сервером, приходит через час, —
         и до v4.18.2 из этого положения не было выхода вовсе. Человек мог
         только зарегистрироваться заново на другой адрес. Здесь оба выхода
         сразу: прислать ещё раз и поменять адрес, в котором опечатка. */ ?>
<?php if ($user && empty($user['email_verified_at']) && !empty($user['email'])): ?>
<div class="state-bar state-bar-warn">
  <span><?= icon('mail', 16) ?>
    <?= esc(t('Почта :email не подтверждена — письмо со ссылкой уже отправлено.', ['email' => $user['email']])) ?></span>
  <form method="post" action="/verify/resend"><?= csrf_field() ?><?= back_field() ?>
    <button class="btn btn-s btn-primary" type="submit"><?= esc(t('Прислать ещё раз')) ?></button>
  </form>
  <a class="btn btn-s btn-secondary" href="/verify/change"><?= esc(t('Другой адрес')) ?></a>
</div>
<?php endif; ?>

<?php if (Auth::impersonating()): $real = Auth::realUser(); ?>
<div class="state-bar state-bar-warn">
  <span><?= icon('eye', 16) ?> <?= esc(t('Вы смотрите как :who (вы — :real)', ['who' => user_name($user), 'real' => user_name($real)])) ?></span>
  <form method="post" action="/admin/impersonate/stop"><?= csrf_field() ?>
    <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Вернуться к себе')) ?></button>
  </form>
</div>
<?php endif; ?>
<?php $stateBars = (string)ob_get_clean(); ?>

<?php
/* Островок вместо планки во всю ширину. Планка приклеена к краям, и страница
   читается её продолжением; островок отделён от краёв, и под ним виден лист.
   `.topbar` остаётся в системе нетронутым — островок включается там, где
   уместен, а не подменяет собой всё разом.

   Разделы берём из nav_sections(): один список на островок, док и «Ещё».
   Два списка рядом разошлись бы при первой правке, и разошлись бы молча. */
$nav = $user ? nav_sections($isAdminArea) : ['primary' => [], 'more' => []];
$navHome = $user ? ($isAdminArea ? '/admin' : '/profile') : '/login';
?>
<?php if ($user): ?>
<header class="isle">
  <?php /* Знак ведёт «домой», а дом зависит от того, где человек стоит: в
           админке это дашборд. Поэтому отдельного пункта «Дашборд» в ряду нет —
           он занял бы слот в доке, где их всего пять. */ ?>
  <a class="wordmark" href="<?= esc($navHome) ?>">
    <?= brand_mark('mark-s') ?>
    <span class="wordmark-name"><?= esc(Setting::appName()) ?></span>
  </a>

  <?php /* Где человек сейчас работает. Стоит у знака, а не в углу: это рамка
           всего, что он видит на любом экране, а не ещё один фильтр списка.
           Показывается только когда переключаться есть куда — одна кнопка на
           один вариант была бы украшением. */ ?>
  <?php if ($isAdminArea):
      $ctxOptions = \App\Core\AdminContext::available();
      $ctxNow = \App\Core\AdminContext::current();
      if (count($ctxOptions) > 1): ?>
  <div class="menu ctx-switch">
    <button class="ctx-chip" type="button" data-menu-toggle aria-haspopup="true" aria-expanded="false">
      <?= icon($ctxNow->is(App\Core\Scope::SYSTEM) ? 'globe' : 'users', 15) ?>
      <span><?= esc(\App\Core\AdminContext::label($ctxNow)) ?></span>
      <?= icon('chevron-down', 14) ?>
    </button>
    <div class="menu-panel">
      <?php foreach ($ctxOptions as $opt): ?>
      <form method="post" action="/admin/context">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="<?= esc($opt->key()) ?>">
        <button type="submit"<?= $opt->equals($ctxNow) ? ' class="active"' : '' ?>>
          <?= esc(\App\Core\AdminContext::label($opt)) ?>
        </button>
      </form>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; endif; ?>

  <nav class="isle-nav" aria-label="<?= esc(t('Разделы')) ?>">
    <?php foreach ($nav['primary'] as $item): ?>
    <a href="<?= esc($item['href']) ?>"<?= nav_active($item['href']) !== '' ? ' aria-current="page"' : '' ?>
       title="<?= esc($item['label']) ?>"><?= icon($item['icon'], 17) ?><?= esc($item['label']) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="isle-end">
    <button class="btn-icon btn-icon-s palette-trigger" type="button" data-palette-open
            aria-label="<?= esc(t('Поиск по системе')) ?>" title="<?= esc(t('Поиск · Ctrl+K')) ?>">
      <?= icon('search', 18) ?>
    </button>
    <?php if ($isAdminArea && Auth::isAdmin()): ?>
    <?php /* Настройки — не раздел в ряду, а утилита: заходят в них редко, но
             метко, и слот в доке они бы отняли у того, чем пользуются каждый
             день. */ ?>
    <a class="btn-icon btn-icon-s<?= nav_active('/admin/settings') !== '' ? ' active' : '' ?>"
       href="/admin/settings" aria-label="<?= esc(t('Настройки')) ?>" title="<?= esc(t('Настройки')) ?>">
      <?= icon('settings', 17) ?>
    </a>
    <?php endif; ?>
    <?php if ($nav['more'] !== []): ?>
    <?php
    $moreActive = '';
    foreach ($nav['more'] as $item) { if (nav_active($item['href']) !== '') { $moreActive = ' active'; break; } }
    ?>
    <div class="menu">
      <button class="btn-icon btn-icon-s<?= $moreActive ?>" type="button" data-menu-toggle
              aria-haspopup="true" aria-expanded="false"
              aria-label="<?= esc(t('Ещё')) ?>" title="<?= esc(t('Ещё')) ?>"><?= icon('menu', 18) ?></button>
      <div class="menu-panel">
        <?php foreach ($nav['more'] as $item): ?>
        <a href="<?= esc($item['href']) ?>"<?= nav_active($item['href']) !== '' ? ' class="is-current"' : '' ?>><?= esc($item['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <span class="isle-sep" aria-hidden="true"></span>
    <div class="menu">
      <?php /* Внутри только аватар с пустым alt — без aria-label скринридер
               читает просто «кнопка». */ ?>
      <button class="menu-btn" type="button" data-menu-toggle aria-haspopup="true"
              aria-expanded="false" aria-label="<?= esc(t('Меню профиля: :name', ['name' => user_name($user)])) ?>">
        <?= avatar_html($user, 32) ?>
      </button>
      <div class="menu-panel">
        <div class="menu-head">
          <div class="cell-main"><?= esc(user_name($user)) ?></div>
          <div class="hint"><?= esc($user['email']) ?></div>
        </div>
        <div class="menu-sep"></div>
        <?php if ($isAdminArea): ?>
          <a href="/profile"><?= esc(t('Мой профиль')) ?></a>
        <?php elseif (Auth::isAdmin() || Auth::clientAdminIds()): ?>
          <a href="/admin"><?= esc(t('Админка')) ?></a>
        <?php endif; ?>
        <a href="/logout" class="danger"><?= esc(t('Выйти')) ?></a>
      </div>
    </div>
  </div>
</header>

<?php /* Док: те же разделы, но внизу — там их достаёт большой палец. Ниже
         1024px подписи в островок не влезают вместе со знаком и аватаром,
         поэтому там остаются иконки, а названия живут здесь. */ ?>
<?php if ($nav['primary'] !== []): ?>
<nav class="dock" aria-label="<?= esc(t('Разделы')) ?>">
  <?php foreach (array_slice($nav['primary'], 0, 5) as $item): ?>
  <?php /* Подпись отдельным элементом: на узком экране она прячется целиком, и
           прятать нужно то, что можно назвать. `title` остаётся всегда — иначе
           иконка без подписи опознаётся только на память. */ ?>
  <a href="<?= esc($item['href']) ?>"<?= nav_active($item['href']) !== '' ? ' aria-current="page"' : '' ?>
     title="<?= esc($item['label']) ?>">
    <?= icon($item['icon'], 20) ?><span class="dock-label"><?= esc($item['label']) ?></span>
  </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

<main id="main" class="page<?= $user ? ' page-isle' : '' ?>"><div class="container">
<?= $stateBars ?>
<?= $content ?>
</div></main>

<footer class="page-footer"><div class="container">
  <?php /* Копирайт ведёт на «О сервисе»: версия и история релизов там,
           а не в интерфейсе, где они мешали бы каждый день. */ ?>
  <a class="footer-about" href="/about"><?= esc(branding_footer()) ?></a>
  <?php /* Документы — одной ссылкой на «О сервисе», где они и лежат списком.
           Разворачивать их в подвале значило занимать строку под то, что
           открывают раз в жизни. Ссылка остаётся: до политики должно быть
           один клик, а не поиск. */ ?>
  <?php if (legal_links() !== []): ?>
  <a class="footer-legal-link" href="/about#legal"><?= esc(t('Документы')) ?></a>
  <?php endif; ?>
  <?php /* Язык и тема — иконками: подписи «RU EN» и «Тема: авто» занимали
           половину подвала ради двух переключателей. Текущее состояние видно
           по самой иконке, остальное — в подсказке. */ ?>
  <span class="lang-switch" title="<?= esc(t('Язык интерфейса')) ?>"><?php foreach (App\Core\Lang::SUPPORTED as $code => $name): ?><a href="/lang/<?= esc($code) ?>"<?= lang() === $code ? ' class="is-current"' : '' ?> aria-label="<?= esc($name) ?>"><?= esc(strtoupper($code)) ?></a><?php endforeach; ?></span>
  <button class="btn-icon btn-icon-s" type="button" data-theme-toggle
          aria-label="<?= esc(t('Тема оформления')) ?>" title="<?= esc(t('Тема оформления')) ?>"><?= icon('theme-auto', 17) ?></button>
</div></footer>

<div class="toasts" aria-live="polite">
<?php foreach (Flash::pull() as $f): ?>
  <?php /* У сообщения об ошибке — кнопка «скопировать» и крестик, и оно не
           уходит само. Текст ошибки нужен целиком: его несут в поддержку, в
           переписку, сюда в чат. Прежде на это была пара секунд, за которые
           надо было успеть выделить мышью бегущее облачко. Успех и
           предупреждение исчезают как раньше: там копировать нечего. */ ?>
  <div class="toast toast-<?= esc($f['type']) ?>"<?= $f['type'] === 'error' ? ' data-sticky' : '' ?>>
    <span><?= esc(t($f['message'])) ?></span>
    <?php if ($f['type'] === 'error'): ?>
    <?php /* Иконками и под текстом: подпись «Скопировать» занимала пол-облака и
             спорила с самим сообщением, ради которого облако и появилось. */ ?>
    <span class="toast-actions">
      <button class="btn-icon btn-icon-s" type="button" data-toast-copy
              aria-label="<?= esc(t('Скопировать')) ?>" title="<?= esc(t('Скопировать')) ?>"><?= icon('copy', 15) ?></button>
      <button class="btn-icon btn-icon-s" type="button" data-toast-close
              aria-label="<?= esc(t('Закрыть')) ?>" title="<?= esc(t('Закрыть')) ?>"><?= icon('x', 15) ?></button>
    </span>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<script src="<?= esc(asset('assets/js/app.js')) ?>" defer></script>
<?php if ($user): ?><script src="<?= esc(asset('assets/js/palette.js')) ?>" defer></script><?php endif; ?>
</body>
</html>
