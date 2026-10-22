<?php /** Раздел настроек «Почта» (§5.6).
 *  Поля объявлением, а не разметкой: у каждого своя подпись, тип и маска —
 *  порт принимает только цифры, адрес отправителя проверяется браузером. */
use App\Core\Setting;
?>
  <div class="bento-card">
    <form method="post" action="/admin/settings/save">
      <?= csrf_field() ?><input type="hidden" name="section" value="smtp">

      <?= field_select('smtp_driver', t('Транспорт'), [
            'smtp' => 'SMTP',
            'log' => t('Файл-лог (разработка)'),
          ], Setting::get('mail.driver', config('mail.driver')) === 'log' ? 'log' : 'smtp',
          ['hint' => t('Файл-лог складывает письма в storage/logs/mail — удобно, пока сервера нет.')]) ?>

      <div class="grid-2">
        <?= field_text('smtp_host', t('Хост'), (string)Setting::get('mail.host', config('mail.host')), [
              'placeholder' => 'smtp.example.com', 'mono' => true, 'max' => 190,
              'autocomplete' => 'off']) ?>
        <?php /* Порт — цифры и ничего кроме: «587 (TLS)», скопированное из
                 инструкции хостера, сохранялось как 587 и выглядело верным, а
                 могло сохраниться и как 0. */ ?>
        <?= field_text('smtp_port', t('Порт'), (string)Setting::get('mail.port', config('mail.port', 587)), [
              'mask' => '#####', 'inputmode' => 'numeric', 'mono' => true, 'max' => 5,
              'hint' => t('587 для STARTTLS, 465 для SSL, 25 без шифрования')]) ?>
      </div>

      <div class="grid-2">
        <?= field_select('smtp_encryption', t('Шифрование'), [
              'tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => t('Нет'),
            ], (string)Setting::get('mail.encryption', config('mail.encryption', 'tls'))) ?>
        <?= field_text('smtp_from_email', t('From-адрес'), (string)Setting::get('mail.from_email', config('mail.from.0')), [
              'type' => 'email', 'placeholder' => 'noreply@example.com', 'max' => 190,
              'hint' => t('С какого адреса уходят письма. Домен должен разрешать отправку от вашего имени.')]) ?>
      </div>

      <div class="grid-2">
        <?= field_text('smtp_user', t('Логин'), (string)Setting::get('mail.user', config('mail.user')), [
              'autocomplete' => 'off', 'max' => 190]) ?>
        <?php /* Плейсхолдер точками, если значение уже есть. Пустое поле
                 неотличимо от «не заполнено», и владелец каждый раз гадает,
                 настроен SMTP или нет. */ ?>
        <?= field_text('smtp_pass', t('Пароль'), '', [
              'type' => 'password', 'autocomplete' => 'new-password',
              'note' => t('(пусто = не менять)'),
              'placeholder' => Setting::get('mail.pass', config('mail.pass')) ? '••••••••' : '']) ?>
      </div>

      <?= field_text('smtp_from_name', t('From-имя'), (string)Setting::get('mail.from_name', Setting::appName()), [
            'max' => 190, 'hint' => t('Подпись отправителя в почтовых клиентах')]) ?>

      <div class="row mb-16">
        <input type="hidden" name="test_email" value="<?= esc(App\Core\Auth::user()['email'] ?? '') ?>">
        <button class="btn btn-secondary" type="button" data-ajax="smtp-out" data-ajax-url="/admin/settings/smtp-test"><?= esc(t('Отправить тест')) ?></button>
        <span id="smtp-out" class="hint pre-wrap"><?= esc(t('Тест уйдёт на ваш email.')) ?></span>
      </div>
      <?= form_actions() ?>
    </form>
  </div>
