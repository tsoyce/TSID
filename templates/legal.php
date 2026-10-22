<?php
/**
 * Правовой документ. Переменные: $docKey, $body (готовый HTML), $version, $updatedAt.
 *
 * $body уже прошёл через Markdown::toHtml, где исходник экранирован целиком, —
 * поэтому здесь он выводится без esc(). Это единственное место в проекте, где
 * так можно, и причина названа вслух намеренно.
 */
use App\Core\Legal;
?>
<div class="page-head"><h1><?= esc(Legal::title($docKey)) ?></h1></div>

<div class="bento">
  <div class="bento-card col-12">
    <div class="legal-doc"><?= $body ?></div>
    <?php if ($updatedAt !== null): ?>
    <p class="hint mt-24">
      <?= esc(t('Редакция :n от :date', ['n' => $version, 'date' => fmt_dt($updatedAt, 'd.m.Y')])) ?>
    </p>
    <?php endif; ?>
  </div>
</div>
