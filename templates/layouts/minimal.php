<?php
/** Минимальный layout: страницы ошибок, системные экраны. Без зависимостей от БД. */
/* Страница ошибки обязана отрисоваться даже при мёртвой БД, поэтому каждое
   обращение к настройкам — под try, с безопасным значением по умолчанию. */
$appName = 'SSO';
try { $appName = \App\Core\Setting::appName(); } catch (\Throwable) {}
$htmlLang = 'ru';
try { $htmlLang = lang(); } catch (\Throwable) {}
$themeColor = '#1D4ED8';
try { $themeColor = branding_theme_color(); } catch (\Throwable) {}
/* Оформление на весь сайт (v4.77) — и на страницах ошибок; без базы — родное. */
$look = '';
$themeDefault = null;
try { if (\App\Core\Appearance::lookEverywhere()) { $look = \App\Core\Bridge::themeStyle(null); $themeDefault = \App\Core\Bridge::defaultTheme(null); } } catch (\Throwable) {}
?>
<!DOCTYPE html>
<html lang="<?= esc($htmlLang) ?>"<?= $themeDefault !== null ? ' data-theme-default="' . esc($themeDefault) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?= esc($themeColor) ?>">
<title><?= esc(t($pageTitle ?? 'Ошибка') . ' — ' . $appName) ?></title>
<?= styles_head() ?>
<?= $look ?>
<script nonce="<?= csp_nonce() ?>">
(function(){var t=localStorage.getItem('sso-theme')||localStorage.getItem('tsoy-theme')||document.documentElement.getAttribute('data-theme-default')||'auto';
if(t==='dark'||(t==='auto'&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.dataset.theme='dark';})();
</script>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card mw-sm">
    <?= $content ?>
  </div>
</div>
<?= js_i18n() ?>
<script src="<?= esc(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
