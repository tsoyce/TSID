<?php /** Регистрация (§5.1). $old, $errors, $client. */
use App\Core\Setting; ?>
<?php if ($client): ?>
<?php /* Не заголовок: имя экрана даёт подсвеченная вкладка. */ ?>
<?= auth_context($client, t('Аккаунт для входа в'), false) ?>
<?php endif; ?>

<?= auth_tabs('register') ?>

<form method="post" action="/register">
  <?= csrf_field() ?><?= App\Core\Resume::field() ?>
  <?php /* Приглашение едет скрытым полем: при ошибке в форме страница
           перерисовывается POST-ом, и без него ключ терялся бы на первой же
           опечатке — человек оказывался перед закрытой дверью. */ ?>
  <?php $inv = App\Core\Signup::inviteFromRequest(); ?>
  <?php if ($inv !== null): ?><input type="hidden" name="invite" value="<?= esc($inv['token']) ?>"><?php endif; ?>
  <?php if (isset($errors['form'])): ?><div class="field-error mb-12" role="alert"><?= esc($errors['form']) ?></div><?php endif; ?>
  <div class="grid-2">
    <?= field_text('first_name', t('Имя'), $old['first_name'] ?? '', [
          'id' => 'first_name', 'max' => 120, 'autocomplete' => 'given-name',
          'required' => true, 'autofocus' => true, 'error' => $errors['first_name'] ?? '']) ?>
    <?= field_text('last_name', t('Фамилия'), $old['last_name'] ?? '', [
          'id' => 'last_name', 'max' => 120, 'autocomplete' => 'family-name',
          'note' => t('(необязательно)')]) ?>
  </div>
  <?= field_text('email', 'Email', $old['email'] ?? '', [
        'id' => 'email', 'type' => 'email', 'autocomplete' => 'email',
        'required' => true, 'error' => $errors['email'] ?? '']) ?>
  <?= field_password('password', t('Пароль'), [
        'new' => true, 'meter' => 'reg-meter',
        'note' => '(' . \App\Core\PasswordPolicy::hint() . ')',
        'error' => $errors['password'] ?? '']) ?>
  <?php /* Те же галочки, что на экране соцвхода: согласие, проставленное за
           человека, — не согласие. Документов обычно один-два, и это единственный
           лишний клик во всей форме. Если документы выключены — блока нет. */ ?>
  <?php foreach (\App\Core\Legal::requiringConsent() as $docKey => $doc): ?>
  <label class="checkbox mb-8">
    <input type="checkbox" name="accept[]" value="<?= esc($docKey) ?>" required>
    <span><?= esc(t('Принимаю')) ?>
      <a href="/legal/<?= esc($docKey) ?>" target="_blank" rel="noopener"><?= esc($doc['acc']) ?></a></span>
  </label>
  <?php endforeach; ?>
  <?php if (isset($errors['accept'])): ?><div class="field-error"><?= esc($errors['accept']) ?></div><?php endif; ?>
  <button class="btn btn-primary btn-block mt-16" type="submit"><?= esc(t('Создать аккаунт')) ?></button>
</form>

