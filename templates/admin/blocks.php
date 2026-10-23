<?php /**
 * Адреса (v4.95; блокировки — с v4.72). $nets, $blocks, $myIp, $days.
 *
 * Живой случай 23.10: боты шли с девяти подсетей, каждый раз с нового адреса,
 * и закрывать их приходилось по одному со страницы адреса — как удалять сто
 * аккаунтов по одному. Теперь сверху — подсети, откуда шли регистрации без
 * подтверждения, отказы ботам и неудачные входы, с галочками; ниже — правила,
 * их тоже снимают пачкой; форма для одиночного адреса — последней.
 */
$dayOptions = ['0' => t('Навсегда'), '1' => t('Сутки'), '7' => t('Неделю'), '30' => t('Месяц'), '365' => t('Год')]; ?>
<div class="page-head">
  <h1><?= esc(t('Адреса')) ?></h1>
  <a class="btn btn-secondary" href="/admin/users/bots"><?= esc(t('Похоже на ботов')) ?></a>
</div>

<?= logs_tabs('blocks') ?>

<div class="bento-card">
  <div class="bc-title"><?= esc(t('Откуда шли боты и подбор — за :n :days', ['n' => $days, 'days' => plural($days, 'день', 'дня', 'дней')])) ?></div>
  <?php if ($nets === []): ?>
    <?= empty_state('shield-check', t('Ни регистраций без подтверждения, ни отказов, ни неудачных входов.')) ?>
  <?php else: ?>
  <p class="hint mb-12"><?= esc(t('По подсетям: /24 для IPv4, /48 для IPv6 — столько хостинг обычно выдаёт одному клиенту. Галочка стоит там, откуда ни один человек не подтвердил почту.')) ?></p>
  <form method="post" action="/admin/blocks/bulk" data-bulk
        data-confirm-tpl="<?= esc(t('Закрыть выбранные подсети (:n)? С них не откроется ни одна страница.')) ?>">
    <?= csrf_field() ?>
    <div class="table-wrap borderless">
    <table class="table table-cards">
      <thead><tr>
        <th class="cell-check"><input type="checkbox" data-check-all aria-label="<?= esc(t('Выбрать все')) ?>"></th>
        <th><?= esc(t('Подсеть')) ?></th><th><?= esc(t('Без подтверждения')) ?></th><th><?= esc(t('Отказано ботам')) ?></th>
        <th><?= esc(t('Неудачных входов')) ?></th><th><?= esc(t('Последний раз')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($nets as $n):
        $pick = !$n['blocked'] && !$n['mine'] && $n['regs'] === $n['unconfirmed'] && $n['unconfirmed'] + $n['refused'] > 0; ?>
        <tr<?= $n['blocked'] ? ' class="is-dimmed"' : '' ?>>
          <td class="cell-check"><?php if (!$n['blocked'] && !$n['mine']): ?><input type="checkbox" name="nets[]" value="<?= esc($n['net']) ?>"
              aria-label="<?= esc($n['net']) ?>"<?= $pick ? ' checked' : '' ?>><?php endif; ?></td>
          <td data-label="<?= esc(t('Подсеть')) ?>" class="cell-primary"><span class="mono"><?= esc($n['net']) ?></span>
            <?php if ($n['blocked']): ?><span class="badge badge-muted"><?= esc(t('закрыта')) ?></span><?php endif; ?>
            <?php if ($n['mine']): ?><span class="badge badge-ink"><?= esc(t('ваша')) ?></span><?php endif; ?></td>
<?php /* Ноль — пустой ячейкой: на телефоне пустая строка карточки прячется,
             и подсеть занимает одну-две строки вместо четырёх. */ ?>
          <td data-label="<?= esc(t('Без подтверждения')) ?>"><?php if ($n['unconfirmed'] > 0): ?><span><?= $n['unconfirmed'] ?><?php if ($n['regs'] > $n['unconfirmed']): ?> <span class="hint"><?= esc(t('из :n', ['n' => $n['regs']])) ?></span><?php endif; ?></span><?php endif; ?></td>
          <td data-label="<?= esc(t('Отказано ботам')) ?>"><?= $n['refused'] > 0 ? $n['refused'] : '' ?></td>
          <td data-label="<?= esc(t('Неудачных входов')) ?>"><?= $n['fails'] > 0 ? $n['fails'] : '' ?></td>
          <td data-label="<?= esc(t('Последний раз')) ?>" class="hint nowrap"><?= esc(fmt_ago($n['last'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="bulk-bar mt-12">
      <span class="hint" data-bulk-count data-tpl="<?= esc(t('Выбрано: :n')) ?>"></span>
      <?= field_select('days', t('На сколько'), $dayOptions, '0', ['class' => 'field-narrow']) ?>
      <button class="btn btn-primary" type="submit"><?= esc(t('Закрыть выбранные')) ?></button>
    </div>
  </form>
  <?php endif; ?>
</div>

<div class="bento-card">
  <div class="bc-title"><?= esc(t('Правила')) ?> <span class="muted fw-400"><?= count($blocks) ?></span></div>
  <?php if ($blocks === []): ?>
    <?= empty_state('shield', t('Ни один адрес не заблокирован. Адреса из журналов открываются своей страницей — там кнопка «Заблокировать».')) ?>
  <?php else: ?>
  <form method="post" action="/admin/blocks/remove" data-bulk
        data-confirm-tpl="<?= esc(t('Снять выбранные блокировки (:n)?')) ?>">
    <?= csrf_field() ?>
    <div class="table-wrap borderless">
    <table class="table table-cards">
      <thead><tr><th class="cell-check"><input type="checkbox" data-check-all aria-label="<?= esc(t('Выбрать все')) ?>"></th>
        <th><?= esc(t('Адрес')) ?></th><th><?= esc(t('Причина')) ?></th><th><?= esc(t('Срок')) ?></th><th><?= esc(t('Попаданий')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($blocks as $b):
        $expired = $b['expires_at'] !== null && strtotime($b['expires_at'] . ' UTC') <= time(); ?>
        <tr<?= $expired ? ' class="is-dimmed"' : '' ?>>
          <td class="cell-check"><input type="checkbox" name="ids[]" value="<?= (int)$b['id'] ?>" aria-label="<?= esc((string)$b['rule']) ?>"></td>
          <td data-label="<?= esc(t('Адрес')) ?>" class="cell-primary">
            <?php if (!str_contains((string)$b['rule'], '/')): ?>
              <a class="mono" href="/admin/ip/<?= esc(rawurlencode((string)$b['rule'])) ?>"><?= esc($b['rule']) ?></a>
            <?php else: ?><span class="mono"><?= esc($b['rule']) ?></span><?php endif; ?>
            <div class="hint"><?= esc(t(':when · :who', ['when' => fmt_dt((string)$b['created_at'], 'd.m.Y'),
                'who' => (string)($b['by_email'] ?? t('система'))])) ?></div>
          </td>
          <td data-label="<?= esc(t('Причина')) ?>" class="hint"><?= esc((string)($b['reason'] ?? '—')) ?></td>
          <td data-label="<?= esc(t('Срок')) ?>" class="hint"><?= $b['expires_at'] === null ? esc(t('навсегда'))
              : ($expired ? esc(t('истёк')) : esc(t('до :d', ['d' => fmt_dt((string)$b['expires_at'], 'd.m.Y H:i')]))) ?></td>
          <td data-label="<?= esc(t('Попаданий')) ?>"><?= (int)$b['hits'] ?>
            <?php if ($b['last_hit_at']): ?><div class="hint"><?= esc(fmt_ago((string)$b['last_hit_at'])) ?></div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="bulk-bar mt-12">
      <span class="hint" data-bulk-count data-tpl="<?= esc(t('Выбрано: :n')) ?>"></span>
      <button class="btn btn-secondary" type="submit"><?= esc(t('Снять выбранные')) ?></button>
    </div>
  </form>
  <?php endif; ?>
</div>

<form method="post" action="/admin/blocks" class="bento-card">
  <?= csrf_field() ?>
  <div class="bc-title"><?= esc(t('Закрыть адрес')) ?></div>
  <div class="grid-2">
    <?= field_text('rule', t('Адрес или подсеть'), (string)($_GET['ip'] ?? ''), [
          'mono' => true, 'required' => true, 'max' => 64, 'placeholder' => t(':ip или :net', ['ip' => '198.51.100.66', 'net' => '198.51.100.0/24']),
          'hint' => t('IPv4 или IPv6. Подсеть — через «/»: не шире /8 для IPv4 и /32 для IPv6.')]) ?>
    <?= field_select('days', t('На сколько'), $dayOptions, '0') ?>
  </div>
  <?= field_text('reason', t('Причина'), '', ['max' => 255, 'placeholder' => t('Для себя: кто это и почему'),
        'hint' => t('Видна только здесь. Тому, кого закрыли, покажем одно: «с этого адреса доступ закрыт».')]) ?>
  <p class="hint"><?= esc(t('Ваш адрес сейчас — :ip. Заблокировать его не получится: вы заперли бы себя.', ['ip' => $myIp])) ?></p>
  <?= form_actions(t('Заблокировать')) ?>
</form>
