<?php
/**
 * «Создать аккаунт?» — между подтверждением личности провайдером и созданием
 * учётной записи. Переменные: $providerTitle, $willGet, $docs.
 *
 * До v3.9 этого экрана не было: соцвход заводил аккаунт молча, и человек узнавал
 * о нём постфактум.
 */
?>
<?= auth_head(t('Создать аккаунт?'), t(':provider подтвердил вашу личность.', ['provider' => $providerTitle])
    . "\n" . t('Аккаунта в :app ещё нет.', ['app' => App\Core\Setting::appName()]), true) ?>

<?php if (!empty($dup)): ?>
<?php /* Похоже, аккаунт уже есть (v4.88). Главное действие — войти в него: сервис
         привяжется после входа, а новый аккаунт остаётся запасным путём. */ ?>
<div class="callout callout-info mt-16" data-dup-hint>
  <div class="flex-1">
    <div class="fw-600 mb-4"><?= esc(t('Похоже, у вас уже есть аккаунт')) ?></div>
    <div class="mb-12"><?= esc(App\Core\DuplicateHint::describe($dup['user'])) ?> ·
      <span class="hint"><?= esc($dup['why'] === 'phone' ? t('совпал номер телефона') : t('совпали фамилия, имя и дата рождения')) ?></span></div>
    <a class="btn btn-primary btn-block" href="/social/signup/link"><?= esc(t('Войти в него и привязать :provider', ['provider' => $providerTitle])) ?></a>
  </div>
</div>
<?php endif; ?>

<?php if ($willGet !== []): ?>
<div class="callout mt-16">
  <div class="hint mb-8"><?= esc(t('Что приедет от :provider', ['provider' => $providerTitle])) ?></div>
  <ul class="signup-data">
    <?php foreach ($willGet as $item): ?>
    <li><?= esc($item) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" action="/social/signup" class="mt-16">
  <?= csrf_field() ?>
  <?php /* Галочки не предустановлены: согласие, проставленное за человека, —
           не согласие. Без каждой из них форма не отправляется. */ ?>
  <?php foreach ($docs as $docKey => $doc): ?>
  <label class="checkbox mb-8">
    <input type="checkbox" name="accept[]" value="<?= esc($docKey) ?>" required>
    <span><?= esc(t('Принимаю')) ?>
      <a href="/legal/<?= esc($docKey) ?>" target="_blank" rel="noopener"><?= esc($doc['acc']) ?></a></span>
  </label>
  <?php endforeach; ?>

  <button class="btn <?= empty($dup) ? 'btn-primary' : 'btn-secondary' ?> btn-block mt-16" type="submit"><?= esc(empty($dup) ? t('Создать аккаунт') : t('Нет, создать новый аккаунт')) ?></button>
</form>
<p class="text-center mt-16">
  <a class="hint" href="/social/signup/cancel"><?= esc(t('Отмена')) ?></a>
</p>
