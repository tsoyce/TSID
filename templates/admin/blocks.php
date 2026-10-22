<?php /**
 * Блокировки адресов (v4.72). $blocks, $myIp.
 *
 * Сверху — форма: адрес или подсеть, причина, срок. Ниже — действующие
 * правила с числом попаданий: по нему видно, стучится ли ещё тот, кого
 * закрыли, и не пора ли снять.
 */ ?>
<form method="post" action="/admin/blocks" class="bento-card">
  <?= csrf_field() ?>
  <div class="bc-title"><?= esc(t('Закрыть адрес')) ?></div>
  <div class="grid-2">
    <?= field_text('rule', t('Адрес или подсеть'), (string)($_GET['ip'] ?? ''), [
          'mono' => true, 'required' => true, 'max' => 64, 'placeholder' => t(':ip или :net', ['ip' => '198.51.100.66', 'net' => '198.51.100.0/24']),
          'hint' => t('IPv4 или IPv6. Подсеть — через «/»: не шире /8 для IPv4 и /32 для IPv6.')]) ?>
    <?= field_select('days', t('На сколько'), [
          '0' => t('Навсегда'), '1' => t('Сутки'), '7' => t('Неделю'), '30' => t('Месяц'), '365' => t('Год'),
        ], '0') ?>
  </div>
  <?= field_text('reason', t('Причина'), '', ['max' => 255, 'placeholder' => t('Для себя: кто это и почему'),
        'hint' => t('Видна только здесь. Тому, кого закрыли, покажем одно: «с этого адреса доступ закрыт».')]) ?>
  <p class="hint"><?= esc(t('Ваш адрес сейчас — :ip. Заблокировать его не получится: вы заперли бы себя.', ['ip' => $myIp])) ?></p>
  <?= form_actions(t('Заблокировать')) ?>
</form>

<div class="bento-card">
  <div class="bc-title"><?= esc(t('Правила')) ?></div>
  <?php if ($blocks === []): ?>
    <?= empty_state('shield', t('Ни один адрес не заблокирован. Адреса из журналов открываются своей страницей — там кнопка «Заблокировать».')) ?>
  <?php else: ?>
  <div class="table-wrap borderless">
    <table class="table table-cards">
      <thead><tr><th><?= esc(t('Адрес')) ?></th><th><?= esc(t('Причина')) ?></th><th><?= esc(t('Срок')) ?></th>
        <th><?= esc(t('Попаданий')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($blocks as $b):
        $expired = $b['expires_at'] !== null && strtotime($b['expires_at'] . ' UTC') <= time(); ?>
        <tr<?= $expired ? ' class="is-dimmed"' : '' ?>>
          <td data-label="<?= esc(t('Адрес')) ?>">
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
          <td>
            <form method="post" action="/admin/blocks/<?= (int)$b['id'] ?>/remove"
                  data-confirm="<?= esc(t('Снять блокировку :rule?', ['rule' => $b['rule']])) ?>">
              <?= csrf_field() ?>
              <button class="btn btn-s btn-secondary" type="submit"><?= esc(t('Снять')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
