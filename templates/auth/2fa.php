<?php /** Второй шаг: TOTP из 6 ячеек или recovery-код (§5.1). */ ?>
<?php if (empty($useRecovery)): ?>
<?= auth_head(t('2fa.title'), t('2fa.hint')) ?>
<form method="post" action="/login/2fa">
  <?= csrf_field() ?><?= App\Core\Resume::field() ?>
  <input type="hidden" name="code" id="otp-value">
  <div class="otp mb-16" data-otp="otp-value" data-otp-submit="1">
    <?php for ($i = 0; $i < 6; $i++): ?>
      <input type="tel" inputmode="numeric" maxlength="6" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" aria-label="<?= esc(t('Цифра :n', ['n' => $i + 1])) ?>">
    <?php endfor; ?>
  </div>
  <button class="btn btn-primary btn-block" type="submit"><?= esc(t('2fa.submit')) ?></button>
</form>
<div class="auth-footer"><a href="/login/2fa?recovery"><?= esc(t('2fa.use_recovery')) ?></a></div>
<?php else: ?>
<?= auth_head(t('2fa.title'), t('Введите один из одноразовых кодов восстановления.')) ?>
<form method="post" action="/login/2fa">
  <?= csrf_field() ?><?= App\Core\Resume::field() ?>
  <?php /* Подписи нет нарочно: она стоит в заголовке экрана над формой, и
           повторять её над единственным полем незачем. */ ?>
  <div class="field">
    <input class="input mono" name="recovery_code" placeholder="XXXX-XXXX-XXXX"
           aria-label="<?= esc(t('2fa.title')) ?>" autofocus required>
  </div>
  <button class="btn btn-primary btn-block" type="submit"><?= esc(t('2fa.submit')) ?></button>
</form>
<div class="auth-footer"><a href="/login/2fa"><?= esc(t('2fa.back_to_code')) ?></a></div>
<?php endif; ?>
