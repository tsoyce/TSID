-- SSO · 031_indexes — индексы под частые выборки (v5.6).
--
-- Страница адреса и журнал по адресу ищут audit_log по ip, а пульт, /metrics и
-- чистка — sessions по expires_at. Индексов не было: на живой базе это полный
-- проход по самым большим таблицам на каждый такой запрос.

CREATE INDEX {audit_log}_ix_ip ON {audit_log} (ip, id);
CREATE INDEX {sessions}_ix_expires ON {sessions} (expires_at);
