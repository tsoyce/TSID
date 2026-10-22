<?php /** Безопасность (§5.1): пароль, 2FA c QR, recovery-коды, оценка. */ ?>
<div class="page-head"><h1><?= esc(t('Безопасность')) ?></h1></div>

<div class="bento">
  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Оценка защищённости')) ?></div>
    <div class="kpi-value fs-28"><?= (int)$score['percent'] ?>%</div>
    <div class="score-bar mt-8"><i style="width:<?= (int)$score['percent'] ?>%"></i></div>
    <ul class="check-list">
      <?php foreach ($score['items'] as $item): ?>
      <li class="<?= $item['ok'] ? 'yes' : 'no' ?>"><?= $item['ok'] ? icon('check', 15, 'icon-ok') : icon('circle', 15, 'icon-muted') ?> <?= esc($item['label']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php $hasPassword = !empty($user['password_hash']); ?>
  <div class="bento-card col-8">
    <div class="bc-title"><?= esc($hasPassword ? t('Смена пароля') : t('Установка пароля')) ?></div>
    <?php if (!$hasPassword): ?>
      <p class="muted"><?= esc(t('У аккаунта нет пароля — вы входите через привязанные сервисы или по ссылке из письма. Пароль добавит ещё один способ входа.')) ?></p>
    <?php endif; ?>
    <form method="post" action="/profile/security/password">
      <?= csrf_field() ?>
      <div class="grid-2">
        <?php if ($hasPassword): ?>
        <?= field_password('current_password', t('Текущий пароль'), ['id' => 'f-current_password']) ?>
        <?php endif; ?>
        <?= field_password('new_password', $hasPassword ? t('Новый пароль') : t('Придумайте пароль'), [
              'id' => 'np', 'new' => true, 'meter' => 'np-meter']) ?>
      </div>
      <button class="btn btn-primary" type="submit"><?= esc($hasPassword ? t('Сменить пароль') : t('Установить пароль')) ?></button>
      <?php if ($hasPassword): ?><span class="hint ml-8"><?= esc(t('Остальные сессии будут завершены.')) ?></span><?php endif; ?>
    </form>
  </div>

  <?php /* Замок — не второй фактор. Второй фактор отвечает «кто ты» при
           входе; замок отвечает «ты ли это» тому, чья сессия уже открыта.
           Поэтому он и стоит отдельной карточкой, а не строкой в 2FA. */ ?>
  <?php if (\App\Core\QuickUnlock::enabled()): ?>
  <div class="bento-card col-12" id="lock">
    <div class="bc-title"><?= esc(t('Быстрая переавторизация')) ?></div>
    <p class="muted"><?= esc(t('После :n мин без действий установка запирает сессию и просит подтвердить, что это вы. Выйти при этом не нужно: пароль не спрашивается, порталы не отключаются.', ['n' => \App\Core\QuickUnlock::idleMinutes()])) ?></p>

    <?php $hasPasskeyNow = \App\Core\QuickUnlock::hasPasskey((int)$user['id']); ?>
    <div class="stack mb-16">
      <div class="row-between">
        <span><?= icon($hasPasskeyNow ? 'check-circle' : 'circle', 16, $hasPasskeyNow ? 'icon-ok' : 'icon-muted') ?>
          <?= esc(t('Отпечаток или лицо — ключ устройства')) ?></span>
        <?php if (!$hasPasskeyNow): ?><a class="hint" href="#passkeys"><?= esc(t('добавить →')) ?></a><?php endif; ?>
      </div>
      <div class="row-between">
        <span><?= icon($hasPin ? 'check-circle' : 'circle', 16, $hasPin ? 'icon-ok' : 'icon-muted') ?>
          <?= esc(t('PIN — запасной путь для устройств без биометрии')) ?></span>
      </div>
    </div>

    <?php if (!$hasPasskeyNow && !$hasPin): ?>
    <?= callout(t('Пока нет ни ключа устройства, ни PIN — замок не сработает: запирать нечем, а спрашивать пароль каждые полчаса значит превратить его в мучение.'), 'warning') ?>
    <?php endif; ?>

    <form method="post" action="/profile/security/pin" class="grid-2">
      <?= csrf_field() ?>
      <?= field_text('pin', $hasPin ? t('Новый PIN') : t('Задать PIN'), '', [
            'type' => 'password', 'inputmode' => 'numeric', 'mono' => true,
            'autocomplete' => 'off', 'max' => \App\Core\QuickUnlock::PIN_MAX,
            'hint' => t('От :min до :max цифр. Одинаковые и подряд идущие не принимаются.', [
                'min' => \App\Core\QuickUnlock::PIN_MIN, 'max' => \App\Core\QuickUnlock::PIN_MAX])]) ?>
      <?= field_text('pin2', t('Ещё раз'), '', [
            'type' => 'password', 'inputmode' => 'numeric', 'mono' => true,
            'autocomplete' => 'off', 'max' => \App\Core\QuickUnlock::PIN_MAX]) ?>
      <div>
        <button class="btn btn-primary" type="submit"><?= esc($hasPin ? t('Сменить PIN') : t('Сохранить PIN')) ?></button>
      </div>
    </form>
    <?php if ($hasPin): ?>
    <form method="post" action="/profile/security/pin/clear" class="mt-8"
          data-confirm="<?= esc(t('Убрать PIN? Останется только ключ устройства.')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-ghost btn-s" type="submit"><?= esc(t('Убрать PIN')) ?></button>
    </form>
    <?php endif; ?>
    <p class="hint mt-8"><?= esc(t(':n неверных попыток подряд — и сессия закрывается совсем: дальше только полный вход.', ['n' => \App\Core\QuickUnlock::PIN_ATTEMPTS])) ?></p>
  </div>
  <?php endif; ?>

  <div class="bento-card col-12" id="2fa">
    <div class="bc-title"><?= esc(t('Двухфакторная защита (TOTP)')) ?></div>

    <?php if ($totpEnabled): ?>
      <div class="row-between">
        <div>
          <span class="badge badge-ok"><?= esc(t('Включена')) ?></span>
          <span class="muted"><?= esc(t('Осталось recovery-кодов: :n', ['n' => (int)$recoveryLeft])) ?></span>
        </div>
        <form method="post" action="/profile/security/2fa/disable" class="row" data-confirm="<?= esc(t('Выключить 2FA? Вход станет менее защищённым, а админка — недоступной.')) ?>">
          <?= csrf_field() ?>
          <input class="input mw-xs" type="password" name="password" placeholder="<?= esc(t('Пароль для подтверждения')) ?>"
                 aria-label="<?= esc(t('Пароль для подтверждения')) ?>" required>
          <button class="btn btn-danger-ghost" type="submit"><?= esc(t('Выключить')) ?></button>
        </form>
      </div>

    <?php elseif ($pendingSecret): ?>
      <div class="grid-2">
        <div>
          <p><?= esc(t('1. Отсканируйте QR в приложении-аутентификаторе (Google Authenticator, Aegis, 1Password…):')) ?></p>
          <div class="qr-box" data-qr="<?= esc($totpUri) ?>"></div>
          <p class="hint mt-8"><?= esc(t('Не сканируется? Введите секрет вручную:')) ?></p>
          <?= field_copy('totp-secret', '', $pendingSecret) ?>
        </div>
        <div>
          <p><?= esc(t('2. Введите код из приложения:')) ?></p>
          <form method="post" action="/profile/security/2fa/confirm">
            <?= csrf_field() ?>
            <input type="hidden" name="code" id="otp-value">
            <div class="otp mb-16" data-otp="otp-value">
              <?php for ($i = 0; $i < 6; $i++): ?><input type="tel" inputmode="numeric" maxlength="6" aria-label="<?= esc(t('Цифра :n', ['n' => $i + 1])) ?>"><?php endfor; ?>
            </div>
            <button class="btn btn-primary" type="submit"><?= esc(t('Подтвердить и включить')) ?></button>
          </form>
        </div>
      </div>

    <?php else: ?>
      <div class="row-between">
        <p class="muted mb-0"><?= esc(t('Одноразовые коды из приложения — самый надёжный второй фактор. Включается и выключается когда угодно.')) ?></p>
        <form method="post" action="/profile/security/2fa/start">
          <?= csrf_field() ?>
          <button class="btn btn-primary" type="submit"><?= esc(t('Включить 2FA')) ?></button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <div class="bento-card col-12" id="passkeys">
    <div class="bc-title"><?= esc(t('Passkeys — вход без пароля')) ?></div>
    <div class="row-between mb-16">
      <p class="muted mb-0 mw-md"><?= esc(t('Ключ в iCloud Keychain, Google Password Manager или на аппаратном токене. На чужом компьютере — выберите «войти с телефона» и отсканируйте QR-код.')) ?></p>
      <button class="btn btn-primary" type="button" data-passkey-add><?= esc(t('Добавить passkey')) ?></button>
    </div>
    <div id="passkey-status" class="hint mb-8"></div>
    <?php if ($passkeys !== []): ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th><?= esc(t('Название')) ?></th><th><?= esc(t('Добавлен')) ?></th><th><?= esc(t('Последний вход')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($passkeys as $pk): ?>
        <tr>
          <td class="cell-main"><?= icon('key', 16, 'icon-ink') ?> <?= esc($pk['label'] ?: t('Ключ')) ?>
            <?php if ($pk['transports'] && str_contains($pk['transports'], 'hybrid')): ?><span class="badge badge-muted"><?= esc(t('с телефона')) ?></span><?php endif; ?></td>
          <td class="hint"><?= esc(fmt_dt($pk['created_at'], 'd.m.Y')) ?></td>
          <td class="hint"><?= esc(fmt_ago($pk['last_used_at'])) ?></td>
          <td>
            <form method="post" action="/passkey/delete" data-confirm="<?= esc(t('Удалить этот passkey? Вход по нему перестанет работать.')) ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$pk['id'] ?>">
              <button class="btn btn-s btn-danger-ghost" type="submit"><?= esc(t('Удалить')) ?></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($socialProviders !== []): ?>
  <div class="bento-card col-12" id="social">
    <div class="bc-title"><?= esc(t('Вход через внешние сервисы')) ?></div>
    <p class="muted"><?= esc(t('Привязанным аккаунтом можно входить без пароля. Отвязать можно в любой момент — кроме случая, когда это ваш единственный способ войти.')) ?></p>
    <?php $linkedBy = []; foreach ($socialLinked as $l) $linkedBy[$l['provider']] = $l; ?>
    <div class="social-row mt-8">
      <?php foreach ($socialProviders as $p): $l = $linkedBy[$p->id()] ?? null; ?>
        <?php if ($l): ?>
          <form method="post" action="/social/unlink"
                data-confirm="<?= esc(t('Отвязать :name от аккаунта?', ['name' => $p->title()])) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-secondary btn-block" type="submit">
              <?= icon('check', 16, 'icon-ok') ?> <?= esc(t(':name — отвязать', ['name' => $p->title()])) ?>
            </button>
          </form>
        <?php else: ?>
          <a class="btn btn-secondary" href="/social/<?= esc($p->id()) ?>/start">
            <?= icon($p->icon(), 16) ?> <?= esc(t('Привязать :name', ['name' => $p->title()])) ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($freshRecovery)): ?>
  <div class="bento-card col-12" id="recovery">
    <div class="bc-title"><?= esc(t('Коды восстановления — показываются один раз')) ?></div>
    <div class="secret-plate mb-16"><?= esc(t('Сохраните сейчас: каждый код одноразовый, потом мы их не покажем.')) ?></div>
    <div class="code-block cols-2"><?php foreach ($freshRecovery as $c) echo esc($c) . "\n"; ?></div>
    <div class="row mt-16">
      <a class="btn btn-secondary" href="/profile/security/recovery.txt"><?= esc(t('Скачать .txt')) ?></a>
    </div>
  </div>
  <?php endif; ?>
</div>

<script src="<?= esc(asset('assets/js/qrcode.js')) ?>" defer></script>
<script src="<?= esc(asset('assets/js/passkey.js')) ?>" defer></script>
