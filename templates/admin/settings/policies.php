<?php /** Раздел настроек «Вход» (§5.6).
 *  Отрезан от «Политик» в v4.21: там было девять карточек и двадцать восемь
 *  полей — две с половиной высоты экрана под одной подписью из пяти
 *  несвязанных тем. Оболочка затем и заведена, чтобы у темы был свой адрес. */
use App\Core\Captcha;
use App\Core\LoginName;
use App\Core\Setting;
?>
  <?php /* Четыре независимые темы — четыре карточки в сетке, а не одна лента
           на 560px посреди пустого экрана. Форма при этом одна: сетка живёт
           внутри неё, поэтому сохранение по-прежнему одной кнопкой. */ ?>
  <form method="post" action="/admin/settings/save">
    <?= csrf_field() ?><input type="hidden" name="section" value="policies">
    <div class="bento">

      <div class="bento-card col-6">
        <div class="bc-title"><?= esc(t('Пароли пользователей')) ?></div>
        <?= field_text('password_min', t('Минимальная длина'), (string)Setting::passwordMin(), [
              'mask' => '##', 'inputmode' => 'numeric', 'mono' => true, 'max' => 2,
              'note' => t('(1 = фактически без требования)'),
              'hint' => t('Допустимо 1 — 64. Длина сама по себе даёт больше, чем любой набор требований.')]) ?>
        <?= field_toggle('password_digits', t('Требовать цифру'), Setting::get('policy.password_digits') === '1') ?>
        <?= field_toggle('password_letters', t('Требовать букву'), Setting::get('policy.password_letters') === '1') ?>
        <?= field_toggle('password_upper', t('Требовать оба регистра'), Setting::get('policy.password_upper') === '1') ?>
        <?= field_toggle('password_special', t('Требовать спецсимвол'), Setting::get('policy.password_special') === '1') ?>
        <div class="bc-footer hint"><?= esc(t('Все требования опциональны. Текущая подсказка пользователям: «:hint».', ['hint' => \App\Core\PasswordPolicy::hint()])) ?></div>
      </div>

      <div class="bento-card col-6">
        <div class="bc-title"><?= esc(t('Вход')) ?></div>
        <div class="grid-2">
          <?= field_text('lockout_attempts', t('Неудач до блокировки'), (string)Setting::lockoutAttempts(), [
                'mask' => '##', 'inputmode' => 'numeric', 'mono' => true, 'max' => 2,
                'hint' => t('Допустимо 3 — 30')]) ?>
          <?= field_text('lockout_minutes', t('Блокировка, минут'), (string)Setting::lockoutMinutes(), [
                'mask' => '####', 'inputmode' => 'numeric', 'mono' => true, 'max' => 4,
                'hint' => t('Допустимо 1 — 1440')]) ?>
        </div>
        <?= field_toggle('admin_require_2fa', t('Обязательная 2FA для админ-ролей'),
              Setting::get('policy.admin_require_2fa') === '1',
              t('Выключено — каждый решает сам. Включено — админ без второго фактора не попадёт в админку, пока его не настроит.')) ?>
      </div>

      <?php /* Кто вообще может завести аккаунт и как он будет называться.
               Ни того ни другого раньше настроить было нельзя: правило имени
               входа жило одной строкой в контроллере профиля, а домен почты не
               проверялся вовсе — регистрировался любой, кто дошёл до формы. */ ?>
      <div class="bento-card col-6">
        <div class="bc-title"><?= esc(t('Замок после простоя')) ?></div>
        <?= field_text('lock_minutes', t('Запирать через, минут'),
              (string)\App\Core\QuickUnlock::idleMinutes(), [
              'type' => 'number', 'low' => 0, 'high' => 1440, 'mono' => true, 'class' => 'mw-xs',
              'hint' => t('0 — не запирать. Заперто не значит «вышел»: сессия жива, порталы работают, спрашиваем только «это вы».')]) ?>
        <p class="hint"><?= esc(t('Отпирают отпечатком или лицом через ключ устройства, а где биометрии нет — коротким PIN. Пароль не спрашивается: замок для того и нужен, чтобы его не вводить.')) ?></p>
        <p class="hint"><?= esc(t('У кого нет ни ключа, ни PIN, замок не сработает — иначе он превратился бы в требование пароля каждые полчаса.')) ?></p>
      </div>

      <div class="bento-card col-6">
        <div class="bc-title"><?= esc(t('Данные профиля')) ?></div>
        <?= field_toggle('require_phone', t('Требовать номер телефона'), Setting::get('policy.require_phone') === '1') ?>
        <?= field_toggle('require_gender', t('Требовать указания пола'), Setting::get('policy.require_gender') === '1') ?>
        <?= field_toggle('mask_own_ip', t('Скрывать часть IP в своём профиле'),
              Setting::get('policy.mask_own_ip', '1') === '1',
              t('В админке IP всегда виден целиком: по «91.132.✻.✻» инцидент не разберёшь.')) ?>
        <p class="hint mt-8"><?= esc(t('Выключено — поля в профиле остаются необязательными. Вход через сервисы заполняет их сам, если провайдер отдаёт данные.')) ?></p>
      </div>

      <div class="bento-card col-12">
        <div class="bc-title"><?= esc(t('Хранение данных')) ?> <span class="hint fw-400"><?= esc(t('(0 — хранить вечно)')) ?></span></div>
        <div class="grid-2">
          <?php foreach ([
            'audit_days' => [t('Аудит, дней'), 0],
            'activity_days' => [t('Журнал активности, дней'), 30],
            'app_logs_days' => [t('Логи приложения, дней'), 30],
            'mail_days' => [t('Отправленные письма в очереди, дней'), 30],
          ] as $rk => [$rlabel, $rdefault]):
            echo field_text('retention_' . $rk, $rlabel, (string)Setting::getInt('retention.' . $rk, $rdefault), [
                'mask' => '####', 'inputmode' => 'numeric', 'mono' => true, 'max' => 4,
                'class' => 'mb-0', 'hint' => t('Допустимо 0 — 3650')]);
          endforeach; ?>
        </div>
      </div>
    </div>
    <?= form_actions() ?>
  </form>
