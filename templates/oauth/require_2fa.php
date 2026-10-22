<?php /** Портал требует 2FA (§6.1 require_2fa): step-up. */ ?>
<div class="text-center">
  <div class="mb-8"><?= icon('lock', 42, 'icon-ink') ?></div>
  <?= auth_head(t('Нужна двухфакторная защита'),
      t('Портал «:name» пускает только с включённой 2FA. Настройте её — это пара минут, — и вход продолжится автоматически.',
        ['name' => $client['name']]), true) ?>
  <a class="btn btn-primary btn-block" href="/profile/security"><?= esc(t('Включить 2FA')) ?></a>
</div>
