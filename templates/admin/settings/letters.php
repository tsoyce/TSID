<?php /** Раздел настроек «Письма» (§5.6).
 *  Текст письма — владельца, устройство письма — наше. Граница объяснена в
 *  App\Core\MailTemplates и повторена здесь словами: человек, открывший экран,
 *  не должен идти читать код, чтобы понять, почему кнопку править нельзя. */
use App\Core\MailTemplates;
use App\Core\Setting;
?>
  <form method="post" action="/admin/settings/save">
    <?= csrf_field() ?><input type="hidden" name="section" value="letters">

    <div class="bento">
      <div class="bento-card col-12">
        <div class="bc-title"><?= esc(t('Подпись под всеми письмами')) ?></div>
        <?= field_textarea('footer', t('Подпись'), (string)Setting::get('mail.footer', ''), [
              'rows' => 2, 'placeholder' => MailTemplates::FOOTER_DEFAULT,
              'hint' => t('Стоит внизу каждого письма. Пусто — как в поставке. Сюда обычно ставят адрес поддержки или название организации.')]) ?>
      </div>

      <?php foreach (MailTemplates::LETTERS as $key => $L): ?>
      <div class="bento-card col-6" id="letter-<?= esc($key) ?>">
        <div class="row-between">
          <div class="bc-title mb-0"><?= esc(t($L['title'])) ?></div>
          <?php if (MailTemplates::customized($key)): ?>
            <span class="badge badge-ink"><?= esc(t('свой текст')) ?></span>
          <?php endif; ?>
        </div>
        <p class="hint mt-8"><?= esc(t($L['when'])) ?></p>

        <?= field_text($key . '_subject', t('Тема'), (string)Setting::get('mail.tpl.' . $key . '.subject', ''), [
              'id' => 'f-' . $key . '-subject', 'max' => 200, 'placeholder' => t($L['subject'])]) ?>
        <div class="grid-2">
          <?= field_text($key . '_greeting', t('Приветствие'), (string)Setting::get('mail.tpl.' . $key . '.greeting', ''), [
                'id' => 'f-' . $key . '-greeting', 'max' => 120, 'placeholder' => t($L['greeting'])]) ?>
          <?php /* У письма без кнопки нет и поля для неё (v4.72). */ ?>
          <?php if ($L['button'] !== ''): ?>
          <?= field_text($key . '_button', t('Надпись на кнопке'), (string)Setting::get('mail.tpl.' . $key . '.button', ''), [
                'id' => 'f-' . $key . '-button', 'max' => 60, 'placeholder' => t($L['button'])]) ?>
          <?php endif; ?>
        </div>
        <?= field_textarea($key . '_body', t('Объяснение'), (string)Setting::get('mail.tpl.' . $key . '.body', ''), [
              'id' => 'f-' . $key . '-body', 'rows' => 3, 'placeholder' => t($L['body']),
              'hint' => t('Строка между приветствием и кнопкой. Пусто — как в поставке.')]) ?>

        <?php /* Предпросмотр — настоящим кодом письма, а не пересказом: иначе
                 в админке одно, а в почте другое. */ ?>
        <details class="disclosure">
          <summary><?= esc(t('Посмотреть письмо')) ?></summary>
          <div class="mail-preview mt-12">
            <?php
            $sample = MailTemplates::sample($key);
            [$html] = \App\Core\View::email($key, $sample);
            ?>
            <iframe class="mail-frame" title="<?= esc(t('Предпросмотр письма')) ?>"
                    srcdoc="<?= esc($html) ?>"></iframe>
          </div>
          <p class="hint mt-8"><?= esc(t('Значения выдуманные: настоящие подставятся при отправке.')) ?></p>
        </details>
      </div>
      <?php endforeach; ?>

      <div class="bento-card col-12">
        <div class="bc-title"><?= esc(t('Что подставляется')) ?></div>
        <?php
        /* Сами подстановки не переводятся: это то, что человек набирает в поле
           буква в букву. Объяснения рядом — переводятся. */
        $vars = [
            '{{ Имя }}' => t('Имя получателя. Если его нет, подстановка исчезает вместе с запятой.'),
            '{{ Название }}' => t('Название установки — сейчас «:app».', ['app' => Setting::appName()]),
            '{{ Срок }}' => t('Сколько живёт ссылка. Берётся из раздела «Сроки», а не пишется руками.'),
            '{{ Код }}' => t('Код подтверждения — только в письме с кодом.'),
        ];
        ?>
        <div class="stack">
          <?php foreach ($vars as $token => $why): ?>
          <div class="row-between">
            <span class="mono"><?= esc($token) ?></span>
            <span class="hint"><?= esc($why) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php /* Названо вслух, потому что вопрос возникает первым: «почему я не
                 могу поправить кнопку и приписку про срок». */ ?>
        <p class="hint mt-16"><?= esc(t('Кнопка, запасная ссылка текстом и строка про срок остаются нашими: письмо без рабочей кнопки — это человек, который не может войти. Срок в тексте подставляется из настроек и потому не расходится с действительностью.')) ?></p>
      </div>
    </div>
    <?= form_actions() ?>
  </form>
