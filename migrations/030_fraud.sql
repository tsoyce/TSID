-- SSO · 030_fraud — архив образцов ботов для антифрода (v4.98).
--
-- Живой случай 23.10: шестьдесят четыре бота удалили пачкой, и от них не
-- осталось ничего, на чём учиться. Аудит пережил аккаунты, но в нём не
-- записано, что это были боты: удалённый бот и удалённый человек в журнале
-- неотличимы, а журнал активности уходит вместе с аккаунтом.
--
-- Здесь — обезличенный образец на каждого удалённого как бота: откуда шёл
-- (подсеть, а не адрес), чем притворялся (строка браузера), какой домен почты,
-- какие признаки сработали и как он вёл себя (сколько запросов, куда, за какое
-- время). Ни ящика, ни имени, ни полного адреса, ни номера аккаунта: образец
-- нельзя связать с человеком, чью почту бот подставил. Срок хранения —
-- retention.fraud_days (по умолчанию 365, 0 — вечно).
--
--   label          что это: bot — удалён администратором как бот;
--   score          оценка риска на момент удаления, 0–100;
--   signals        коды сработавших признаков (JSON-список);
--   activity       поведение: {"requests":…, "span_s":…, "paths":{…}, "statuses":{…}}.

CREATE TABLE {fraud_samples} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  archived_at DATETIME NOT NULL,
  registered_at DATETIME NULL,
  label VARCHAR(16) NOT NULL,
  net VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  outdated VARCHAR(32) NULL,
  email_domain VARCHAR(128) NULL,
  score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  signals JSON NULL,
  activity JSON NULL,
  PRIMARY KEY (id),
  KEY ix_fraud_time (archived_at),
  KEY ix_fraud_net (net)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
