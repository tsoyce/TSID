-- SSO · 011_role_map — карта соответствия ролей порталу (v4.0).
--
-- SSO знает роли по-своему: admin, user, client_admin и то, что завели руками.
-- Сервис на той стороне знает свои: editor, moderator, customer. До сих пор
-- портал получал claim roles со слагами SSO и должен был сам догадываться, что
-- с ними делать, — то есть договорённость держалась в чужой голове.
--
-- Теперь она записана. Роль SSO → строка, которую увидит конкретный портал.
-- Отсутствие строки означает «этому порталу роль не передаётся вообще»: чужие
-- роли не должны утекать туда, где о них знать незачем.
--
-- external_name без UNIQUE по (client_id, external_name) намеренно: две роли SSO
-- вполне могут схлопываться в одну роль портала — так бывает при миграции, когда
-- у нас разделение тоньше, чем у него.

CREATE TABLE {client_role_map} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  external_name VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_map (client_id, role_id),
  KEY ix_role_map_client (client_id),
  CONSTRAINT fk_role_map_client FOREIGN KEY (client_id) REFERENCES {clients}(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_map_role FOREIGN KEY (role_id) REFERENCES {roles}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
