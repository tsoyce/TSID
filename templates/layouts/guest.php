<?php
/**
 * Макет гостевой: карта проектов фоном, форма входа поверх.
 *
 * Домен SSO набирают случайно и приходят по ссылке из письма. Сетка проектов
 * отвечает «куда ты попал» раньше любого текста — но только теми порталами,
 * которые владелец отметил сам (галочка в карточке портала, выключена по
 * умолчанию). Если отмеченных нет, гостевая не показывается вовсе: пустая
 * витрина хуже, чем сразу форма входа. Переменные: $content, $portals.
 */
use App\Core\Flash;
use App\Core\Setting;

$appName = Setting::appName();
$portals = $portals ?? [];
?>
<!DOCTYPE html>
<?php $themeDefault = App\Core\Bridge::defaultTheme(null);
      /* Фон страниц (v4.84): главная — тоже экран входа. */
      $backdrop = App\Core\Backdrop::layer('auth'); ?>
<html lang="<?= esc(lang()) ?>"<?= $themeDefault !== null ? ' data-theme-default="' . esc($themeDefault) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?= esc(branding_theme_color()) ?>">
<title><?= esc($appName) ?></title>
<?= styles_head() ?>
<link rel="icon" href="<?= esc(branding_favicon_href()) ?>">
<?= pwa_head() ?>
<?= branding_accent_style() ?>
<?php /* Главная — тоже экран входа (v4.77): оформление из /admin/appearance на
         ней всегда. Раньше она брала только акцент, и форма на главной не
         слушала ни шрифтов, ни скруглений. */ ?>
<?= App\Core\Bridge::themeStyle(null) ?>
<script nonce="<?= csp_nonce() ?>">
(function(){var t=localStorage.getItem('sso-theme')||localStorage.getItem('tsoy-theme')||document.documentElement.getAttribute('data-theme-default')||'auto';
if(t==='dark'||(t==='auto'&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.dataset.theme='dark';})();
</script>
</head>
<body<?= $backdrop !== '' ? ' class="has-backdrop"' : '' ?>>
<?= $backdrop ?>
<?= skip_link() ?>
<div class="guest">
  <?php /* Фон клипует только себя (position: fixed): страница остаётся обычным
           потоком, иначе форма выше вьюпорта обрезалась бы без возможности
           доскроллить — на телефоне её буквально нельзя было бы найти. */ ?>
  <div class="guest-bg" aria-hidden="true">
    <?php /* Стена снимков (v4.80). Была сетка в четыре колонки: при трёх
             проектах одна пустовала, четвёртая пряталась под формой, а
             единственная карточка колонки растягивалась на её высоту — снимок
             сверху и белое поле под ним. Теперь три колонки слева от формы, в
             каждой — все проекты в своём порядке, повторённые до заполнения,
             и колонка медленно едет по кругу (содержимое удвоено — стык не
             виден). Все карточки в пропорции снимков, 16:10, без растяжения. */
    $wall = [];
    $n = count($portals);
    for ($c = 0; $c < 3 && $n > 0; $c++) {
        $list = [];
        foreach ($portals as $i => $p) if ($i % 3 === $c) $list[] = $p;
        for ($k = $c + 1; count($list) < 3; $k++) $list[] = $portals[$k % $n];
        $wall[] = $list;
    } ?>
    <div class="pwall">
      <?php foreach ($wall as $list): ?>
      <div class="pwall-col"><div class="pwall-track">
        <?php for ($pass = 0; $pass < 2; $pass++): foreach ($list as $p): ?>
        <div class="pcard">
          <?= portal_card_shot($p) ?>
          <div class="pcard-body">
            <span class="pcard-name"><?= esc($p['name']) ?></span>
            <span class="pcard-host"><?= esc(host_label((string)$p['base_url'])) ?></span>
          </div>
        </div>
        <?php endforeach; endfor; ?>
      </div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="guest-layer">
    <?php /* Островок без пунктов навигации: до входа идти некуда. Остаются
             название установки, язык и тема. */ ?>
    <header class="isle isle-guest">
      <a class="wordmark" href="/"><?= brand_mark('mark-s') ?>
        <span class="wordmark-name"><?= esc(App\Core\Setting::appName()) ?></span></a>
      <div class="isle-end">
        <span class="lang-switch"><?php foreach (App\Core\Lang::SUPPORTED as $code => $name): ?><a href="/lang/<?= esc($code) ?>"<?= lang() === $code ? ' class="is-current"' : '' ?>><?= esc(strtoupper($code)) ?></a><?php endforeach; ?></span>
        <button class="btn-icon btn-icon-s" type="button" data-theme-toggle
                aria-label="<?= esc(t('Тема оформления')) ?>"><?= icon('theme-auto', 17) ?></button>
      </div>
    </header>

    <?php /* На телефоне повёрнутая сетка за формой — шум, который отнимает
             половину читаемости и всю высоту. Там её роль берёт полоса над
             формой: те же проекты, но в один ряд и не под текстом. */ ?>
    <?php if ($portals !== []): ?>
    <div class="guest-strip" aria-hidden="true">
      <div class="guest-strip-track">
        <?php /* Список удвоен: лента едет сдвигом на -50%, поэтому стык не
                 виден и скрипт не нужен. */ ?>
        <?php for ($pass = 0; $pass < 2; $pass++): foreach ($portals as $p): ?>
        <div class="pcard">
          <?= portal_card_shot($p) ?>
          <div class="pcard-body"><span class="pcard-name"><?= esc($p['name']) ?></span></div>
        </div>
        <?php endforeach; endfor; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="guest-body">
      <main id="main" class="guest-panel"><?= $content ?></main>
    </div>

    <div class="guest-foot">
      <?php $legal = legal_links(); if ($legal !== []): ?>
      <span class="footer-legal">
        <?php foreach ($legal as $legalTitle => $legalHref): ?>
        <a href="<?= esc($legalHref) ?>"><?= esc($legalTitle) ?></a>
        <?php endforeach; ?>
      </span>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="toasts" aria-live="polite">
<?php foreach (Flash::pull() as $f): ?>
  <div class="toast toast-<?= esc($f['type']) ?>"><?= esc(t($f['message'])) ?></div>
<?php endforeach; ?>
</div>

<?= js_i18n() ?>
<script src="<?= esc(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>