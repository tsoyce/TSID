<?php /** Раздел настроек «Документы» (§5.6).
 *  Вырезан из общего экрана: свой адрес, одна форма, одна кнопка. */
use App\Core\Legal;
?>
  <div class="bento-card">
    <form method="post" action="/admin/settings/save">
      <?= csrf_field() ?><input type="hidden" name="section" value="docs">
      <?php /* Владелец и контакт жили здесь ВТОРОЙ раз — те же данные, что в
               «Реквизитах». Два места для одних данных расходятся при первой
               правке, и в документ уезжает то из них, о котором забыли. */ ?>
      <?php $reqMissing = App\Core\Requisites::missing(); ?>
      <?php if ($reqMissing !== []): ?>
      <?php /* Текст экранируется здесь, а ссылка вставляется разметкой (v4.83):
               callout экранировал всё вместе, и вместо ссылки на экране стоял
               её HTML-код. */ ?>
      <?= callout(esc(t('Не заполнено в реквизитах: :list. Эти подстановки уйдут в документ пустыми.',
            ['list' => implode(', ', $reqMissing)]))
          . ' <a href="/admin/settings/requisites">' . esc(t('Заполнить →')) . '</a>', 'warning', true) ?>
      <?php else: ?>
      <p class="hint mb-16"><?= esc(t('Данные оператора подставляются из реквизитов.')) ?>
        <a href="/admin/settings/requisites"><?= esc(t('Посмотреть →')) ?></a></p>
      <?php endif; ?>

      <?php /* Заготовки — не юридический документ. Сказать об этом прямо
               обязательно: иначе их выложат как есть. */ ?>
      <div class="callout callout-warning mb-16">
        <?= esc(t('Шаблоны ниже — заготовки. Их нужно прочитать и поправить под себя: за содержание документов отвечает владелец установки.')) ?>
      </div>

      <?php foreach (App\Core\Legal::DOCS as $docKey => $docDef):
        $ver = App\Core\Legal::version($docKey);
        $upd = App\Core\Legal::updatedAt($docKey); ?>
      <div class="bc-title mt-24"><?= esc(App\Core\Legal::title($docKey)) ?>
        <?php if ($ver > 0): ?>
        <span class="badge badge-muted"><?= esc(t('редакция :n', ['n' => $ver])) ?></span>
        <?php endif; ?>
        <?php if (App\Core\Legal::enabled($docKey)): ?>
        <a class="hint" href="/legal/<?= esc($docKey) ?>" target="_blank" rel="noopener"><?= esc(t('открыть')) ?></a>
        <?php endif; ?>
      </div>
      <?= field_toggle('enabled_' . $docKey,
            $docDef['consent']
              ? t('Показывать и требовать согласия при регистрации')
              : t('Показывать в подвале и на странице «О сервисе»'),
            setting('docs.' . $docKey . '.enabled') === '1',
            App\Core\Legal::body($docKey) === '' ? t('Пока текст пуст, документ не показывается независимо от тумблера.') : '') ?>
      <div class="field">
        <?php /* aria-label, а не <label>: название документа уже стоит
                 заголовком карточки, и видимая подпись дублировала бы его. */ ?>
        <textarea class="input mono" name="body_<?= esc($docKey) ?>" rows="10"
                  aria-label="<?= esc(App\Core\Legal::title($docKey)) ?>"
                  placeholder="<?= esc(t('Пусто — документ не показывается')) ?>"><?= esc(App\Core\Legal::body($docKey)) ?></textarea>
        <div class="hint mt-8"><?= esc(t('Разметка: ## заголовок, - список, **жирный**, [текст](ссылка). HTML не поддерживается намеренно.')) ?>
          <?php if ($upd !== null): ?>· <?= esc(t('изменён :date', ['date' => fmt_dt($upd)])) ?><?php endif; ?></div>
      </div>
      <button class="btn btn-secondary btn-s" type="submit" name="template" value="<?= esc($docKey) ?>"
              data-confirm="<?= esc(t('Заменить текст заготовкой? Введённое сейчас потеряется.')) ?>">
        <?= esc(t('Взять шаблон')) ?>
      </button>
      <?php endforeach; ?>

      <p class="hint mt-8"><?= esc(t('Изменение текста поднимает редакцию. Согласия, данные раньше, сохраняют номер той редакции, с которой соглашались.')) ?></p>
      <?= form_actions() ?>
    </form>
  </div>
