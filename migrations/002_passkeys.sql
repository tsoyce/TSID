-- SSO · 002_passkeys — WebAuthn-ключи (passkeys): iCloud Keychain,
-- Google Password Manager, аппаратные токены. Discoverable credentials,
-- вход в т.ч. по QR с телефона (hybrid transport).

CREATE TABLE {passkeys} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  credential_id VARCHAR(255) NOT NULL,
  public_key TEXT NOT NULL,
  alg SMALLINT NOT NULL,
  sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  transports VARCHAR(255) NULL,
  label VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_credential (credential_id),
  KEY ix_user (user_id),
  CONSTRAINT fk_pk_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
