-- SSO · 001_init — полная схема §4 архитектуры v2.0
-- Все токены/коды хранятся как SHA-256; IP — VARBINARY(16); секреты at rest — шифротекст.
-- Отличия от §4 (осознанные): password_hash NULL (импорт без пароля, §13),
-- one_time_tokens.user_id NULL (setup_token клиента до появления пользователя),
-- clients.client_secret_old_* (бесшовная ротация секрета, §5.2),
-- user_client_access.consented_at (память consent для is_trusted=0).

CREATE TABLE {users} (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id         CHAR(36)     NOT NULL,
  login             VARCHAR(64)  NULL,
  email             VARCHAR(255) NOT NULL,
  email_verified_at DATETIME     NULL,
  phone             VARCHAR(20)  NULL,
  phone_verified_at DATETIME     NULL,
  password_hash     VARCHAR(255) NULL,
  password_legacy   TINYINT(1)   NOT NULL DEFAULT 0,
  last_name         VARCHAR(100) NULL,
  first_name        VARCHAR(100) NULL,
  middle_name       VARCHAR(100) NULL,
  display_name      VARCHAR(150) NULL,
  avatar_path       VARCHAR(255) NULL,
  birthdate         DATE         NULL,
  locale            VARCHAR(10)  NOT NULL DEFAULT 'ru',
  timezone          VARCHAR(64)  NOT NULL DEFAULT 'Europe/Moscow',
  is_verified_badge TINYINT(1)   NOT NULL DEFAULT 0,
  status            VARCHAR(16) NOT NULL DEFAULT 'pending',
  totp_secret       VARBINARY(255) NULL,
  totp_enabled_at   DATETIME     NULL,
  failed_logins     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until      DATETIME     NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at     DATETIME NULL,
  deleted_at        DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_public_id (public_id),
  UNIQUE KEY uq_email (email),
  UNIQUE KEY uq_login (login),
  UNIQUE KEY uq_phone (phone),
  KEY ix_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {recovery_codes} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_user (user_id),
  CONSTRAINT fk_rc_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {clients} (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id          VARCHAR(48)  NOT NULL,
  client_secret_hash VARCHAR(255) NOT NULL,
  client_secret_old_hash VARCHAR(255) NULL,
  client_secret_old_expires_at DATETIME NULL,
  name               VARCHAR(100) NOT NULL,
  slug               VARCHAR(50)  NOT NULL,
  description        VARCHAR(255) NULL,
  icon_path          VARCHAR(255) NULL,
  base_url           VARCHAR(255) NOT NULL,
  redirect_uris      JSON NOT NULL,
  post_logout_uris   JSON NOT NULL,
  backchannel_uri    VARCHAR(255) NULL,
  allowed_scopes     JSON NOT NULL,
  is_trusted         TINYINT(1) NOT NULL DEFAULT 1,
  same_site_family   TINYINT(1) NOT NULL DEFAULT 0,
  access_policy      VARCHAR(16) NOT NULL DEFAULT 'open',
  require_2fa        TINYINT(1) NOT NULL DEFAULT 0,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  sort_order         INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_client_id (client_id),
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {user_client_access} (
  user_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  granted_by BIGINT UNSIGNED NULL,
  consented_at DATETIME NULL,
  first_login_at DATETIME NULL,
  last_login_at  DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, client_id),
  KEY ix_client (client_id),
  CONSTRAINT fk_uca_user   FOREIGN KEY (user_id)   REFERENCES {users}(id)   ON DELETE CASCADE,
  CONSTRAINT fk_uca_client FOREIGN KEY (client_id) REFERENCES {clients}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {roles} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NULL,
  slug VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_scope_slug (client_id, slug),
  CONSTRAINT fk_role_client FOREIGN KEY (client_id) REFERENCES {clients}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {user_roles} (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES {roles}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {sessions} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  ip VARBINARY(16) NULL,
  user_agent VARCHAR(255) NULL,
  device_label VARCHAR(100) NULL,
  is_remembered TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash),
  KEY ix_user (user_id),
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {session_clients} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  sid CHAR(36) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  logged_out_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sid (sid),
  KEY ix_session (session_id),
  KEY ix_client (client_id),
  CONSTRAINT fk_sc_sess   FOREIGN KEY (session_id) REFERENCES {sessions}(id) ON DELETE CASCADE,
  CONSTRAINT fk_sc_client FOREIGN KEY (client_id)  REFERENCES {clients}(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {auth_codes} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code_hash CHAR(64) NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  redirect_uri VARCHAR(255) NOT NULL,
  scope VARCHAR(255) NOT NULL,
  nonce VARCHAR(255) NULL,
  code_challenge VARCHAR(128) NOT NULL,
  auth_time DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_code (code_hash),
  KEY ix_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {refresh_tokens} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  family_id CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  scope VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  rotated_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash),
  KEY ix_family (family_id),
  KEY ix_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {one_time_tokens} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  type VARCHAR(32) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  payload JSON NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash),
  KEY ix_user_type (user_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {linked_identities} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL,
  provider_uid VARCHAR(191) NOT NULL,
  meta JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_uid (provider, provider_uid),
  KEY ix_user (user_id),
  CONSTRAINT fk_li_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {client_events} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(32) NOT NULL,
  payload JSON NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_status_next (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {audit_log} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_user_id BIGINT UNSIGNED NULL,
  on_user_id BIGINT UNSIGNED NULL,
  client_id BIGINT UNSIGNED NULL,
  event VARCHAR(48) NOT NULL,
  ip VARBINARY(16) NULL,
  user_agent VARCHAR(255) NULL,
  meta JSON NULL,
  PRIMARY KEY (id),
  KEY ix_event_time (event, created_at),
  KEY ix_on_user (on_user_id, created_at),
  KEY ix_actor (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {rate_limits} (
  bucket VARCHAR(96) NOT NULL,
  points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {mail_queue} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_email VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  body_text MEDIUMTEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {settings} (
  `key` VARCHAR(64) NOT NULL,
  `value` TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {migrations} (
  version VARCHAR(32) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
