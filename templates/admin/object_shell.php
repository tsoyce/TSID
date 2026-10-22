<?php /**
 * Оболочка объекта: колонка разделов слева, содержимое справа.
 *
 * Одна на организацию, сервис и человека. Классы те же, что у настроек
 * (`set-shell`, `set-rail`, `set-body`): три места, одинаковые по смыслу, не
 * должны выглядеть по-разному, а второй набор правил разошёлся бы с первым при
 * первой же правке.
 *
 * Переменные: $map — класс карты, $object — сам объект, $section — ключ
 * раздела, $inner — готовая разметка, $counts — числа у разделов, $backTo —
 * куда ведёт первая хлебная крошка.
 */
/** @var class-string<App\Core\ObjectMap> $map */
$current = $map::find($section ?? '') ?? ['title' => '', 'desc' => ''];
$isFirst = ($map::find($section ?? '')['path'] ?? '') === '';
?>
<?php /* Хлебные крошки ведут к родителю и только к нему (v4.69). Имя объекта
         стоит в шапке колонки строкой ниже, и печатать его ещё и в крошках
         значило повторять одно и то же дважды в одном углу экрана — замечание
         владельца 13.09. Раздел в крошки не входит тем более: он заголовок
         страницы. На первый раздел объекта ведёт колонка. */ ?>
<nav class="breadcrumbs" aria-label="Хлебные крошки">
  <a href="<?= esc($backTo) ?>"><?= icon('arrow-left', 14, 'icon-muted') ?> <?= esc($backTitle) ?></a>
</nav>

<div class="set-shell">
  <nav class="set-rail has-head" aria-label="<?= esc(t('Разделы')) ?>">
    <?php /* Шапка колонки: лицо объекта, имя и служебные метки (v4.69).
             Лицо — то, по чему объект узнают в других местах: иконка портала,
             аватар человека, знак организации. Метки — мелко и моноширинно:
             их подставляют в чужие конфиги и ищут в журналах, поэтому
             последнюю — идентификатор — можно скопировать одной кнопкой. */ ?>
    <div class="set-rail-group rail-head">
      <?php $badge = $map::badge($object); if ($badge !== ''): ?>
      <span class="rail-badge"><?= $badge ?></span>
      <?php endif; ?>
      <div class="rail-id">
        <div class="rail-name"><?= esc($map::title($object)) ?></div>
        <?php $marks = $map::marks($object); if ($marks !== []):
          $markId = 'rail-mark-' . substr(md5(implode('|', $marks)), 0, 8); ?>
        <?php /* Метка на строку, а кнопка — вплотную к последней (v4.70). В одну
                 строку через «·» длинный client_id переносился, и кнопка
                 оставалась одна на третьей строке, непонятно чья. */ ?>
        <div class="rail-marks">
          <?php foreach ($marks as $i => $m): $last = $i === count($marks) - 1; ?>
          <?php if ($last): ?>
          <span class="rail-mark-row"><code id="<?= $markId ?>" title="<?= esc($m) ?>"><?= esc($m) ?></code><button class="rail-copy" type="button" data-copy="<?= $markId ?>"
                  title="<?= esc(t('Копировать')) ?>" aria-label="<?= esc(t('Копировать')) ?>"><?= icon('copy', 13) ?></button></span>
          <?php else: ?>
          <code><?= esc($m) ?></code>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php /* Разделы — своей лентой (v4.83): на телефоне шапка объекта стоит над
             ней, а не первой плиткой ленты, отнимая у разделов весь экран. */ ?>
    <div class="set-rail-links">
    <?php foreach ($map::visible() as $group => $items): ?>
    <div class="set-rail-group">
      <div class="set-rail-title"><?= esc(t($map::groups()[$group] ?? $group)) ?></div>
      <?php foreach ($items as $s): ?>
      <a class="set-rail-link<?= ($s['key'] === ($section ?? '')) ? ' is-current' : '' ?>"
         href="<?= esc($map::route($object, $s['key'])) ?>"<?= ($s['key'] === ($section ?? '')) ? ' aria-current="page"' : '' ?>>
        <?= icon($s['icon'], 16) ?><span><?= esc(t($s['title'])) ?></span>
        <?php if (!empty($counts[$s['key']])): ?>
        <span class="set-rail-count"><?= (int)$counts[$s['key']] ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    </div>
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
