<?php /** Почтовая очередь (§5.2): pending/sent/failed, повтор, просмотр. */ ?>
<div class="page-head">
  <h1><?= esc(t('Почта')) ?></h1>
  <?php /* Очередь — это очередь, а не архив: отправленное письмо в ней больше
           ни для чего не нужно. Упавшие и ждущие не трогаем — по ним видно,
           что чинить. */ ?>
  <?php if (App\Core\Auth::can('housekeeping')): ?>
  <form method="post" action="/admin/logs/mail/purge"
        data-confirm="<?= esc(t('Убрать отправленные письма старше недели? Ждущие и упавшие останутся.')) ?>">
    <?= csrf_field() ?><input type="hidden" name="keep_days" value="7">
    <button class="btn btn-secondary" type="submit"><?= esc(t('Убрать отправленные')) ?></button>
  </form>
  <?php endif; ?>
</div>

<?= logs_tabs('mail') ?>

<?php if ($cronStale): ?>
<div class="secret-plate mb-16"><?= icon('alert-triangle', 16) ?>
<?= esc(t('Крон не отчитывался больше 10 минут — очередь писем некому разгребать. Немедленная отправка делает одну попытку; всё, что не ушло с первого раза, ждёт крон. Проверьте планировщик — строка есть в разделе')) ?>
  <a href="/admin/health"><?= esc(t('«Здоровье»')) ?></a>.</div>
<?php endif; ?>

<div class="row mb-16">
  <a class="btn btn-s <?= $status === '' ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/logs/mail"><?= esc(t('Все')) ?></a>
  <a class="btn btn-s <?= $status === 'pending' ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/logs/mail?status=pending"><?= esc(t('В очереди')) ?> (<?= $counts['pending'] ?>)</a>
  <a class="btn btn-s <?= $status === 'sent' ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/logs/mail?status=sent"><?= esc(t('Отправлено')) ?> (<?= $counts['sent'] ?>)</a>
  <a class="btn btn-s <?= $status === 'failed' ? 'btn-primary' : 'btn-secondary' ?>" href="/admin/logs/mail?status=failed"><?= esc(t('Провалено')) ?> (<?= $counts['failed'] ?>)</a>
  <?php if ($counts['failed'] > 0): ?>
  <form method="post" action="/admin/logs/mail/retry-all">
    <?= csrf_field() ?>
    <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Повторить все неотправленные')) ?></button>
  </form>
  <?php endif; ?>
</div>

<?php if ($viewMail): ?>
<div class="bento-card mb-16">
  <div class="bc-title"><?= esc(t('Письмо №:n', ['n' => (int)$viewMail['id']])) ?> → <?= esc($viewMail['to_email']) ?></div>
  <div class="cell-main mb-8"><?= esc($viewMail['subject']) ?></div>
  <div class="code-block code-scroll"><?= esc(\App\Core\Mailer::redact((string)$viewMail['body_text'])) ?></div>
  <div class="bc-footer"><a href="/admin/logs/mail"><?= esc(t('Закрыть')) ?></a></div>
</div>
<?php endif; ?>

<div class="table-wrap">
<table class="table">
  <thead><tr><th><?= esc(t('Кому')) ?></th><th><?= esc(t('Тема')) ?></th><th><?= esc(t('Статус')) ?></th><th><?= esc(t('Попыток')) ?></th><th><?= esc(t('Создано')) ?></th><th><?= esc(t('Ошибка')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $m): ?>
  <tr>
    <td class="cell-main"><?= esc($m['to_email']) ?></td>
    <td><a href="/admin/logs/mail?view=<?= (int)$m['id'] ?><?= $status ? '&status=' . esc($status) : '' ?>"><?= esc($m['subject']) ?></a></td>
    <td><?= mail_status_badge((string)$m['status']) ?></td>
    <td class="hint"><?= (int)$m['attempts'] ?></td>
    <td class="hint nowrap"><?= esc(fmt_dt($m['created_at'], 'd.m H:i')) ?></td>
    <td class="hint cell-clip"><?= esc($m['last_error'] ?? '') ?></td>
    <td>
      <?php if ($m['status'] !== 'sent'): ?>
      <form method="post" action="/admin/logs/mail/<?= (int)$m['id'] ?>/retry">
        <?= csrf_field() ?>
        <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Повторить')) ?></button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="7"><?= empty_state('mail', t('Писем нет.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
