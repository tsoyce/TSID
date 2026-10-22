<?php /** Журнал приложения (§5.2): storage/logs/app-*.log, JSON-lines.
     До v4.94 назывался «Ошибки приложения», а на боевой в нём почти одни
     служебные записи (info) — название пугало зря. */ ?>
<div class="page-head">
  <h1><?= esc(t('Журнал приложения')) ?></h1>
  <?php if ($days !== []): ?>
  <div class="row">
    <a class="btn btn-secondary" href="/admin/logs/errors/download?day=<?= esc($day) ?>">
      <?= icon('download', 16) ?> <?= esc(t('Скачать журнал')) ?>
    </a>
    <?php /* Чистка кнопкой, а не только по расписанию: планировщик после
             установки настроен далеко не всегда, и до сих пор журналы можно
             было только копить. Сегодняшний день не трогается. */ ?>
    <?php if (App\Core\Auth::can('housekeeping')): ?>
    <form method="post" action="/admin/logs/errors/purge"
          data-confirm="<?= esc(t('Убрать журналы старше двух недель? Сегодняшний останется.')) ?>">
      <?= csrf_field() ?><input type="hidden" name="keep_days" value="14">
      <button class="btn btn-secondary" type="submit"><?= esc(t('Убрать старые')) ?></button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?= logs_tabs('errors') ?>

<?php /* Поиск по номеру инцидента — отдельной строкой над фильтрами: это не
         фильтр, а точный запрос. Страница 500 показывает человеку номер и
         говорит «назовите его администратору»; здесь администратор его и
         вводит. Ищется по всем дням, а не только по выбранному. */ ?>
<?php /* Подсказка вынесена ИЗ поля под форму. Строка равняется по нижнему краю,
         а нижний край поля с подсказкой — это низ подсказки: кнопка «Найти»
         оказывалась ниже поля ровно на её высоту. Подсказка и относится не к
         полю, а ко всему поиску. */ ?>
<form method="get" action="/admin/logs/errors" class="filter-bar is-search mb-4">
  <?= field_text('incident', t('Номер инцидента'), $incident, [
        'mono' => true, 'placeholder' => 'a6154c59', 'id' => 'f-incident',
        'invalid' => $incident !== '' && !$incidentValid]) ?>
  <?= field_submit(t('Найти')) ?>
</form>
<p class="hint mb-16"><?= esc(t('Восемь знаков со страницы ошибки. Ищется по всем дням.')) ?></p>

<?php if ($incident !== ''): ?>
  <?php if (!$incidentValid): ?>
    <p class="text-danger mb-16"><?= esc(t('Номер состоит из восьми знаков — цифр и букв a–f. Проверьте, что скопировали целиком.')) ?></p>
  <?php elseif ($total === 0): ?>
    <p class="muted mb-16"><?= esc(t('По номеру :id ничего нет. Записи чистятся по сроку хранения — возможно, инцидент старше него.', ['id' => $incident])) ?></p>
  <?php else: ?>
    <p class="muted mb-16"><?= esc(t('Инцидент :id — записей: :n, файл :file.',
        ['id' => $incident, 'n' => $total, 'file' => $incidentFile])) ?></p>
  <?php endif; ?>
<?php endif; ?>

<form method="get" action="/admin/logs/errors" class="filter-bar">
  <?php
  $dayOptions = $days === [] ? ['' => t('лог пуст')] : array_combine($days, $days);
  ?>
  <?= field_select('day', t('День'), $dayOptions, $day) ?>
  <?= field_select('level', t('Уровень'), [
        '' => t('— любой'), 'error' => 'error', 'warning' => 'warning',
        'info' => 'info', 'debug' => 'debug',
      ], $level) ?>
  <noscript><?= field_submit(t('Показать')) ?></noscript>
</form>

<?php /* Одинаковые сообщения свёрнуты в одну строку со счётчиком. Замер живого
         журнала: 460 записей за день — это шесть разных сообщений, и экран
         растягивался на 27 экранов прокрутки, где сотня копий одной строки
         прятала редкую и важную. Сами записи внутри группы, по времени. */ ?>
<?php if ($groups !== []): ?>
<p class="hint mb-12"><?= esc(t('Записей — :n, разных сообщений — :g. Одинаковые свёрнуты: нажмите строку, чтобы увидеть каждую.',
    ['n' => (int)$total, 'g' => count($groups)])) ?></p>
<?php endif; ?>

<div class="stack">
<?php foreach ($groups as $g): ?>
  <?php
  $tone = $g['level'] === 'error' ? 'badge-danger' : ($g['level'] === 'warning' ? 'badge-warn' : 'badge-muted');
  ?>
  <details class="log-group">
    <summary>
      <span class="badge <?= $tone ?>"><?= esc($g['level']) ?></span>
      <span class="log-group-msg mono"><?= esc($g['msg']) ?></span>
      <?php /* Счётчик — главное, чего на экране не было вовсе: одно и то же
               сообщение сто раз и один раз означают разное. */ ?>
      <span class="log-group-count"><?= (int)$g['count'] ?>&times;</span>
      <?php /* Время — местное и коротко (v4.94): строка ISO в UTC
               («2026-09-27T04:06:33+00:00 … 2026-09-27T10:44:28+00:00») была
               единственным таким временем в админке. Точное — в подсказке. */
      $a = fmt_dt($g['first'], 'd.m H:i:s');
      $b = fmt_dt($g['last'], substr($a, 0, 5) === fmt_dt($g['last'], 'd.m') ? 'H:i:s' : 'd.m H:i:s'); ?>
      <span class="hint mono log-group-when" title="<?= esc($g['first'] === $g['last'] ? $g['last'] : $g['first'] . ' … ' . $g['last']) ?>">
        <?= esc($g['first'] === $g['last'] ? $a : $a . ' … ' . $b) ?>
      </span>
    </summary>
    <div class="log-group-body">
      <?php foreach ($g['items'] as $e): ?>
      <div class="log-item">
        <div class="row gap-8">
          <span class="hint mono" title="<?= esc($e['ts'] ?? '') ?>"><?= esc(fmt_dt($e['ts'] ?? null, 'd.m H:i:s')) ?></span>
          <span class="hint mono">req:<?= esc($e['request_id'] ?? '-') ?></span>
        </div>
        <?php if (!empty($e['ctx']) && $e['ctx'] !== []): ?>
        <div class="code-block mt-8 pre-wrap"><?= esc(json_encode($e['ctx'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </details>
<?php endforeach; ?>
<?php if ($groups === []): ?>
  <?= empty_state('check-circle', t('Записей нет — либо тишина, либо фильтр слишком строгий.')) ?>
<?php endif; ?>
</div>
