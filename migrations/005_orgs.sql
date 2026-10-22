-- SSO · 005_orgs — организации и группы (релиз v3.0).
-- Сценарий: SSO не только для своих порталов, но и для компании.
-- Доступ к порталу можно выдать организации целиком — все её члены получают вход.

CREATE TABLE {orgs} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(50) NOT NULL,
  description VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_org_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {org_members} (
  org_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'member',
  added_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (org_id, user_id),
  KEY ix_user (user_id),
  CONSTRAINT fk_om_org FOREIGN KEY (org_id) REFERENCES {orgs}(id) ON DELETE CASCADE,
  CONSTRAINT fk_om_user FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {org_client_access} (
  org_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NULL,
  granted_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (org_id, client_id),
  KEY ix_client (client_id),
  CONSTRAINT fk_oca_org FOREIGN KEY (org_id) REFERENCES {orgs}(id) ON DELETE CASCADE,
  CONSTRAINT fk_oca_client FOREIGN KEY (client_id) REFERENCES {clients}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
