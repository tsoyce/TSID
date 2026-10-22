<?php /**
 * Плитка своей схемы (v4.79): коллаж обеих тем, имя её же шрифтом и крестик
 * «убрать» для того, кто правит настройки установки.
 *
 * $scheme  {id, name, theme}
 * $canDrop может ли убрать
 * $payload что подставить в форму (у готовых схем — только цвета); по умолчанию — схема целиком
 */
use App\Core\Bridge;
use App\Core\LookSchemes;
use App\Core\Setting;

$th = $scheme['theme'];
$siteAccent = (string)Setting::get('branding.accent', '') ?: '#1D4ED8';
$col = static function (string $key) use ($th, $siteAccent): string {
    $v = (string)($th[$key] ?? '');
    if ($v !== '') return $v;
    if ($key === 'accent') return $siteAccent;
    if ($key === 'accent_dark') return (string)($th['accent'] ?? '') ?: '#60A5FA';
    return Bridge::NATIVE[$key] ?? '#888888';
};
$font = (string)($th['font_display'] ?? $th['font'] ?? '');
?>
<div class="look-mine" data-look-mine="<?= esc($scheme['id']) ?>">
  <button class="look-preset" type="button" data-look-adopt="<?= esc((string)json_encode($payload ?? LookSchemes::formValues($th), JSON_UNESCAPED_UNICODE)) ?>"
          title="<?= esc($scheme['name']) ?>">
    <span class="lp-art">
      <?php foreach (['', '_dark'] as $sfx): ?>
      <span class="lp-half" style="--lb:<?= esc($col('background' . $sfx)) ?>;--ls:<?= esc($col('surface' . $sfx)) ?>;--la:<?= esc($col('accent' . $sfx)) ?>;--lt:<?= esc($col('text' . $sfx)) ?>">
        <span class="lp-card"><span class="lp-line"></span><span class="lp-line lp-short"></span><span class="lp-btn"></span></span>
      </span>
      <?php endforeach; ?>
    </span>
    <span class="lp-name ff-var" style="--ff:<?= esc($font !== '' ? Bridge::fontStack($font) : 'inherit') ?>"><?= esc($scheme['name']) ?></span>
  </button>
  <?php if (!empty($canDrop)): ?>
  <button class="look-mine-drop btn-icon btn-icon-s" type="button" data-look-drop-scheme="<?= esc($scheme['id']) ?>"
          data-name="<?= esc($scheme['name']) ?>" aria-label="<?= esc(t('Убрать схему «:name»', ['name' => $scheme['name']])) ?>"><?= icon('x', 14) ?></button>
  <?php endif; ?>
</div>
