<?php /**
 * Применение миграций. Намеренно ничего не знает про таблицы новее 001:
 * эта страница обязана открываться именно тогда, когда остальные не открываются.
 */ ?>
<div class="page-head"><h1><?= esc(t('Миграции')) ?></h1></div>

<div class="bento-card mw-lg">
  <?php if ($pending === []): ?>
    <?= callout(t('Схема базы совпадает с кодом — применять нечего.'), 'success') ?>
  <?php else: ?>
    <?= callout(t('Код обновлён, а схема базы отстаёт. Пока миграции не применены, разделы, '
        . 'которым нужны новые таблицы, будут отдавать ошибку. Применение безопасно: '
        . 'каждая миграция выполняется один раз и записывается в журнал.'), 'warning') ?>

    <div class="bc-title mt-24"><?= esc(t('Не применено')) ?></div>
    <div class="stack">
      <?php foreach ($pending as $v): ?>
        <div class="row-between">
          <span class="mono"><?= esc($v) ?></span>
          <span class="badge badge-warn"><?= esc(t('ожидает')) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="post" action="/admin/migrate" class="mt-24">
      <?= csrf_field() ?>
      <button class="btn btn-primary" type="submit"><?= esc(t('Применить миграции')) ?></button>
    </form>
  <?php endif; ?>

  <?php if ($applied !== []): ?>
  <div class="bc-title mt-24"><?= esc(t('Уже применены')) ?></div>
  <div class="row gap-4">
    <?php foreach ($applied as $v): ?>
      <span class="badge badge-muted mono"><?= esc($v) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <p class="hint mt-16">
    <?= esc(t('Если админка недоступна целиком, те же миграции применяются из консоли:')) ?>
    <code>php migrate.php</code>
  </p>
</div>
