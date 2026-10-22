<?php /**
 * Мастер «Подключить сервис» — один на все места, откуда сервис заводят.
 *
 * Мест два: общий список порталов и раздел «Сервисы» внутри организации. Второе
 * и есть обычный порядок работы — человек стоит в своей организации и заводит
 * сервис ей, а не «вообще». Копировать ради этого разметку нельзя: анкета
 * разъедется при первой же правке.
 *
 * $wizardOrgId — организация, которой сервис достанется сразу. null означает
 * «ничья»: так заводят из общего списка, стоя над всеми организациями.
 */
$wizardOrgId = $wizardOrgId ?? null;
?>
<div class="modal-backdrop" id="wizard">
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal-head">
      <h2 class="mb-0"><?= esc(t('Подключить сервис')) ?></h2>
      <button class="btn-icon btn-icon-s" type="button" data-modal-close aria-label="<?= esc(t('Закрыть')) ?>"><?= icon('x', 18) ?></button>
    </div>
    <?php
    /* Одно поле — адрес. Всё остальное выводится из него и правится потом в
       карточке: анкета на десять полей отпугивала на ровном месте, хотя ни
       одно из них при первом подключении менять не нужно. */
    ?>
    <form method="post" action="/admin/clients/create">
      <?= csrf_field() ?>
      <?php if ($wizardOrgId !== null): ?>
        <input type="hidden" name="org_id" value="<?= (int)$wizardOrgId ?>">
      <?php endif; ?>
      <?= field_text('base_url', t('Адрес сервиса'), '', [
            'mono' => true, 'type' => 'url', 'required' => true, 'autofocus' => true,
            'placeholder' => 'https://site.com', 'autocomplete' => 'off', 'inputmode' => 'url']) ?>
      <p class="hint mb-16"><?= esc($wizardOrgId !== null
          ? t('Сервис сразу достанется этой организации. Название, адреса возврата и права подставим сами — всё это можно поменять в карточке.')
          : t('Название, адреса возврата и права подставим сами — всё это можно поменять в карточке.')) ?></p>
      <button class="btn btn-primary btn-block" type="submit"><?= esc(t('Создать')) ?></button>
    </form>
  </div>
</div>
