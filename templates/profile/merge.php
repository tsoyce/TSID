<?php /** Экран слияния дублей: «Это случайно не вы?» (v3.6). */ ?>
<div class="page-head"><h1><?= esc(t('Объединение аккаунтов')) ?></h1></div>

<div class="bento-card mw-lg">
  <?= callout(t('Этот :provider-аккаунт уже привязан к другой учётной записи. Похоже, соцвход когда-то завёл её автоматически — и это ваш дубль.', ['provider' => $provider]), 'info') ?>

  <div class="merge-pair mt-24">
    <div class="merge-side">
      <div class="bc-title"><?= esc(t('Останется')) ?></div>
      <div class="row"><?= avatar_html($me, 48) ?>
        <div class="flex-1">
          <div class="cell-main"><?= esc(user_name($me)) ?></div>
          <div class="hint"><?= esc($me['email']) ?></div>
        </div></div>
    </div>
    <div class="merge-arrow"><?= icon('chevron-right', 22, 'icon-muted') ?></div>
    <div class="merge-side merge-side-dup">
      <div class="bc-title"><?= esc(t('Вольётся и закроется')) ?></div>
      <div class="row"><?= avatar_html($dup, 48) ?>
        <div class="flex-1">
          <div class="cell-main"><?= esc(user_name($dup)) ?></div>
          <div class="hint mono"><?= esc(mask_email((string)$dup['email'])) ?></div>
          <div class="hint"><?= esc(t('создан :when', ['when' => fmt_dt($dup['created_at'], 'd.m.Y')])) ?></div>
        </div></div>
    </div>
  </div>

  <?php $has = array_filter($dupSummary); ?>
  <?php if ($has !== []): ?>
  <p class="muted mt-16"><?= esc(t('Вместе с ним сюда переедут:')) ?>
    <?php $bits = [];
    if ($dupSummary['accesses']) $bits[] = t(':n доступ(ов) к порталам', ['n' => $dupSummary['accesses']]);
    if ($dupSummary['identities']) $bits[] = t(':n привязанных сервисов', ['n' => $dupSummary['identities']]);
    if ($dupSummary['passkeys']) $bits[] = t(':n passkey', ['n' => $dupSummary['passkeys']]);
    echo esc(implode(' · ', $bits)); ?>.
  </p>
  <?php endif; ?>

  <form method="post" action="/profile/merge" class="mt-16">
    <?= csrf_field() ?>
    <?php if ($needsPassword): ?>
    <?= field_password('dup_password', t('Пароль второго аккаунта'), [
          'id' => 'f-dup_password', 'class' => 'mw-xs', 'autofocus' => true]) ?>
    <?php else: ?>
    <?php /* Пароля у дубля нет: соцвход был единственной дверью, и пройденный
             OAuth уже доказал владение — лишний барьер тут не защита, а помеха */ ?>
    <p class="hint mb-16"><?= esc(t('Вы уже подтвердили владение вторым аккаунтом, войдя через :provider.', ['provider' => $provider])) ?></p>
    <?php endif; ?>
    <div class="row">
      <button class="btn btn-primary" type="submit"><?= esc(t('Да, это я — объединить')) ?></button>
      <a class="btn btn-secondary" href="/profile/merge/cancel"><?= esc(t('Нет, это не я')) ?></a>
    </div>
  </form>

  <p class="hint mt-16"><?= esc(t('Объединение необратимо: второй аккаунт закроется, а его способы входа, доступы и данные переедут в этот.')) ?></p>
</div>
