-- SSO · 010_legal — записи о согласии с документами (v3.9).
--
-- Тексты документов живут в {settings}: владелец правит их из админки, и они
-- обязаны переживать обновление кода. А вот согласия — здесь, потому что это
-- не настройка, а факт: такой-то человек в такое-то время принял такую-то
-- редакцию такого-то документа.
--
-- doc_version в записи обязателен и хранится числом, а не ссылкой на текущую
-- версию. Иначе запись не значит ничего: документ перепишут, версия вырастет,
-- и «согласился» будет указывать на текст, которого человек не видел.
--
-- UNIQUE по (user_id, doc_key, doc_version): согласие с одной и той же
-- редакцией фиксируется один раз. Новая редакция — новая строка, старая
-- остаётся, потому что история согласий и есть смысл этой таблицы.

CREATE TABLE {legal_consents} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  -- privacy / consent / terms
  doc_key VARCHAR(32) NOT NULL,
  doc_version INT UNSIGNED NOT NULL,
  accepted_at DATETIME NOT NULL,
  ip VARBINARY(16) NULL,
  user_agent VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legal_consent (user_id, doc_key, doc_version),
  KEY ix_legal_user (user_id),
  CONSTRAINT fk_legal_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
