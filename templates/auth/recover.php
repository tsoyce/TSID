<?php /** Запрос ссылки восстановления (§5.1): ответы одинаковы для любых email. */ ?>
<?= auth_tabs('recover') ?>
<?php /* Заголовка нет намеренно: экран уже назван вкладкой, и h1 под ней
         повторял бы то же слово вдвое крупнее. Объяснение принадлежит полю,
         а не экрану, поэтому стоит подписью под ним. */ ?>
<form method="post" action="/recover">
  <?= csrf_field() ?>
  <?= field_text('email', t('common.email'), '', [
        'id' => 'email', 'type' => 'email', 'autocomplete' => 'email',
        'required' => true, 'autofocus' => true, 'hint' => t('recover.hint')]) ?>
  <button class="btn btn-primary btn-block" type="submit"><?= esc(t('recover.submit')) ?></button>
</form>
