<?php /** Смена адреса, который ещё не подтверждён. $current, $error.
 *  Пароль здесь не спрашивается намеренно — объяснение в контроллере. */ ?>
<?= auth_head(t('Другой адрес'), t('Письмо не пришло? Скорее всего, в адресе опечатка.')) ?>

<p class="hint mb-16">
  <?= esc(t('Сейчас указан :email. Новый адрес заменит его, и письмо уйдёт туда.', ['email' => $current])) ?>
</p>

<form method="post" action="/verify/change">
  <?= csrf_field() ?>
  <?= field_text('email', t('Новый адрес'), '', [
        'id' => 'email', 'type' => 'email', 'autocomplete' => 'email',
        'required' => true, 'autofocus' => true, 'error' => $error]) ?>
  <button class="btn btn-primary btn-block" type="submit"><?= esc(t('Сохранить и выслать письмо')) ?></button>
</form>

<div class="auth-footer">
  <a href="/profile"><?= esc(t('← Вернуться')) ?></a>
</div>
