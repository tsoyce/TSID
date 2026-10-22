<?php /**
 * Проверки: что у установки под ногами (§11.3).
 *
 * Обе карточки одной ширины. Раньше первая стояла в `mw-xl` (720px), а вторая
 * в `col-12` — то есть во всю ширину: на одном экране два блока про одно и то
 * же были разного размера, и это читалось как небрежность. Пояснение про
 * публичный /status тоже жило между карточками голым абзацем — теперь оно там,
 * к чему относится.
 */ ?>
<div class="bento-card">
  <div class="bc-title"><?= esc(t('Что проверено')) ?></div>
<?php foreach ($checks as $c): ?>
  <div class="health-row">
    <span class="health-icon"><?= status_icon($c['status']) ?></span>
    <div>
      <div class="cell-main"><?= esc($c['label']) ?></div>
      <?php if ($c['note'] !== ''): ?><div class="hint"><?= esc($c['note']) ?></div><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
  <p class="hint mt-16"><?= esc(t('Публичный')) ?> <a href="/status">/status</a> <?= esc(t('отдаёт только ok/degraded — деталями наружу не светим (§11.3).')) ?></p>
</div>

<?php /* Токен для машинного опроса. Отдельной карточкой: это не проверка,
         а ключ, и путать их не надо. Показывается целиком — он не секрет
         уровня пароля, но и не публичен: по нему видно устройство установки. */ ?>
<div class="bento-card mt-16">
  <div class="bc-title"><?= esc(t('Опрос состояния машиной')) ?></div>
  <p class="muted"><?= esc(t('Адрес /health.json отдаёт то же самое в JSON: проверки, целость поставки, сводку ошибок. Нужен, чтобы состояние можно было спросить, а не пересказывать.')) ?></p>
  <?php if ($apiToken !== ''): ?>
    <?= field_copy('health-token', t('Ссылка для опроса'),
          base_url('/health.json?token=' . $apiToken),
          ['hint' => t('Добавьте &probe=1, чтобы заодно прозвонить адреса и порталы — это дольше и ходит наружу.')]) ?>
    <form method="post" action="/admin/health/token" class="mt-8"
          data-confirm="<?= esc(t('Выдать новый токен? Старый перестанет работать сразу.')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="rotate">
      <button class="btn btn-secondary btn-s" type="submit"><?= esc(t('Выдать новый')) ?></button>
    </form>
  <?php else: ?>
    <form method="post" action="/admin/health/token" class="row">
      <?= csrf_field() ?><input type="hidden" name="action" value="rotate">
      <?= field_submit(t('Выдать токен')) ?>
    </form>
  <?php endif; ?>
</div>
