<?php /**
 * Регистрация по шагам (v4.96; §5.1). $step (email|code|profile), $steps, $email,
 * $old, $errors, $client, $captcha.
 *
 * Почта — первой: аккаунт рождается последним шагом и уже с подтверждённой
 * почтой. Номер шага виден — человек знает, сколько осталось. Шаг с кодом —
 * только когда подтверждение почты включено; иначе шагов два.
 */
use App\Core\Resume;
$n = ['email' => 1, 'code' => 2, 'profile' => $steps][$step] ?? 1;
$inv = App\Core\Signup::inviteFromRequest();
$keep = Resume::field() . ($inv !== null ? '<input type="hidden" name="invite" value="' . esc($inv['token']) . '">' : ''); ?>
<?php if ($client): ?>
<?php /* Та же шапка, что на «Вход» (v5.3.1): вкладки переключаются без нового
         документа, и шапка не должна прыгать. */ ?>
<?= auth_portal_head($client, t('register.context')) ?>
<?php endif; ?>

<?= auth_tabs('register') ?>

<p class="signup-step hint"><?= esc(t('Шаг :n из :of', ['n' => $n, 'of' => $steps])) ?></p>

<?php if ($step === 'email'): ?>
<form method="post" action="/register">
  <?= csrf_field() ?><?= $keep ?>
  <?php if (isset($errors['form'])): ?><div class="field-error mb-12" role="alert"><?= esc($errors['form']) ?></div><?php endif; ?>
  <?= field_text('email', 'Email', $old['email'] ?? '', [
        'id' => 'email', 'type' => 'email', 'autocomplete' => 'email',
        'required' => true, 'autofocus' => true, 'error' => $errors['email'] ?? '',
        /* Ошибка заменяет подсказку: две строки под полем — одна лишняя. */
        'hint' => isset($errors['email']) ? '' : (App\Core\EmailGate::active()
            ? t('Пришлём код — убедиться, что почта ваша. Имя и пароль — следующим шагом.') : t('Имя и пароль — следующим шагом.'))]) ?>
  <?php if (!empty($captcha)): ?><?= captcha_field('captcha-reg', t('Проверка от ботов: так владелец сервиса настроил регистрацию.')) ?><?php endif; ?>
  <button class="btn btn-primary btn-block mt-16" type="submit"><?= esc(t('Продолжить')) ?></button>
</form>

<?php elseif ($step === 'code'): ?>
<p class="mb-16"><?= esc(t('Отправили письмо на :email. Введите код из него — или нажмите в письме кнопку.', ['email' => $email])) ?></p>
<form method="post" action="/register/code">
  <?= csrf_field() ?><?= $keep ?>
  <input type="hidden" name="code" id="otp-value">
  <?php /* Те же шесть ячеек, что у второго фактора: вставка из письма целиком
           разойдётся по ячейкам сама, на телефоне — цифровая клавиатура. */ ?>
  <div class="otp mb-16" data-otp="otp-value" data-otp-submit="1">
    <?php for ($i = 0; $i < 6; $i++): ?>
      <input type="tel" inputmode="numeric" maxlength="6" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" aria-label="<?= esc(t('Цифра :n', ['n' => $i + 1])) ?>">
    <?php endfor; ?>
  </div>
  <button class="btn btn-primary btn-block" type="submit"><?= esc(t('Подтвердить')) ?></button>
</form>
<p class="hint text-center mt-12 mb-0"><?= esc(t('Письмо идёт до пары минут. Нет во «Входящих» — загляните в «Спам».')) ?></p>
<div class="signup-alt mt-16">
  <form method="post" action="/register/resend"><?= csrf_field() ?><?= $keep ?>
    <button class="btn btn-secondary btn-block" type="submit"><?= icon('refresh', 16) ?> <?= esc(t('Прислать код ещё раз')) ?></button>
  </form>
  <form method="post" action="/register/restart"><?= csrf_field() ?><?= $keep ?>
    <button class="btn btn-secondary btn-block" type="submit"><?= esc(t('Другой адрес')) ?></button>
  </form>
</div>

<?php else: ?>
<form method="post" action="/register/restart" class="signup-email mb-16">
  <?= csrf_field() ?><?= $keep ?>
  <span class="signup-email-addr"><?= icon('mail', 16, 'icon-muted') ?><span class="truncate"><?= esc($email) ?></span></span>
  <button class="btn btn-ghost btn-s" type="submit"><?= esc(t('Изменить')) ?></button>
</form>
<form method="post" action="/register/finish">
  <?= csrf_field() ?><?= $keep ?>
  <div class="grid-2">
    <?= field_text('first_name', t('Имя'), $old['first_name'] ?? '', [
          'id' => 'first_name', 'max' => 120, 'autocomplete' => 'given-name',
          'required' => true, 'autofocus' => true, 'error' => $errors['first_name'] ?? '']) ?>
    <?= field_text('last_name', t('Фамилия'), $old['last_name'] ?? '', [
          'id' => 'last_name', 'max' => 120, 'autocomplete' => 'family-name',
          'note' => t('(необязательно)')]) ?>
  </div>
  <?php /* Почта уже известна — в скрытом поле для менеджера паролей: он
           сохранит пару «почта + пароль», а не пароль без имени. */ ?>
  <input type="email" name="username" value="<?= esc($email) ?>" autocomplete="username" hidden>
  <?= field_password('password', t('Пароль'), [
        'new' => true, 'meter' => 'reg-meter',
        'note' => '(' . \App\Core\PasswordPolicy::hint() . ')',
        'error' => $errors['password'] ?? '']) ?>
  <?php /* Те же галочки, что на экране соцвхода: согласие, проставленное за
           человека, — не согласие. Если документы выключены — блока нет. */ ?>
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
<?php endif; ?>
