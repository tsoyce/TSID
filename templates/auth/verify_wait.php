<?php /**
 * «Подтвердите почту» (v4.72): новый аккаунт входит после подтверждения. $email.
 *
 * В письме и код, и кнопка (v4.74). Код — теми же шестью ячейками, что второй
 * фактор: вставка из письма целиком разойдётся по ячейкам сама, а на телефоне
 * цифровая клавиатура. Кнопка из письма продолжает вход во вкладке, где её
 * откроют; эта страница, открытая рядом, сама узнает об этом при обновлении.
 */ ?>
<?= auth_head(t('Подтвердите почту'), t('Мы отправили письмо на :email. Введите код из него — или нажмите в письме кнопку «Подтвердить почту».', ['email' => $email])) ?>
<form method="post" action="/verify/code">
  <?= csrf_field() ?>
  <input type="hidden" name="code" id="otp-value">
  <div class="otp mb-16" data-otp="otp-value" data-otp-submit="1">
    <?php for ($i = 0; $i < 6; $i++): ?>
      <input type="tel" inputmode="numeric" maxlength="6" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" aria-label="<?= esc(t('Цифра :n', ['n' => $i + 1])) ?>">
    <?php endfor; ?>
  </div>
  <button class="btn btn-primary btn-block" type="submit"><?= esc(t('Подтвердить')) ?></button>
</form>
<p class="hint text-center mt-12 mb-0"><?= esc(t('Письмо идёт до пары минут. Нет во «Входящих» — загляните в «Спам».')) ?></p>

<form method="post" action="/verify/wait/resend" class="mt-16">
  <?= csrf_field() ?>
  <button class="btn btn-secondary btn-block" type="submit"><?= icon('refresh', 16) ?> <?= esc(t('Отправить письмо ещё раз')) ?></button>
</form>

<?php /* Опечатка в адресе — самая частая причина «письмо не пришло», и без
         этого поля выход был один: регистрироваться заново. */ ?>
<details class="disclosure mt-16">
  <summary><?= esc(t('Ошиблись адресом?')) ?></summary>
  <form method="post" action="/verify/wait/email" class="mt-12">
    <?= csrf_field() ?>
    <?= field_text('email', t('Верный адрес'), '', ['type' => 'email', 'required' => true,
          'autocomplete' => 'email', 'placeholder' => 'you@example.ru']) ?>
    <button class="btn btn-secondary btn-block" type="submit"><?= esc(t('Отправить на этот адрес')) ?></button>
  </form>
</details>

<div class="auth-footer"><a href="/login"><?= esc(t('Войти в другой аккаунт')) ?></a></div>