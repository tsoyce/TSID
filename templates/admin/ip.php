<?php /**
 * Страница адреса (v4.72): кто с него приходил, что делал, заблокирован ли.
 * $ip, $block, $users, $requests, $audit, $mine.
 */ ?>
<div class="page-head">
  <h1><?= esc(t('Адрес :ip', ['ip' => $ip])) ?></h1>
  <?php if ($block !== null): ?><span class="badge badge-danger"><?= esc(t('заблокирован')) ?></span><?php endif; ?>
</div>

<div class="bento">
  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Доступ')) ?></div>
    <?php if ($block !== null): ?>
      <p><?= esc(t('Закрыт правилом :rule.', ['rule' => $block['rule']])) ?>
        <?= $block['reason'] ? esc(t('Причина: :r.', ['r' => $block['reason']])) : '' ?></p>
      <p class="hint"><?= esc(t('Попыток после блокировки: :n.', ['n' => (int)$block['hits']])) ?></p>
      <form method="post" action="/admin/blocks/<?= (int)$block['id'] ?>/remove"
            data-confirm="<?= esc(t('Снять блокировку :rule?', ['rule' => $block['rule']])) ?>">
        <?= csrf_field() ?><?= back_field() ?>
        <button class="btn btn-secondary" type="submit"><?= esc(t('Снять блокировку')) ?></button>
      </form>
    <?php elseif ($mine): ?>
      <p class="hint"><?= esc(t('Это ваш собственный адрес — заблокировать его нельзя.')) ?></p>
    <?php else: ?>
      <?php /* Одна кнопка, а не переход в настройки: решение принимают здесь,
               глядя на то, что с адреса делали. */ ?>
      <form method="post" action="/admin/blocks">
        <?= csrf_field() ?><?= back_field() ?>
        <input type="hidden" name="rule" value="<?= esc($ip) ?>">
        <?= field_text('reason', t('Причина'), '', ['max' => 255, 'placeholder' => t('Для себя: кто это и почему')]) ?>
        <?= field_select('days', t('На сколько'), [
              '0' => t('Навсегда'), '1' => t('Сутки'), '7' => t('Неделю'), '30' => t('Месяц'), '365' => t('Год'),
            ], '0') ?>
        <button class="btn btn-danger btn-block" type="submit"><?= icon('lock', 16) ?> <?= esc(t('Заблокировать адрес')) ?></button>
      </form>
      <p class="hint mt-8"><a href="/admin/blocks?ip=<?= esc(rawurlencode((string)(App\Core\BotSignal::subnet(ip_bin($ip)) ?? $ip))) ?>"><?= esc(t('Закрыть всю подсеть →')) ?></a></p>
    <?php endif; ?>
  </div>

  <div class="bento-card col-8">
    <div class="bc-title"><?= esc(t('Кто приходил с этого адреса')) ?></div>
    <?php if ($users === []): ?>
      <p class="hint mb-0"><?= esc(t('Ни один вошедший аккаунт с этого адреса не замечен — только анонимные запросы.')) ?></p>
    <?php else: ?>
    <div class="table-wrap borderless">
      <table class="table">
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><a href="/admin/users/<?= (int)$u['id'] ?>"><?= esc(user_name($u)) ?></a>
              <div class="hint"><?= esc((string)($u['email'] ?? ('@' . $u['login']))) ?></div></td>
            <td class="hint"><?= (int)$u['n'] ?> <?= esc(plural((int)$u['n'], 'запись', 'записи', 'записей')) ?></td>
            <td class="hint nowrap"><?= esc(fmt_ago((string)$u['last'])) ?></td>
            <td><?php if ($u['status'] === 'blocked'): ?><span class="badge badge-danger"><?= esc(t('заблокирован')) ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('События аудита')) ?></div>
    <?php if ($audit === []): ?>
      <p class="hint mb-0"><?= esc(t('Событий с этого адреса нет.')) ?></p>
    <?php else: ?>
    <div class="table-wrap borderless">
      <table class="table">
        <tbody>
        <?php foreach ($audit as $a): ?>
          <tr>
            <td class="hint nowrap"><?= esc(fmt_dt((string)$a['created_at'], 'd.m H:i:s')) ?></td>
            <td><?= event_label((string)$a['event']) ?></td>
            <td class="hint"><?= esc((string)($a['on_email'] ?? $a['actor_email'] ?? '—')) ?></td>
            <td class="hint cell-clip"><?= esc(App\Core\Audit::metaText($a['meta'] ?? null)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Запросы')) ?></div>
    <?= App\Core\View::fetch('admin/_activity_table', ['rows' => $requests, 'showUser' => true, 'showIp' => false]) ?>
    <p class="hint mt-8"><a href="/admin/logs/requests?ip=<?= esc(rawurlencode($ip)) ?>"><?= esc(t('Все запросы с адреса →')) ?></a></p>
  </div>
</div>
