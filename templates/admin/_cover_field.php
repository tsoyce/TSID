<?php /**
 * Обложка панели входа (v4.81) — у установки и у портала одной частью; ею же
 * задаётся фон страниц (v4.84).
 *
 * Картинки нужны при любом выборе: одна — снимок, несколько — по кругу, а у
 * видео и YouTube первая — заставка, пока видео грузится, и вместо видео у
 * тех, кто просил «без движения». Поэтому блок картинок виден всегда, а блоки
 * видео и YouTube — только у выбранного (без скрипта видны все).
 *
 * $clientId null — установка, число — портал
 * $owner    вместо $clientId: 'bg' — фон страниц (v4.84)
 * $prefix   приставка полей: cover (по умолчанию) или bg — два редактора на одной странице
 * $label    подпись выбора; $kindHint — пояснение под ним
 * $sound    показывать ли «Кнопку звука» (у фона — нет)
 */
use App\Core\CoverSet;

$owner = $owner ?? ($clientId ?? null);
$prefix = $prefix ?? 'cover';
$set = CoverSet::get($owner);
$scope = CoverSet::scope($owner);
$mb = (int)floor(CoverSet::videoLimit() / 1048576);
$n = static fn(string $name): string => $prefix . '_' . $name;
$id = static fn(string $name): string => $prefix . '-' . $name;
?>
<div class="cover-edit" data-cover-edit="<?= esc($prefix) ?>">
  <?= field_choices($n('kind'), $label ?? t('Обложка'), [
        'images' => ['title' => t('Картинки')],
        'video' => ['title' => t('Видео файлом')],
        'youtube' => ['title' => t('Видео с YouTube')],
      ], $set['kind'], ['class' => 'choices-seg', 'id' => $id('kind'),
        'hint' => $kindHint ?? t('Видео идёт без звука и по кругу. На телефоне панели нет — там весь экран у формы.')]) ?>

  <div class="field">
    <label for="<?= esc($id('add')) ?>"><?= esc(t('Картинки')) ?>
      <span class="hint"><?= esc(t('(до :n, по 6 МБ)', ['n' => CoverSet::MAX_IMAGES])) ?></span></label>
    <?php if ($set['images'] !== []): ?>
    <div class="cover-tiles">
      <?php foreach ($set['images'] as $i => $img): ?>
      <label class="cover-tile" title="<?= esc(t('Отметьте, чтобы убрать')) ?>">
        <input type="checkbox" name="<?= esc($n('drop')) ?>[]" value="<?= esc($img) ?>">
        <img src="<?= esc(CoverSet::url($scope, $img, 'webp')) ?>" alt="" loading="lazy">
        <span class="cover-tile-mark"><?= icon('x', 14) ?><span class="sr-only"><?= esc(t('Убрать картинку :n', ['n' => $i + 1])) ?></span></span>
        <?php if ($i === 0): ?><span class="cover-tile-first"><?= esc(t('первая')) ?></span><?php endif; ?>
      </label>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <input class="input" id="<?= esc($id('add')) ?>" type="file" name="<?= esc($n('add')) ?>[]" multiple accept="image/*">
    <p class="hint"><?= esc($set['images'] === []
        ? t('Можно выбрать несколько сразу. Одна — снимок, несколько — сменяются по кругу. Уложим в 1600×1400 и перекодируем.')
        : t('Отмеченные крестиком уберутся при сохранении, новые встанут в конец. Первая — заставка видео и картинка портала на витрине.')) ?></p>
  </div>

  <div data-cover-for="images">
    <?= field_choices($n('motion'), t('Движение'), [
          'fade' => ['title' => t('Смена кадров')],
          'drift' => ['title' => t('Живые обои')],
        ], $set['motion'], ['class' => 'choices-seg', 'id' => $id('motion'),
          'hint' => t('Живые обои — кадр медленно приближается и плывёт. Кто просил в системе «без движения», увидит первый кадр неподвижным.')]) ?>
    <?= field_choices($n('every'), t('Кадр держится'), [
          '5' => ['title' => t(':n с', ['n' => 5])],
          '8' => ['title' => t(':n с', ['n' => 8])],
          '12' => ['title' => t(':n с', ['n' => 12])],
        ], (string)$set['every'], ['class' => 'choices-seg', 'id' => $id('every')]) ?>
  </div>

  <div class="field" data-cover-for="video">
    <label for="<?= esc($id('video')) ?>"><?= esc(t('Видео')) ?>
      <span class="hint"><?= esc(t('(MP4 или WebM, до :mb МБ)', ['mb' => $mb])) ?></span></label>
    <?php if ($set['video'] !== null): ?>
    <video class="cover-video-sample" src="<?= esc(CoverSet::url($scope, $set['video']['id'], $set['video']['ext'])) ?>" muted controls preload="metadata"></video>
    <?php endif; ?>
    <input class="input" id="<?= esc($id('video')) ?>" type="file" name="<?= esc($n('video')) ?>" accept="video/mp4,video/webm">
    <?php if ($set['video'] !== null): ?>
    <label class="checkbox mt-8"><input type="checkbox" name="<?= esc($n('video_delete')) ?>" value="1"><span><?= esc(t('Убрать видео')) ?></span></label>
    <?php endif; ?>
    <p class="hint"><?= esc(t('Файл отдаётся с этого сайта. Сжимайте заранее: полминуты в 1080p — это 10–20 МБ, и столько скачает каждый, кто откроет вход.')) ?></p>
  </div>

  <div data-cover-for="youtube">
    <?= field_text($n('youtube'), t('Ссылка на видео YouTube'), $set['youtube'] !== '' ? 'https://youtu.be/' . $set['youtube'] : '', [
          'id' => $id('youtube'), 'placeholder' => 'https://www.youtube.com/watch?v=…',
          'hint' => t('Показывается с youtube-nocookie.com: браузер посетителя обращается к YouTube (Google), и тот видит его адрес. Пока видео грузится — первая картинка.')]) ?>
  </div>

  <?php if ($sound ?? true): ?>
  <?php /* Звук (v4.83): включить его сразу нельзя — видео со звуком браузеры
           не запускают без нажатия, — поэтому звук включает посетитель. */ ?>
  <div data-cover-for="video youtube">
    <input type="hidden" name="<?= esc($n('sound')) ?>" value="0">
    <?= field_toggle($n('sound'), t('Кнопка звука'), $set['sound'],
          t('Видео начинает без звука: со звуком браузеры его не запускают, пока человек ничего не нажал. Кнопка в углу панели даёт посетителю включить звук самому.'),
          ['id' => $id('sound')]) ?>
  </div>
  <?php endif; ?>
</div>
