<?php /**
 * О сервисе. Сюда вынесено всё, что относится к самому движку, а не к
 * конкретной инсталляции: версия, происхождение, лицензия. В интерфейсе таким
 * сведениям места нет — они интересны один раз, а мешают каждый день.
 */ ?>
<div class="page-head"><h1><?= esc(t('О сервисе')) ?></h1></div>

<div class="bento">
  <div class="bento-card col-7">
    <div class="bc-title"><?= esc(t('Инсталляция')) ?></div>
    <?= kv_list([
        t('Название') => App\Core\Setting::appName(),
        t('Адрес') => base_url(),
        t('Версия движка') => SSO_VERSION,
        'PHP' => PHP_VERSION,
    ]) ?>
    <p class="hint mt-16">
      <?= esc(t('Название, логотип, цвет и текст подвала настраиваются в разделе «Настройки» — движок не навязывает своего имени.')) ?>
    </p>
  </div>

  <div class="bento-card col-5">
    <div class="bc-title"><?= esc(t('Движок')) ?></div>
    <p class="muted"><?= esc(t('SSO — открытый OpenID-провайдер на PHP: один аккаунт на сеть сайтов, без Composer и Node. Лицензия MIT: можно использовать, дорабатывать и разворачивать у себя.')) ?></p>
    <div class="stack mt-16">
      <a class="btn btn-secondary" href="https://github.com/" rel="noopener" target="_blank">
        <?= icon('external', 16) ?> <?= esc(t('Исходный код')) ?>
      </a>
    </div>
  </div>

  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Что изменилось')) ?></div>
    <?php if ($releases === []): ?>
      <div class="hint"><?= esc(t('Список изменений недоступен — файл CHANGELOG.md не найден.')) ?></div>
    <?php else: ?>
      <?php /* Лента, как в логах: строка — событие, клик — подробности.
               Сводка в строке, полный текст релиза в окне. Раньше здесь была
               та же сводка, но открыть подробности было негде, и человек шёл
               читать CHANGELOG.md на диске. */ ?>
      <div class="stack">
        <?php foreach ($releases as $r): $rid = 'rel-' . preg_replace('~[^0-9]~', '-', $r['version']); ?>
        <button class="release-row" type="button" data-modal-open="<?= esc($rid) ?>">
          <div class="row-between">
            <span class="cell-main"><?= esc($r['version']) ?>
              <?php if ($r['title'] !== ''): ?><span class="muted">· <?= esc($r['title']) ?></span><?php endif; ?>
            </span>
            <span class="hint nowrap"><?= esc($r['date']) ?> <?= icon('chevron-right', 14, 'icon-muted') ?></span>
          </div>
          <?php $rsum = App\Core\Changelog::summary($r); if ($rsum !== ''): ?>
            <div class="hint mt-4 text-left"><?= esc($rsum) ?></div>
          <?php endif; ?>
        </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php /* Документы. Раньше жили строкой в подвале каждой страницы — место
         занимали всегда, открывали их раз в жизни. Здесь у них своё место,
         а в подвале осталась одна ссылка сюда. */ ?>
<?php $aboutLegal = legal_links(); if ($aboutLegal !== []): ?>
<div class="bento mt-16" id="legal">
  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Документы')) ?></div>
    <p class="muted"><?= esc(t('Правовые документы этой инсталляции. Редакция и дата изменения — на странице каждого.')) ?></p>
    <div class="stack mt-16">
      <?php foreach ($aboutLegal as $legalTitle => $legalHref): ?>
      <div class="row-between">
        <a class="cell-main" href="<?= esc($legalHref) ?>"><?= esc($legalTitle) ?></a>
        <span class="hint"><?= icon('arrow-right', 15, 'icon-muted') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php /* Подробности релиза: по окну на каждый. Тело идёт через тот же
         Markdown, что и правовые документы, — он экранирует сначала и
         размечает потом, поэтому разметка из файла не станет тегами. */ ?>
<?php foreach ($releases as $r): $rid = 'rel-' . preg_replace('~[^0-9]~', '-', $r['version']); ?>
<div class="modal-backdrop" id="<?= esc($rid) ?>">
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal-head">
      <h2 class="mb-0"><?= esc($r['version']) ?><?php if ($r['title'] !== ''): ?>
        <span class="muted fw-400">· <?= esc($r['title']) ?></span><?php endif; ?></h2>
      <button class="btn-icon btn-icon-s" type="button" data-modal-close
              aria-label="<?= esc(t('Закрыть')) ?>"><?= icon('x', 18) ?></button>
    </div>
    <?php if ($r['date'] !== ''): ?><div class="hint mb-16"><?= esc($r['date']) ?></div><?php endif; ?>
    <div class="legal-doc"><?= App\Core\Changelog::html($r) ?></div>
  </div>
</div>
<?php endforeach; ?>
