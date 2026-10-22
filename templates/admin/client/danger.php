<?php /**
 * Опасная зона портала: удаление. $c, $unlocked, $usersOfClient.
 *
 * Отдельная страница, а не блок в карточке: кнопка, уносящая портал, не должна
 * попадаться на глаза тому, кто зашёл поправить redirect_uri. И за паролем —
 * от чужой открытой вкладки CSRF-токен не защищает.
 *
 * В оболочке карточки (v4.83): колонка разделов остаётся на месте, как у
 * остальных разделов, — раньше страница открывалась без неё.
 */ ?>
<div class="bento">
<div class="bento-card col-12 bordered-danger" id="danger">
  <div class="bc-title"><?= esc(t('Удаление портала')) ?></div>
  <p class="hint"><?= esc(t('Уйдут реквизиты, выданные токены, доступы людей, приглашения и карта ролей. Восстановить нельзя: client_secret хранится хэшем, и прежний уже не воспроизвести. Люди останутся — исчезнет только их доступ к этому порталу.')) ?></p>
  <?php /* Число отдельной строкой, без склоняемого существительного внутри
           фразы: «у :n человек» на русском требует plural(), а подставленное
           русское слово так и уезжало в английский текст. */ ?>
  <?php if ($usersOfClient > 0): ?>
  <p class="hint mt-8"><?= esc(t('Людей с доступом сейчас: :n', ['n' => $usersOfClient])) ?></p>
  <?php endif; ?>

  <?php if (!$unlocked): ?>
  <?= danger_gate('/admin/clients/' . (int)$c['id'] . '/danger', 'mt-16') ?>
  <?php else: ?>
  <form method="post" action="/admin/clients/<?= (int)$c['id'] ?>/action" class="mt-16"
        data-confirm="<?= esc(t('Удалить портал? Это необратимо.')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <?= field_confirm_name(t('Наберите слаг портала — :slug — чтобы подтвердить.', ['slug' => $c['slug']]), $c['slug']) ?>
    <button class="btn btn-danger" type="submit"><?= esc(t('Удалить портал')) ?></button>
  </form>
  <?php endif; ?>
</div>
</div>
