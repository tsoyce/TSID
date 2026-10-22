<?php /** Форма нового пароля: сброс и первичная установка (§13). $action, $token. */
use App\Core\Setting; ?>
<?= auth_head($pageTitle) ?>
<form method="post" action="<?= esc($action) ?>">
  <?= csrf_field() ?>
  <?= field_password('password', t('Новый пароль'), [
        'new' => true, 'meter' => 'rs-meter', 'autofocus' => true,
        'note' => '(' . \App\Core\PasswordPolicy::hint() . ')']) ?>
  <button class="btn btn-primary btn-block" type="submit"><?= esc(t('Сохранить пароль')) ?></button>
</form>
<p class="hint mt-16 text-center"><?= esc(t('После сохранения все активные сессии будут завершены.')) ?></p>
