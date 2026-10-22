-- SSO · 009_photos — галерея фотографий аккаунта (v3.8).
--
-- Все четыре соцпровайдера отдают ссылку на фото при каждом входе: Telegram
-- photo_url, VK photo_200, GitHub avatar_url, Яндекс islands-200. До v3.8 она
-- складывалась в linked_identities.meta и не использовалась вообще — данные
-- собирались и выбрасывались. Теперь фото копятся здесь, а человек выбирает,
-- какое из них будет аватаркой.
--
-- Автоподмена аватарки исключена намеренно: раз человек поставил своё фото,
-- вход через Яндекс не должен молча заменять его яндексовым. Галерея
-- пополняется, текущий аватар остаётся тем же (см. App\Models\UserPhoto).
--
-- source_ref — то, из чего фото приехало (URL у провайдера, имя файла у
-- загрузки). Нужен только для одного: не скачивать при каждом входе одно и то
-- же. Провайдер меняет ссылку, когда человек меняет фото, — этого достаточно.

-- Какое фото галереи сейчас стоит аватаркой. Само изображение аватара
-- по-прежнему живёт в users.avatar_path (512/128/32.webp) — эта колонка нужна
-- только чтобы подсветить выбранное в галерее.
ALTER TABLE {users} ADD COLUMN avatar_photo_id BIGINT UNSIGNED NULL;

CREATE TABLE {user_photos} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  -- upload / telegram / vk / yandex / github
  source VARCHAR(20) NOT NULL DEFAULT 'upload',
  source_ref VARCHAR(255) NULL,
  path VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_user_photos_user (user_id),
  -- Одно и то же фото от одного провайдера не заводится дважды
  UNIQUE KEY uq_user_photos_ref (user_id, source, source_ref),
  CONSTRAINT fk_user_photos_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
