-- Cloudflare Turnstile v0.3.3: emergency staff-login recovery
-- osTicket 1.18.x / MySQL or MariaDB
-- Change ost_ below if your osTicket database uses a different table prefix.
-- Back up your database before running.
--
-- This writes staff_enabled=0 to EVERY instance of this specific plugin.
-- Client login protection is unchanged.
-- Requires a DB account with INSERT and UPDATE on ost_config.

SELECT p.id AS plugin_id, i.id AS instance_id, p.install_path, p.name
FROM ost_plugin AS p
JOIN ost_plugin_instance AS i ON i.plugin_id = p.id
WHERE p.name = 'Cloudflare Turnstile Login Protection'
  AND p.install_path LIKE '%cloudflare-turnstile%';

INSERT INTO ost_config (`namespace`, `key`, `value`)
SELECT CONCAT('plugin.',p.id,'.instance.',i.id), 'staff_enabled', '0'
FROM ost_plugin AS p
JOIN ost_plugin_instance AS i ON i.plugin_id = p.id
WHERE p.name = 'Cloudflare Turnstile Login Protection'
  AND p.install_path LIKE '%cloudflare-turnstile%'
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated` = CURRENT_TIMESTAMP;

SELECT c.`namespace`, c.`key`, c.`value`
FROM ost_config AS c
JOIN ost_plugin AS p
  ON c.`namespace` LIKE CONCAT('plugin.',p.id,'.instance.%')
WHERE p.name = 'Cloudflare Turnstile Login Protection'
  AND p.install_path LIKE '%cloudflare-turnstile%'
  AND c.`key` = 'staff_enabled';
