<?php /** Приём инвайта: один экран, одна кнопка (§«30 секунд»). */ ?>
<?= auth_context(['id' => $invite['client_id'], 'icon_path' => $invite['icon_path'],
      'name' => $invite['client_name'], 'slug' => $invite['client_slug']], t('invite.title')) ?>

<?php if ($user): ?>
  <p class="muted text-center">
    <?= esc(t('Вы вошли как :email.', ['email' => $user['email']])) ?>
    <?= esc(t('Примите приглашение — и портал сразу станет доступен.')) ?>
  </p>
  <form method="post" action="/invite/<?= esc($token) ?>">
    <?= csrf_field() ?>
    <button class="btn btn-primary btn-block" type="submit"><?= esc(t('invite.accept')) ?></button>
  </form>
  <div class="auth-footer"><a href="/profile/services"><?= esc(t('Мои сервисы')) ?></a></div>
<?php else: ?>
  <p class="muted text-center">
    <?= esc(t('Войдите или создайте аккаунт — приглашение примется автоматически.')) ?>
  </p>
  <form method="post" action="/invite/<?= esc($token) ?>">
    <?= csrf_field() ?>
    <button class="btn btn-primary btn-block" type="submit"><?= esc(t('invite.login_and_accept')) ?></button>
  </form>
  <div class="auth-footer"><?= esc(t('Нет аккаунта?')) ?> <a href="/register"><?= esc(t('Создать за полминуты')) ?></a></div>
<?php endif; ?>

<?php if ($invite['note']): ?>
  <p class="hint mt-16 text-center"><?= esc($invite['note']) ?></p>
<?php endif; ?>
