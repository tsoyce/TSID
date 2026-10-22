<?php /**
 * Реквизиты владельца (§5.5): одно место, откуда они попадают во все документы.
 * $fields — объявление из App\Core\Requisites, $filled — сколько заполнено.
 */
use App\Core\Requisites;
?>
<?php /* Крошки, заголовок и пояснение ставит оболочка настроек; ссылка на
         документы — кнопкой в шапке (v4.83): второе пояснение повторяло первое. */ ?>

<div class="bento">
  <div class="bento-card col-7">
    <div class="bc-title"><?= esc(t('Кто оператор')) ?></div>
    <form method="post" action="/admin/settings/requisites">
      <?= csrf_field() ?>
      <?php /* Поля растут из объявления, а не из разметки: добавить реквизит —
               дописать строку в Requisites::fields(), и он сам появится и в
               форме, и в подстановках, и в подсказке редактору. */ ?>
      <?php foreach ($fields as $f): ?>
        <?php
        $name = 'req_' . $f['key'];
        $value = Requisites::get($f['key']);
        /* Подпись переводилась, подсказка и пример — нет (v4.45): в английском
           интерфейсе форма реквизитов показывала русские пояснения и русские
           образцы («101000, Москва, ул. Примерная, 1»). Заметить это было
           нечем — сканер словаря видел только `t('…')` и до каталога полей не
           доходил. */
        $opt = ['hint' => t($f['hint']), 'placeholder' => t($f['placeholder']), 'max' => $f['max']];
        if ($f['mask'] !== null) { $opt['mask'] = $f['mask']; $opt['mono'] = true; $opt['inputmode'] = 'numeric'; }
        if ($f['type'] === 'email') { $opt['type'] = 'email'; }
        if ($f['type'] === 'phone') { $opt['type'] = 'tel'; $opt['inputmode'] = 'tel'; }
        echo $f['type'] === 'textarea'
            ? field_textarea($name, t($f['label']), $value, $opt)
            : field_text($name, t($f['label']), $value, $opt);
        ?>
      <?php endforeach; ?>
      <?= form_actions() ?>
    </form>
  </div>

  <div class="bento-card col-5">
    <div class="bc-title"><?= esc(t('Как это попадёт в документы')) ?></div>
    <p class="hint"><?= esc(t('Наберите в тексте документа подстановку — при показе она заменится значением. Пустое поле подставится пустотой, поэтому незаполненное лучше из текста убрать.')) ?></p>
    <div class="stack mt-12">
      <?php foreach ($fields as $f): ?>
      <?php $v = Requisites::get($f['key']); ?>
      <div class="row-between">
        <code class="mono fs-13">{{&nbsp;<?= esc(t($f['label'])) ?>&nbsp;}}</code>
        <span class="hint"><?= $v !== '' ? esc(mb_substr($v, 0, 28)) : '<span class="text-warning">' . esc(t('пусто')) . '</span>' ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php /* Число заполненного — не украшение: документ с половиной пустых
             подстановок выглядит готовым и не является таковым. */ ?>
    <p class="hint mt-16">
      <?= esc(t('Заполнено :n из :all.', ['n' => (int)$filled, 'all' => count($fields)])) ?>
      <?php if ($filled < count($fields)): ?>
      <?= esc(t('Незаполненные подставятся пустотой.')) ?>
      <?php endif; ?>
    </p>
  </div>
</div>
