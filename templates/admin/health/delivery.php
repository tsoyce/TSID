<?php /**
 * Всё ли доехало при заливке.
 *
 * Заливка обрывается тихо: часть файлов на месте, часть нет, часть осталась от
 * прошлой версии — установка при этом выглядит рабочей. Так на боевой пропал
 * класс соцвхода и уронил экран входа, а следом не доехал шаблон главной.
 *
 * $delivery: known, ok, version, total, missing[], changed[]
 */ ?>
<?php if (!$delivery['known']): ?>
  <?= empty_state('info', t('Описи поставки нет — установка развёрнута из репозитория, а не из архива.'),
      '', 'pad-16') ?>
  <p class="hint mt-8"><?= esc(t('Опись кладётся в архив сборщиком (dist-manifest.json) и позволяет за секунду сказать, всё ли доехало. У копии из git её нет, и это нормально.')) ?></p>
<?php else: ?>

<?php if ($delivery['ok']): ?>
<div class="bento-card">
  <div class="health-row">
    <span class="health-icon"><?= status_icon('ok') ?></span>
    <div>
      <div class="cell-main"><?= esc(t('Все файлы на месте')) ?></div>
      <div class="hint"><?= esc(t('файлов по описи: :n, версия :v',
        ['n' => $delivery['total'], 'v' => $delivery['version'] ?? '—'])) ?></div>
    </div>
  </div>
</div>
<?php else: ?>
<div class="bento-card bordered-ink">
  <div class="bc-title"><?= esc(t('Поставка неполная')) ?></div>
  <p class="text-danger"><?= esc(t('Не хватает файлов: :n. Долейте их — приложение будет падать при первом же обращении к недостающему.',
      ['n' => count($delivery['missing'])])) ?></p>
  <ul class="mono fs-13">
    <?php foreach (array_slice($delivery['missing'], 0, 60) as $f): ?>
    <li><?= esc($f) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if (count($delivery['missing']) > 60): ?>
  <p class="hint"><?= esc(t('…и ещё :n', ['n' => count($delivery['missing']) - 60])) ?></p>
  <?php endif; ?>
  <p class="hint mt-8"><?= esc(t('Проще всего залить архив целиком поверх: файлы конфигурации и storage опись не трогает.')) ?></p>
</div>
<?php endif; ?>

<?php /* Расхождение по размеру — отдельной карточкой и жёлтым: у того, кто
         правил шаблон под себя, всё работает. Но ровно так же выглядит файл,
         оставшийся от прошлой версии, — а это значит, что лечения в нём нет,
         хотя версия в подвале уже новая. Различить их снаружи нельзя, поэтому
         показываем и объясняем оба случая. */ ?>
<?php if ($delivery['changed'] !== []): ?>
<div class="bento-card mt-16">
  <div class="bc-title"><?= esc(t('Размер не совпал с описью')) ?></div>
  <p><?= esc(t('Файлов: :n. Либо они правлены на месте — тогда всё в порядке, — либо остались от прошлой версии: заливка оборвалась, и в них нет того, что чинилось.',
      ['n' => count($delivery['changed'])])) ?></p>
  <ul class="mono fs-13">
    <?php foreach (array_slice($delivery['changed'], 0, 40) as $f): ?>
    <li><?= esc($f) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if (count($delivery['changed']) > 40): ?>
  <p class="hint"><?= esc(t('…и ещё :n', ['n' => count($delivery['changed']) - 40])) ?></p>
  <?php endif; ?>
  <p class="hint mt-8"><?= esc(t('Если своих правок вы не делали — залейте архив поверх ещё раз.')) ?></p>
</div>
<?php endif; ?>

<?php endif; ?>
