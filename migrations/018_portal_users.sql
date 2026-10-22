-- SSO · 018_portal_users — забрать людей с портала кнопкой (v4.6).
--
-- Импорт файлом (§13.1) закрывает разовый переезд, но требует человека с
-- выгрузкой в руках: выгрузи, скачай, сопоставь колонки, загрузи. Пока портал
-- один, это терпимо. Когда порталов пять и у каждого свой админ — нет.
--
-- Здесь портал сам отдаёт своих людей по запросу, а SSO забирает их кнопкой.
-- Направление именно такое — SSO спрашивает, портал отвечает, — по той же
-- причине, что и с манифестом: портал не должен уметь класть данные к нам по
-- своей инициативе. Он отвечает на вопрос, а решение принимаем мы.
--
-- users_secret хранится ОТКРЫТЫМ, в отличие от client_secret. Иначе нельзя:
-- подписывать запрос должны обе стороны, а из argon2-хэша ключ не достать. Тот
-- же приём, что у webhooks (миграция 004). Ключ отдельный от client_secret
-- намеренно: он живёт в другом файле на портале, отзывается отдельно и не
-- ломает вход, если его сменили.

ALTER TABLE {clients} ADD COLUMN users_url VARCHAR(255) NULL;
ALTER TABLE {clients} ADD COLUMN users_secret VARCHAR(255) NULL;
ALTER TABLE {clients} ADD COLUMN users_count INT UNSIGNED NULL;
ALTER TABLE {clients} ADD COLUMN users_pulled_at DATETIME NULL;

-- Связь «наш человек ↔ его номер на портале». Ради неё всё и затевалось:
-- повторный перенос обязан обновлять уже перенесённых, а не плодить двойников.
-- Портал меняет у себя почту и фамилию, мы забираем ещё раз — и должны попасть
-- в того же человека, даже если совпасть больше не по чему.
--
-- external_id уникален в пределах портала, но не глобально: у двух порталов
-- прекрасно бывает пользователь №1, и это разные люди. Пара (портал, номер) —
-- вот что уникально.
CREATE TABLE {client_user_links} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  external_id VARCHAR(190) NOT NULL,
  linked_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_link_external (client_id, external_id),
  KEY ix_link_user (user_id),
  CONSTRAINT fk_link_client FOREIGN KEY (client_id) REFERENCES {clients}(id) ON DELETE CASCADE,
  CONSTRAINT fk_link_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
