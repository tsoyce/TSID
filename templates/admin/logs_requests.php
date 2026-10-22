<?php /**
 * Журнал активности — каждый запрос к приложению (v4.72). $rows, $days, $knocks.
 *
 * v4.94: стуки сканеров (гость по несуществующему адресу) по умолчанию скрыты —
 * они шли пачками по двести в минуту, и действия людей между ними терялись.
 * Сколько скрыто и кто стучал чаще всех — строкой над таблицей; адрес ведёт на
 * его страницу, где его можно закрыть.
 */ ?>
<div class="page-head">
  <h1><?= esc(t('Запросы')) ?></h1>
</div>

<?= logs_tabs('requests') ?>

<form method="get" action="/admin/logs/requests" class="filter-bar">
  <?= field_text('user', t('Человек'), (string)($_GET['user'] ?? ''), ['placeholder' => t('почта или логин'), 'class' => 'field-grow']) ?>
  <?= field_text('ip', 'IP', (string)($_GET['ip'] ?? ''), ['mono' => true, 'class' => 'field-narrow']) ?>
  <?= field_text('path', t('Адрес содержит'), (string)($_GET['path'] ?? ''), ['mono' => true, 'placeholder' => '/admin']) ?>
  <?= field_select('status', t('Ответ'), ['' => t('— любой'), 'fail' => t('отказы и сбои')], (string)($_GET['status'] ?? '')) ?>
  <?php if (($_GET['show'] ?? '') === 'all'): ?><input type="hidden" name="show" value="all"><?php endif; ?>
  <noscript><?= field_submit(t('Фильтровать')) ?></noscript>
</form>

<?php /* Срок 0 — «не чистить» (ActivityLog::purge его пропускает); раньше
         страница печатала «Хранится 0 дн.». */ ?>
<p class="hint mb-12"><?= esc(t('Каждый запрос к приложению: кто, откуда, что открыл и чем ответили. Токены из адресов вырезаны, у параметров — только имена.')) ?>
  <?= esc($days > 0 ? t('Хранится :n :days.', ['n' => $days, 'days' => plural($days, 'день', 'дня', 'дней')]) : t('Хранится без срока.')) ?></p>

<?php if ($knocks !== null && $knocks['n'] > 0): ?>
<div class="knock-line hint mb-12">
  <?= icon('shield', 16, 'icon-muted') ?>
  <span><?= esc(t('Скрыто стуков сканеров за сутки: :n — гости по адресам, которых нет.', ['n' => number_format($knocks['n'], 0, '', ' ')])) ?>
  <?php if ($knocks['top'] !== []): ?><?= esc(t('Чаще всех:')) ?>
    <?php foreach ($knocks['top'] as $i => $k): ?><?= $i > 0 ? ', ' : '' ?><a class="mono" href="/admin/ip/<?= esc(rawurlencode($k['ip'])) ?>"><?= esc($k['ip']) ?></a> (<?= (int)$k['n'] ?>)<?php endforeach; ?>.
  <?php endif; ?>
  <a href="/admin/logs/requests?<?= esc(http_build_query(['show' => 'all'] + $_GET)) ?>"><?= esc(t('Показать всё')) ?></a></span>
</div>
<?php elseif (($_GET['show'] ?? '') === 'all'): ?>
<p class="hint mb-12"><?php $q = $_GET; unset($q['show']); ?><?= esc(t('Показаны и стуки сканеров.')) ?> <a href="/admin/logs/requests<?= $q !== [] ? '?' . esc(http_build_query($q)) : '' ?>"><?= esc(t('Скрыть')) ?></a></p>
<?php endif; ?>

<div class="bento-card">
  <?= App\Core\View::fetch('admin/_activity_table', ['rows' => $rows, 'showUser' => true, 'showIp' => true]) ?>
  <?php if (count($rows) >= 100): $last = end($rows); ?>
  <p class="mt-12 mb-0"><a class="btn btn-s btn-secondary" href="/admin/logs/requests?<?= esc(http_build_query(['before' => (int)$last['id']] + $_GET)) ?>"><?= esc(t('Раньше →')) ?></a></p>
  <?php endif; ?>
</div>
