<?php /** Дашборд (§5.2): KPI, график, аудит, здоровье. */
use App\Controllers\Admin\DashboardController; ?>
<div class="page-head"><h1><?= esc(t('Дашборд')) ?></h1></div>

<?php if (!$onboarding['hidden'] && $onboarding['steps'] !== []): ?>
<div class="bento-card mb-16 bordered-ink-soft">
  <div class="row-between mb-16">
    <div>
      <div class="bc-title mb-4"><?= esc(t('Первые шаги · :done из :total', ['done' => (int)$onboarding['done'], 'total' => (int)$onboarding['total']])) ?></div>
      <div class="score-bar score-bar-fixed"><i style="width:<?= (int)round($onboarding['done'] / max(1, $onboarding['total']) * 100) ?>%"></i></div>
    </div>
    <form method="post" action="/admin/onboarding/hide" data-confirm="<?= esc(t('Скрыть чеклист? Вернуть его можно, очистив настройку onboarding.hidden.')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-ghost btn-s" type="submit"><?= esc(t('Скрыть')) ?></button>
    </form>
  </div>
  <div class="onboarding-grid">
    <?php foreach ($onboarding['steps'] as $s): ?>
    <div class="onboarding-step<?= $s['done'] ? ' is-done' : '' ?>">
      <span class="onboarding-mark">
        <?= $s['done'] ? icon('check-circle', 20, 'icon-ok') : icon($s['icon'], 20, 'icon-muted') ?>
      </span>
      <div class="flex-1">
        <div class="cell-main"><?= esc($s['label']) ?></div>
        <div class="hint"><?= esc($s['hint']) ?></div>
      </div>
      <?php if (!$s['done']): ?>
        <a class="btn btn-s btn-secondary" href="<?= esc($s['url']) ?>"><?= esc($s['cta']) ?></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="bento">
  <div class="bento-card col-3"><div class="bc-title"><?= esc(t('Пользователи')) ?></div>
    <div class="kpi-value"><?= number_format($kpi['users_total'], 0, '', ' ') ?></div>
    <div class="kpi-sub">+<?= (int)$kpi['users_new_7d'] ?> <?= esc(t('за 7 дней')) ?></div>
    <?php /* Кривая у каждой плитки (v4.84): низ трёх из четырёх стоял пустым. */ ?>
    <div class="sparkline"><?= DashboardController::sparkSvg($regSpark, 120, 36, t('Регистрации за 14 дней')) ?></div></div>
  <div class="bento-card col-3"><div class="bc-title"><?= esc(t('Входы сегодня')) ?></div>
    <div class="kpi-value"><?= number_format($kpi['logins_today'], 0, '', ' ') ?></div>
    <div class="kpi-sub"><?= esc(t('успешные · за 14 дней: :n', ['n' => (int)array_sum($loginSpark)])) ?></div>
    <div class="sparkline"><?= DashboardController::sparkSvg($loginSpark, 120, 36, t('Входы за 14 дней')) ?></div></div>
  <div class="bento-card col-3"><div class="bc-title"><?= esc(t('Неудачные входы')) ?></div>
    <div class="kpi-value <?= $kpi['fails_today'] > 20 ? 'text-danger' : '' ?>"><?= number_format($kpi['fails_today'], 0, '', ' ') ?></div>
    <?php /* Подпись, как у соседних плиток (v4.70): голое число над кривой не
             говорило, за какой срок оно и что за линия под ним. */ ?>
    <div class="kpi-sub"><?= esc(t('сегодня · за 14 дней: :n', ['n' => (int)array_sum($failSpark)])) ?></div>
    <div class="sparkline"><?= DashboardController::sparkSvg($failSpark, 120, 36, t('Неудачные входы за 14 дней')) ?></div></div>
  <div class="bento-card col-3"><div class="bc-title"><?= esc(t('Сейчас в сети')) ?></div>
    <div class="kpi-value"><?= number_format($kpi['sessions_active'], 0, '', ' ') ?></div>
    <?php /* Число — живые сессии, кривая — новые по дням (v4.98): подпись
             говорит про обе, иначе кривая читалась как «онлайн по дням». */ ?>
    <div class="kpi-sub"><?= esc(t('активных сессий · кривая — новые за день')) ?></div>
    <div class="sparkline"><?= DashboardController::sparkSvg($sessionSpark, 120, 36, t('Новые сессии за 14 дней')) ?></div></div>

  <div class="bento-card col-8">
    <div class="bc-title"><?= esc(t('Регистрации за 30 дней')) ?></div>
    <div class="chart"><?= DashboardController::chartSvg($registrations, 720, 180, t('Регистрации за 30 дней')) ?></div>
    <div class="row-between hint mt-8">
      <span><?= esc(fmt_dt(array_key_first($registrations) . ' 00:00:00', 'd.m')) ?></span>
      <span><?= esc(t('сегодня')) ?></span>
    </div>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Здоровье')) ?></div>
    <div class="stack">
    <?php foreach ($health as $h): ?>
      <div class="row gap-8">
        <?= status_icon($h['status'], 18) ?>
        <span><?= esc($h['label']) ?></span>
      </div>
    <?php endforeach; ?>
    </div>
    <div class="bc-footer"><a href="/admin/health"><?= esc(t('Подробно →')) ?></a></div>
  </div>

  <div class="bento-card col-8">
    <div class="bc-title"><?= esc(t('Последние события')) ?></div>
    <div class="table-wrap borderless">
      <table class="table">
        <tbody>
        <?php foreach ($audit as $a): ?>
        <tr>
          <td class="hint nowrap"><?= esc(fmt_dt($a['created_at'], 'd.m H:i')) ?></td>
          <td><?= event_label($a['event']) ?></td>
          <td class="hint"><?= esc($a['on_email'] ?? $a['actor_email'] ?? '—') ?></td>
          <td class="hint"><?= esc($a['client_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="bc-footer"><a href="/admin/logs"><?= esc(t('Весь аудит →')) ?></a></div>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Быстрые действия')) ?></div>
    <div class="stack">
      <a class="btn btn-secondary btn-block" href="/admin/clients#открыть-wizard"><?= esc(t('Подключить сервис')) ?></a>
      <a class="btn btn-secondary btn-block" href="/admin/users"><?= esc(t('Найти пользователя')) ?></a>
      <a class="btn btn-secondary btn-block" href="/admin/tools"><?= esc(t('Импорт пользователей')) ?></a>
      <a class="btn btn-secondary btn-block" href="/admin/logs/mail"><?= esc(t('Почтовая очередь')) ?></a>
    </div>
  </div>
</div>
