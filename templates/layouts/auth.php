<?php
/** Layout карточки входа/регистрации: одиночная 420px по центру (§10.4).
 *  $popup=true → компактный режим без футера (§10.6). */
use App\Core\Flash;
use App\Core\Setting;

$appName = Setting::appName();
/* Режим окна берётся из сессии, а контроллер лишь уточняет его.
   Раньше было наоборот, и каждый экран обязан был передать признак сам. Вход и
   регистрация передавали, а «не помню», «новый пароль» и подтверждение почты —
   нет: в попапе размером 480×680 переход на соседнюю вкладку раскрывал полную
   раскладку с колонками и подвалом. Одна и та же полоса вкладок вела то к
   компактной карточке, то к целой странице. Умолчание из сессии закрывает и те
   экраны, которых ещё нет. */
$popup = $popup ?? \App\Core\LoginFlow::pendingIsPopup();
$popup = !empty($popup);
/* Предпросмотр редактора оформления (v4.71): тот же экран, но тема задана
   редактором, а не устройством, и отправить отсюда ничего нельзя. */
$preview = $preview ?? null;
/* Вход во фрейме на странице портала (v4.73): app.js сообщает странице портала,
   что фрейм готов и какой он высоты, и просит отдельное окно там, где фрейм
   не справится. Адрес — origin её адреса возврата: сообщения уходят только туда. */
$frameOrigin = $preview === null ? \App\Core\Framed::parentOrigin() : null;
/* Портал — до <html>: от него зависит и тема по умолчанию (v4.80). */
$portal = !empty($client) ? $client : ($preview === null ? \App\Core\LoginFlow::pendingClient() : null);
$themeDefault = $preview === null ? App\Core\Bridge::defaultTheme($portal) : null;
/* Фон страниц (v4.84) — у экрана входа, и в компактной раскладке тоже: во
   фрейме на странице портала и в окне входа (v4.87). В 4.85 фон пускали во
   фрейм, но условие «не попап» его всё равно отсекало: фрейм — та же
   компактная раскладка, что и окно. Видео и YouTube скрипт во фрейме не
   подключает (YouTube — вложенный документ, на котором Chrome 153 с
   блокировщиками и падал, 4.73), а в узком окне — сам по ширине экрана. */
$backdrop = App\Core\Backdrop::layer('auth');
?>
<!DOCTYPE html>
<html lang="<?= esc(lang()) ?>"<?= $themeDefault !== null ? ' data-theme-default="' . esc($themeDefault) . '"' : '' ?><?= $preview !== null && $preview['scheme'] === 'dark' ? ' data-theme="dark"' : '' ?><?= $frameOrigin !== null ? ' data-frame-origin="' . esc($frameOrigin) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?= esc(branding_theme_color()) ?>">
<title><?= esc(t($pageTitle ?? 'Вход') . ' — ' . $appName) ?></title>
<?= styles_head() ?>
<link rel="icon" href="<?= esc(branding_favicon_href()) ?>">
<?php /* В попапе соцвхода манифест не нужен: окно живёт секунды и закрывается */ ?>
<?= $popup ? '' : pwa_head() ?>
<?= branding_accent_style() ?>
<?php /* Тема портала, откуда пришёл человек (v4.65), — после акцента владельца,
         чтобы перекрыть его. Портал берём из самого потока входа, а не только из
         переменной экрана: «не помню» и соседние экраны его не передают, а
         выглядеть должны так же, как вход. */
?>
<?= App\Core\Bridge::themeStyle($portal) ?>
<?php if ($preview !== null): ?>
<?php /* Что из палитры не применится — редактору, который читает это из фрейма. */ ?>
<meta name="look-why" content="<?= esc((string)json_encode($preview['why'], JSON_UNESCAPED_UNICODE)) ?>">
<?php else: ?>
<script nonce="<?= csp_nonce() ?>">
(function(){var t=localStorage.getItem('sso-theme')||localStorage.getItem('tsoy-theme')||document.documentElement.getAttribute('data-theme-default')||'auto';
if(t==='dark'||(t==='auto'&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.dataset.theme='dark';})();
</script>
<?php endif; ?>
</head>
<?php $bodyClass = trim(($popup ? 'popup' : ($preview !== null ? 'is-preview' : '')) . ($backdrop !== '' ? ' has-backdrop' : '')); ?>
<body<?= $bodyClass !== '' ? ' class="' . $bodyClass . '"' : '' ?>>
<?= $backdrop ?>
<?php if ($popup): ?>
<?php /* Компактная раскладка: окно входа (480×680) и фрейм на странице портала.
         Колонок и подвала нет. Знак — тот же, что над формой в полной
         раскладке (v4.87): раньше здесь стоял запасной «● Название», и окно
         входа выглядело чужим рядом с главной. Пришёл с портала — вместо знака
         коннектор портала, как и там. Карточки нет: окно уже и есть карточка,
         а вторая внутри давала рамку в рамке. */ ?>
<div class="auth-wrap">
  <div class="auth-shell is-popup">
    <main id="main" class="auth-card">
      <?php if (empty($client)): ?>
      <div class="auth-brandline"><?= brand_mark() ?>
        <span class="wordmark-name"><?= esc($appName) ?></span>
      </div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<?php else: ?>
<?php /* Две колонки на широком экране. Это единственная страница SSO, которую
         человек видит на чужом сайте, и видит на каждом сайте группы, —
         поэтому слева представление установки, справа форма. Ниже 1024px
         панель не рисуется вовсе: на телефоне она отняла бы весь первый экран
         у того, ради чего человек пришёл.

         Панель принадлежит владельцу установки и по умолчанию пуста — белая
         марка означает, что большинство ничего не загрузит. Поэтому «пусто»
         оформлено как состояние, а не как дыра. */ ?>
<?php /* Раскладка — из оформления (v4.71): панель слева, справа или её нет.
         Порядок в разметке не меняется — меняется только сетка: форма всегда
         идёт в документе второй, и программа чтения с экрана находит её там же. */
$layout = App\Core\Bridge::layout($portal); ?>
<div class="auth-split<?= $layout !== 'left' ? ' is-pane-' . $layout : '' ?>"<?= $preview !== null ? ' inert' : '' ?>>
  <?php if ($layout !== 'none'): ?>
  <div class="auth-split-media"><?= auth_media_pane($portal) ?></div>
  <?php endif; ?>
  <div class="auth-split-form">
    <main id="main" class="auth-split-inner">
      <?php /* Знак над формой — якорь на узких экранах, где панели слева нет.
               Когда человек пришёл с портала, его заменяет коннектор: два знака
               и пунктир между ними говорят то же самое и точнее. */ ?>
      <?php if (empty($client)): ?>
      <div class="auth-brandline"><?= brand_mark() ?>
        <span class="wordmark-name"><?= esc(Setting::appName()) ?></span>
      </div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  <?php /* Подвал живёт в колонке формы, а не под обеими: под панелью-картинкой
           ему делать нечего, а прижатый к низу страницы он на широком экране
           уезжал бы от формы на пол-экрана.
           .auth-footer-bar, а не .container: в base.php подвал обёрнут в
           контейнер, и флекс-раскладку задаёт именно он. Здесь контейнера нет,
           поэтому ссылки, переключатель языка и тема валились друг под друга. */ ?>
  <div class="page-footer auth-footer-bar">
    <?php $legal = legal_links(); if ($legal !== []): ?>
    <span class="footer-legal">
      <?php foreach ($legal as $legalTitle => $legalHref): ?>
      <a href="<?= esc($legalHref) ?>"><?= esc($legalTitle) ?></a>
      <?php endforeach; ?>
    </span>
    <?php endif; ?>
    <span class="lang-switch"><?php foreach (App\Core\Lang::SUPPORTED as $code => $name): ?><a href="/lang/<?= esc($code) ?>"<?= lang() === $code ? ' class="is-current"' : '' ?>><?= esc(strtoupper($code)) ?></a><?php endforeach; ?></span>
    <?php /* На экране входа документы остаются ссылками, а не прячутся на
             «О сервисе»: здесь человек соглашается с ними, и до текста должно
             быть один клик. Тема — иконкой, как и в остальном подвале. */ ?>
    <button class="btn-icon btn-icon-s" type="button" data-theme-toggle
            aria-label="<?= esc(t('Тема оформления')) ?>" title="<?= esc(t('Тема оформления')) ?>"><?= icon('theme-auto', 17) ?></button>
  </div>
  </div>
</div>
<?php endif; ?>

<div class="toasts" aria-live="polite">
<?php foreach ($preview === null ? Flash::pull() : [] as $f): ?>
  <div class="toast toast-<?= esc($f['type']) ?>"><?= esc(t($f['message'])) ?></div>
<?php endforeach; ?>
</div>

<?= js_i18n() ?>
<script src="<?= esc(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
