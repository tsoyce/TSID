<?php /** Редактирование профиля + аватар-пайплайн (§5.1). */ ?>
<div class="page-head"><h1><?= esc(t('Редактирование профиля')) ?></h1></div>

<div class="bento">
  <div class="bento-card col-8">
    <div class="bc-title"><?= esc(t('Личные данные')) ?></div>
    <form method="post" action="/profile/edit">
      <?= csrf_field() ?>
      <div class="grid-2">
        <?= field_text('last_name', t('Фамилия'), $user['last_name'], [
              'max' => 120, 'autocomplete' => 'family-name']) ?>
        <?= field_text('first_name', t('Имя'), $user['first_name'], [
              'max' => 120, 'autocomplete' => 'given-name']) ?>
      </div>

      <?php
      /* Свёрнуто: большинству это не нужно, а кому нужно — тот знает зачем.
         Открыто по умолчанию, если поля уже заполнены. */
      [$guessLast, $guessFirst] = App\Controllers\ProfileController::latinGuess($user);
      $hasLatin = ($user['latin_last_name'] ?? '') !== '' || ($user['latin_first_name'] ?? '') !== '';
      ?>
      <details class="disclosure mb-16"<?= $hasLatin ? ' open' : '' ?>>
        <summary><?= esc(t('ФИО на латинице')) ?></summary>
        <div class="grid-2 mt-12">
          <?= field_text('latin_last_name', t('Фамилия'), $user['latin_last_name'] ?? '', [
                'max' => 120, 'placeholder' => $guessLast, 'autocomplete' => 'family-name', 'class' => 'mb-0']) ?>
          <?= field_text('latin_first_name', t('Имя'), $user['latin_first_name'] ?? '', [
                'max' => 120, 'placeholder' => $guessFirst, 'autocomplete' => 'given-name', 'class' => 'mb-0']) ?>
        </div>
      </details>
      <?php
      /* Обязательность телефона и пола — политика инсталляции, по умолчанию
         выключена: поля просто есть, а заполнять их или нет — решает человек. */
      $needPhone = App\Core\Setting::get('policy.require_phone') === '1';
      $needGender = App\Core\Setting::get('policy.require_gender') === '1';
      ?>
      <div class="grid-2">
        <?php /* Маски здесь нет намеренно: номер набирают в любой стране, и
                 «+7 (###) ###-##-##» отрезал бы всех остальных. Подсказка
                 говорит про код страны, а разбор — на сервере. */ ?>
        <?= field_text('phone', t('Телефон'), $user['phone'] ?? '', [
              'type' => 'tel', 'inputmode' => 'tel', 'autocomplete' => 'tel', 'max' => 32,
              'placeholder' => '+7 900 000-00-00', 'required' => $needPhone,
              'note' => $needPhone ? '' : t('(необязательно)'),
              'hint' => t('С кодом страны')]) ?>
        <?= field_select('gender', t('Пол'), [
              '' => '—', 'male' => t('Мужской'), 'female' => t('Женский'),
            ], (string)($user['gender'] ?? ''), [
              'required' => $needGender,
              'note' => $needGender ? '' : t('(необязательно)')]) ?>
      </div>
      <div class="grid-2">
        <?= field_text('birthdate', t('Дата рождения'), $user['birthdate'], ['type' => 'date']) ?>
        <?php /* Пояснение под полем, а не рядом с подписью: рядом оно
                 переносится на вторую строку, подпись становится выше соседней,
                 и два поля в одном ряду встают на разной высоте. Короткая
                 пометка — рядом, длинное объяснение — снизу. */ ?>
        <?= field_text('display_name', t('Отображаемое имя'), $user['display_name'], [
              'max' => 120, 'hint' => t('Если пусто — Имя Фамилия')]) ?>
      </div>
      <?= form_actions() ?>
    </form>

    <?php $extraEmails = App\Models\UserEmail::forUser((int)$user['id']); ?>
    <?php if ($extraEmails !== []): ?>
    <div class="bc-title mt-24"><?= esc(t('Дополнительные адреса')) ?></div>
    <p class="hint"><?= esc(t('Их оставили привязанные сервисы. По любому из них ваш аккаунт находится при входе — новый дубль не заведётся.')) ?></p>
    <div class="stack">
      <?php foreach ($extraEmails as $e): ?>
      <div class="row-between">
        <span><span class="mono"><?= esc($e['email']) ?></span>
          <span class="badge badge-muted"><?= esc($e['source']) ?></span>
          <?= $e['verified_at'] ? '<span class="badge badge-ok">' . esc(t('подтверждён')) . '</span>' : '' ?></span>
        <form method="post" action="/profile/emails/delete"
              data-confirm="<?= esc(t('Убрать адрес? Вход через сервис с этой почтой может завести новый аккаунт.')) ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
          <button class="btn btn-s btn-danger-ghost" type="submit"><?= esc(t('Убрать')) ?></button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="bento-card col-4">
    <div class="bc-title"><?= esc(t('Аватар')) ?></div>
    <div class="row row-center mb-12">
      <?php if (avatar_url($user)): ?>
        <span class="avatar avatar-96"><img id="avatar-preview" src="<?= esc(avatar_url($user, 512)) ?>" alt=""></span>
      <?php else: ?>
        <span class="avatar avatar-96" style="background:<?= esc(avatar_gradient($user['public_id'])) ?>"><?= esc(user_initials($user)) ?></span>
      <?php endif; ?>
    </div>
    <form method="post" action="/profile/avatar" enctype="multipart/form-data" class="stack">
      <?= csrf_field() ?>
      <label class="btn btn-secondary btn-block pointer">
        <?= esc(t('Выбрать файл')) ?>
        <input type="file" name="avatar" accept="image/*" hidden data-preview="avatar-preview" data-autosubmit="1">
      </label>
    </form>
    <?php if ($user['avatar_path']): ?>
    <form method="post" action="/profile/avatar/delete" class="mt-8">
      <?= csrf_field() ?>
      <button class="btn btn-ghost btn-block" type="submit"><?= esc(t('Убрать фото')) ?></button>
    </form>
    <?php endif; ?>
    <p class="hint mt-8"><?= esc(t('До 5 МБ. Обрежем в квадрат и переконвертируем в webp — EXIF и лишние байты не выживут.')) ?></p>
  </div>

  <?php /* Галерея — отдельной карточкой во всю ширину, а не внутри узкой
           колонки аватара: в плитку шириной 50px не влезает ни подпись
           источника, ни выбор. Сюда же попадают фотографии, которые отдали
           соцсети при входе. Аватарка сама не меняется — выбирает человек. */ ?>
  <?php if (!empty($photos)): ?>
  <div class="bento-card col-12">
    <div class="bc-title"><?= esc(t('Мои фотографии')) ?></div>
    <p class="hint"><?= esc(t('Нажмите на фото, чтобы поставить его аватаркой.')) ?></p>
    <div class="photo-grid mt-12">
      <?php foreach ($photos as $ph): $isCurrent = (int)($user['avatar_photo_id'] ?? 0) === (int)$ph['id']; ?>
      <div class="photo-item<?= $isCurrent ? ' is-current' : '' ?>">
        <?php /* Вся плитка — кнопка: выбор в один клик, без подписей,
                 которые всё равно не поместились бы. */ ?>
        <form method="post" action="/profile/photo/use">
          <?= csrf_field() ?>
          <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
          <button class="photo-pick" type="submit"<?= $isCurrent ? ' disabled' : '' ?>
                  aria-label="<?= esc($isCurrent ? t('Это текущая аватарка') : t('Сделать аватаркой')) ?>">
            <img src="<?= esc(base_url('/photo/' . $user['public_id'] . '/' . basename($ph['path']))) ?>"
                 alt="" loading="lazy" width="120" height="120">
          </button>
        </form>
        <?php if ($isCurrent): ?>
        <span class="photo-badge" title="Текущая аватарка"><?= icon('check', 14) ?></span>
        <?php endif; ?>
        <span class="photo-src"><?= esc(App\Models\UserPhoto::sourceLabel($ph['source'])) ?></span>
        <form method="post" action="/profile/photo/delete" class="photo-del"
              data-confirm="<?= esc(t('Удалить эту фотографию?')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
          <button class="btn-icon btn-icon-s" type="submit"
                  aria-label="<?= esc(t('Удалить фотографию')) ?>"><?= icon('trash', 14) ?></button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
