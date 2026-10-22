<?php /** Раздел настроек «Ключи» (§5.6).
 *  Вырезан из общего экрана: свой адрес, одна форма, одна кнопка. */ ?>
  <div class="bento-card">
    <div class="bc-title"><?= esc(t('Ключи подписи RS256 (§3.4)')) ?></div>
    <div class="stack mb-16">
      <div><?= esc(t('Активный:')) ?> <span class="badge badge-ink mono"><?= esc($activeKid) ?></span>
        · <?= esc(t('возраст :n дн.', ['n' => (int)$keyAge])) ?>
        <?= $keyAge >= 180 ? '<span class="badge badge-warn">' . esc(t('пора ротировать')) . '</span>' : '' ?></div>
      <?php foreach ($registry as $kid => $info): if ($kid === $activeKid) continue; ?>
      <div class="hint mono"><?= esc($kid) ?> — <?= ($info['retired_at'] ?? null) ? esc(t('в JWKS до :d', ['d' => fmt_dt(gmdate('Y-m-d H:i:s', strtotime(($info['retired_at']) . ' UTC') + 48 * 3600))])) : esc(t('история')) ?></div>
      <?php endforeach; ?>
    </div>
    <form method="post" action="/admin/settings/rotate-keys"
          data-confirm="<?= esc(t('Ротировать ключи? Новый начнёт подписывать сразу; старый останется в JWKS 48 часов для валидации хвостов.')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-secondary" type="submit"><?= esc(t('Ротировать ключи')) ?></button>
    </form>
    <p class="hint mt-8"><?= esc(t('Рекомендация — раз в год. «Здоровье» напомнит после 180 дней.')) ?></p>
  </div>
