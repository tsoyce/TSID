<?php /**
 * Оболочка настроек (§5.6): колонка разделов слева, содержимое справа.
 *
 * До этого настройки жили в шести местах: восемь вкладок в одном экране плюс
 * пять отдельных страниц. Вкладки не давали ссылку на раздел, у каждой формы
 * была своя полоса «сохранить» — восемь полос на одном экране, — а соседние
 * разделы (оформление, вебхуки, здоровье, инструменты, система) выглядели
 * вообще другим продуктом.
 *
 * Теперь у раздела свой адрес, одна форма и одна кнопка. Колонка — тот же
 * перечень, что и везде (App\Core\SettingsMap), поэтому разойтись ей не с чем.
 *
 * Переменные: $section — ключ текущего раздела, $inner — готовая разметка.
 */
use App\Core\SettingsMap;

$current = SettingsMap::find($section ?? '') ?? ['title' => t('Настройки'), 'desc' => ''];
?>
<?= breadcrumbs([[t('Настройки'), '/admin/settings'], [t($current['title']), null]]) ?>

<div class="set-shell">
  <?php /* Колонка — навигация, а не оглавление: активный раздел помечен,
           остальные ведут по своим адресам. На узком экране она уезжает
           наверх и превращается в горизонтальную ленту. */ ?>
  <nav class="set-rail" aria-label="<?= esc(t('Разделы настроек')) ?>">
    <?php foreach (SettingsMap::visible() as $group => $items): ?>
    <div class="set-rail-group">
      <div class="set-rail-title"><?= esc(t(SettingsMap::GROUPS[$group] ?? $group)) ?></div>
      <?php foreach ($items as $s): ?>
      <a class="set-rail-link<?= ($s['key'] === ($section ?? '')) ? ' is-current' : '' ?>"
         href="<?= esc($s['route']) ?>"<?= ($s['key'] === ($section ?? '')) ? ' aria-current="page"' : '' ?>>
        <?= icon($s['icon'], 16) ?><span><?= esc(t($s['title'])) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </nav>

  <div class="set-body">
    <div class="page-head">
      <h1><?= esc(t($current['title'])) ?></h1>
      <?php if (!empty($headActions)): ?><div class="row"><?= $headActions ?></div><?php endif; ?>
    </div>
    <?php if (!empty($current['desc'])): ?>
    <p class="muted mb-16 page-lede"><?= esc(t($current['desc'])) ?></p>
    <?php endif; ?>
    <?= $inner ?>
  </div>
</div>
