-- SSO · 012_bridge — «мост» между SSO и порталом (v4.1).
--
-- Портал публикует статический JSON по {base_url}/.well-known/sso-portal.json:
-- своё имя, список своих ролей и параметры оформления. SSO его забирает — и
-- администратору больше не нужно вбивать карту ролей руками, а человек на
-- экране входа видит логотип того сервиса, куда он собрался, а не только наш.
--
-- Брендинг лежит колонками в clients, а не в отдельной таблице: это несколько
-- скалярных значений, которые читаются на каждом показе экрана входа.
--
-- brand_logo — путь к НАШЕЙ копии файла, не ссылка на портал. Ссылка раскрывала
-- бы порталу IP каждого, кто открыл экран входа, включая тех, кто в этот портал
-- не собирался, и картинку по ней можно подменить в любой момент после проверки.

ALTER TABLE {clients} ADD COLUMN manifest_url VARCHAR(255) NULL;
ALTER TABLE {clients} ADD COLUMN manifest_fetched_at DATETIME NULL;
ALTER TABLE {clients} ADD COLUMN brand_accent CHAR(7) NULL;
ALTER TABLE {clients} ADD COLUMN brand_logo VARCHAR(255) NULL;
ALTER TABLE {clients} ADD COLUMN brand_tagline VARCHAR(120) NULL;

-- То, что портал рассказал о себе. Отдельно от client_role_map намеренно:
-- это разные вещи. Здесь — сведения от портала, там — наше решение о том,
-- что кому передавать. Смешивать их значило бы дать порталу управлять
-- доступами через собственный манифест.
CREATE TABLE {client_roles_seen} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(100) NOT NULL,
  title VARCHAR(150) NULL,
  seen_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_seen (client_id, slug),
  KEY ix_roles_seen_client (client_id),
  CONSTRAINT fk_roles_seen_client FOREIGN KEY (client_id) REFERENCES {clients}(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
