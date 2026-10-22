-- SSO · 003_trust — релиз v2.2 «Доверие»:
-- уведомления безопасности, реестр известных устройств, инвайт-ссылки,
-- задания на экспорт данных. Плюс индекс для аудита на больших объёмах.

-- НАСТРОЙКИ УВЕДОМЛЕНИЙ (всё опционально, дефолты — в коде) -----------------
CREATE TABLE {notification_prefs} (
  user_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(32) NOT NULL,               -- login.new_device | password.change | …
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (user_id, event),
  CONSTRAINT fk_np_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ИЗВЕСТНЫЕ УСТРОЙСТВА (чтобы отличить новый вход от привычного) -------------
CREATE TABLE {known_devices} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  fingerprint CHAR(64) NOT NULL,            -- sha256(device_label + подсеть /24)
  device_label VARCHAR(100) NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_fp (user_id, fingerprint),
  CONSTRAINT fk_kd_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ИНВАЙТ-ССЫЛКИ (закрывают access_policy = invite, §9.1) --------------------
CREATE TABLE {invites} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NULL,             -- роль, выдаваемая вместе с доступом
  email VARCHAR(255) NULL,                  -- если задан — принять может только он
  max_uses SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(100) NULL,
  created_by BIGINT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invite_token (token_hash),
  KEY ix_client (client_id),
  CONSTRAINT fk_inv_client FOREIGN KEY (client_id) REFERENCES {clients}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ЗАДАНИЯ НА ЭКСПОРТ ДАННЫХ -------------------------------------------------
CREATE TABLE {export_jobs} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  token_hash CHAR(64) NOT NULL,             -- ссылка на скачивание
  file_path VARCHAR(255) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ready_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_export_token (token_hash),
  KEY ix_user_status (user_id, status),
  CONSTRAINT fk_ej_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Аудит: отдельный индекс по времени — чистка и выборка «за период» на 1M+ записей
CREATE INDEX {audit_log}_ix_created ON {audit_log} (created_at);
