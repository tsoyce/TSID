<?php /** Экран замка. $user, $hasPin, $hasPasskey, $error.
 *  Пароля здесь нет намеренно: замок для того и заведён, чтобы его не вводить.
 *  Кому проще ввести пароль — внизу «Выйти». */ ?>
<div class="lock-who">
  <?= avatar_html($user, 48) ?>
  <div>
    <div class="cell-main"><?= esc(user_name($user)) ?></div>
    <?php if (($sub = user_sub($user)) !== ''): ?><div class="hint"><?= esc($sub) ?></div><?php endif; ?>
  </div>
</div>

<p class="muted"><?= esc(t('Вы не пользовались этим устройством какое-то время. Подтвердите, что это по-прежнему вы.')) ?></p>

<?php if ($hasPasskey): ?>
<?php /* Ключ устройства первым: это отпечаток или лицо, полсекунды и ничего
         вспоминать не надо. PIN ниже — для устройств без биометрии. */ ?>
<button class="btn btn-primary btn-block" type="button" data-passkey-login>
  <?= icon('key', 17) ?> <?= esc(t('Отпечаток или лицо')) ?>
</button>
<?php endif; ?>

<?php if ($hasPin): ?>
<?php if ($hasPasskey): ?><div class="auth-divider"><?= esc(t('login.or')) ?></div><?php endif; ?>
<form method="post" action="/lock/pin">
  <?= csrf_field() ?>
  <?= field_text('pin', t('PIN'), '', [
        'id' => 'pin', 'type' => 'password', 'inputmode' => 'numeric',
        'autocomplete' => 'off', 'mono' => true, 'required' => true,
        'autofocus' => !$hasPasskey, 'error' => $error,
        'max' => App\Core\QuickUnlock::PIN_MAX]) ?>
  <button class="btn <?= $hasPasskey ? 'btn-secondary' : 'btn-primary' ?> btn-block" type="submit">
    <?= esc(t('Открыть')) ?>
  </button>
</form>
<?php endif; ?>

<div class="auth-footer">
  <form method="post" action="/logout">
    <?= csrf_field() ?><input type="hidden" name="mode" value="here">
    <button class="btn btn-ghost btn-s" type="submit"><?= esc(t('Выйти и войти паролем')) ?></button>
  </form>
</div>
