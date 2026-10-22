-- SSO · 004_ecosystem — релиз v2.3:
-- webhooks для сторонних систем, токены Admin API, настройки соцвхода.

-- WEBHOOKS (универсальный канал наружу; OIDC-backchannel остаётся отдельно) --
CREATE TABLE {webhooks} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  url VARCHAR(255) NOT NULL,
  secret VARCHAR(255) NOT NULL,
  events JSON NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_status VARCHAR(32) NULL,
  last_sent_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {webhook_deliveries} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  webhook_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(48) NOT NULL,
  payload JSON NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  response_code SMALLINT NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_status_next (status, next_attempt_at),
  KEY ix_webhook (webhook_id),
  CONSTRAINT fk_wd_webhook FOREIGN KEY (webhook_id) REFERENCES {webhooks}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ТОКЕНЫ ADMIN API ---------------------------------------------------------
CREATE TABLE {api_tokens} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  prefix VARCHAR(16) NOT NULL,
  scopes JSON NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_token (token_hash),
  KEY ix_prefix (prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
