<?php /** $code, $title, $message, $incident, $retry_after, $delivery */ ?>
<div class="text-center">
  <div class="mono error-code"><?= (int)$code ?></div>
  <h1 class="fs-22"><?= esc(t($title)) ?></h1>
  <p class="muted"><?= esc(t($message)) ?></p>
  <?php if (!empty($retry_after)): ?>
    <p class="muted"><?= esc(t('Попробуйте через :n :unit.', [
      'n' => (int)$retry_after,
      'unit' => plural((int)$retry_after, 'секунду', 'секунды', 'секунд'),
    ])) ?></p>
  <?php endif; ?>
  <?php if (!empty($incident)): ?>
    <p class="hint"><?= esc(t('ID инцидента:')) ?> <code><?= esc($incident) ?></code>
      <?= esc(t('— назовите его администратору, событие найдётся в логах.')) ?></p>
    <?php /* Администратору — сразу ссылка на само событие, а не совет искать.
             Обычному человеку она не видна: обещание «найдётся в логах» держит
             тот, у кого есть логи. */ ?>
    <?php if (App\Core\Auth::check() && App\Core\Auth::can('logs.read')): ?>
    <p class="hint"><a href="/admin/logs/errors?incident=<?= esc($incident) ?>">
      <?= esc(t('Открыть это событие в журнале')) ?></a></p>
    <?php endif; ?>
  <?php endif; ?>
  <?php /* Причина названа прямо здесь, когда она известна: отсылать за ней в
           журнал бессмысленно, если недоехал файл самого журнала. Ровно так и
           случилось — страница ошибки вела на страницу, которая падала. */ ?>
  <?php if (!empty($delivery)): ?>
    <div class="mt-16 bordered-ink pad-16 text-left">
      <p><strong><?= esc(t('Похоже, файл не доехал при заливке')) ?></strong></p>
      <p class="mono fs-13"><?= esc($delivery['file']) ?></p>
      <?php if ((int)$delivery['others'] > 0): ?>
        <p class="hint"><?= esc(t('И ещё файлов недостаёт: :n. Недоехавший файл редко бывает один.',
          ['n' => (int)$delivery['others']])) ?></p>
      <?php endif; ?>
      <p class="hint"><?= esc(t('Залейте архив целиком поверх — конфигурацию и storage он не трогает.')) ?>
        <a href="/admin/health/delivery"><?= esc(t('Проверить поставку целиком')) ?></a></p>
    </div>
  <?php endif; ?>
  <div class="mt-16">
    <a class="btn btn-secondary" href="/"><?= esc(t('error.home')) ?></a>
  </div>
</div>
