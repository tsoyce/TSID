-- SSO · 014_permissions — таблица прав у ролей и уровень доступа (v4.3).
--
-- До этого права были зашиты в код: Auth::isAdmin() и Auth::isSuperadmin(),
-- то есть три ступени на всю систему. «Тестировщик, который видит всё, но
-- ничего не меняет» или «поддержка, которая правит людей, но не трогает
-- порталы» в такую схему не помещались вовсе.
--
-- Право — строка вида `область.действие`: users.read, clients.write,
-- settings.delete. Ровно как rwx: строки — области, столбцы — чтение,
-- изменение, удаление. В интерфейсе это сетка галочек, и человек видит всю
-- раскладку разом, а не догадывается по названию роли.
--
-- Отдельной таблицей, а не JSON-колонкой у роли: право нужно спрашивать в
-- запросе («у кого есть users.delete»), а по JSON такое не спросишь ни в
-- MySQL, ни в SQLite одинаково.
--
-- access_level — не право, а сводка для СЕРВИСОВ: одно число 0–99, по
-- которому портал ветвится, не изучая словарь наших ролей. Внутри SSO
-- уровень прав не даёт: решают права из этой таблицы. Разбор — ACCESS_MODEL.md.

-- role_id — ровно BIGINT UNSIGNED, как {roles}.id. MySQL требует совпадения
-- типов в внешнем ключе байт в байт и на несовпадении отвечает «errno: 150
-- Foreign key constraint is incorrectly formed». SQLite на это не смотрит
-- вовсе, поэтому в разработке ошибка не проявлялась.
CREATE TABLE {role_permissions} (
  role_id     BIGINT UNSIGNED NOT NULL,
  perm        VARCHAR(64)  NOT NULL,
  PRIMARY KEY (role_id, perm),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES {roles}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE {roles} ADD COLUMN access_level SMALLINT UNSIGNED NOT NULL DEFAULT 20;
