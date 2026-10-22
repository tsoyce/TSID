-- SSO · 029_guard — подтверждение почты, блокировки по IP, журнал активности (v4.72).
--
-- Живой случай 21.09: человек заводит аккаунты на адреса-псевдонимы и
-- настойчиво входит с одного адреса, а по журналу не понять, что он делал.

-- Аккаунт, заведённый регистрацией при включённом «подтверждать почту»:
-- войти он может только после подтверждения. Флаг, а не «статус pending»:
-- pending носят и перенесённые с порталов, и зарегистрированные раньше, — их
-- новое правило не касается. 1 — ждёт подтверждения, 0 — как все.
ALTER TABLE {users} ADD COLUMN email_gate TINYINT(1) NOT NULL DEFAULT 0;

-- Блокировки адресов. Хранится диапазон: одиночный адрес — диапазон из одного.
-- ip_from/ip_to — упакованные адреса (inet_pton), одной длины: IPv4 и IPv6 не
-- сравниваются между собой. rule — как записал администратор: 1.2.3.4 или
-- 1.2.3.0/24. expires_at NULL — бессрочно.
CREATE TABLE {ip_blocks} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rule VARCHAR(64) NOT NULL,
  ip_from VARBINARY(16) NOT NULL,
  ip_to VARBINARY(16) NOT NULL,
  reason VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  last_hit_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_block_rule (rule),
  KEY ix_block_from (ip_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал активности: каждый запрос к приложению — кто, откуда, что открыл,
-- чем ответили. Аудит пишет события («вошёл», «сменил пароль»), а между ними
-- было не видно ничего: какие страницы человек открывал, куда стучался без
-- прав, что отправлял. Секретов здесь нет: из адреса вырезаются токены, из
-- строки запроса остаются только имена параметров. Хранится по сроку из
-- настроек (по умолчанию 30 дней).
CREATE TABLE {activity_log} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  ip VARBINARY(16) NULL,
  method VARCHAR(8) NOT NULL,
  path VARCHAR(255) NOT NULL,
  query_keys VARCHAR(255) NULL,
  status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  ms INT UNSIGNED NOT NULL DEFAULT 0,
  user_agent VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_activity_user (user_id, created_at),
  KEY ix_activity_ip (ip, created_at),
  KEY ix_activity_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Как портал открывает вход и показывать ли «Вы вошли» перед возвратом.
-- login_display: NULL — как задумано на портале, popup — окном поверх сайта,
-- page — всей страницей. login_welcome: 1 — короткий экран «Вы вошли».
ALTER TABLE {clients} ADD COLUMN login_display VARCHAR(8) NULL;
ALTER TABLE {clients} ADD COLUMN login_welcome TINYINT(1) NOT NULL DEFAULT 0;
