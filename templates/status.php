<?php /** Человекочитаемый статус (§5.3). */ ?>
<div class="text-center">
  <div class="mb-8"><?= status_icon($ok ? 'ok' : 'warn', 44) ?></div>
  <h2 class="fs-18"><?= esc($ok ? t('Всё работает') : t('Частичная деградация')) ?></h2>
  <p class="muted"><?= esc($ok ? t('Все подсистемы отвечают штатно.') : t('Часть подсистем отвечает медленно или недоступна. Мы уже в курсе.')) ?></p>
  <p class="hint mono"><?= esc(gmdate('c')) ?></p>
</div>
