-- SSO · 007_account_data — пол, дополнительные email, след слияния (v3.6).
--
-- Три вещи одного релиза:
--
--  · gender — Яндекс отдаёт пол в профиле, выбрасывать его жалко, а спрашивать
--    заново — глупо. Поле необязательное; обязательность включается политикой.
--  · user_emails — адреса от соцпровайдеров. Вошёл через Яндекс — остался
--    яндексовый адрес, через Google — gmail. Все они подтверждены провайдером
--    и годятся для поиска аккаунта при следующем соцвходе: это главное лекарство
--    от дублей. Основной адрес остаётся в users.email.
--  · merged_into — след слияния дублей: кто в кого влит. Дубль остаётся в базе
--    со статусом deleted, но аудит и история не осиротевают.

ALTER TABLE {users} ADD COLUMN gender VARCHAR(10) NULL;
ALTER TABLE {users} ADD COLUMN merged_into BIGINT UNSIGNED NULL;

CREATE TABLE {user_emails} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  -- откуда адрес: yandex / google / vk / github / merge / manual
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- один адрес — один аккаунт, иначе поиск по нему неоднозначен
  UNIQUE KEY uq_user_emails_email (email),
  KEY ix_user_emails_user (user_id),
  CONSTRAINT fk_user_emails_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
