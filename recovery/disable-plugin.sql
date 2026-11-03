-- Cloudflare Turnstile v0.3.3: emergency full plugin disable
-- osTicket 1.18.x / MySQL or MariaDB
-- Change ost_ below if your osTicket database uses a different table prefix.
-- Back up your database before running.
--
-- Disables ONLY this Cloudflare Turnstile plugin, not other plugins.
-- Neither plugin configuration nor keys are deleted.
-- Requires a DB account with UPDATE on ost_plugin.

SELECT id, name, install_path, isactive
FROM ost_plugin
WHERE name = 'Cloudflare Turnstile Login Protection'
  AND install_path LIKE '%cloudflare-turnstile%';

UPDATE ost_plugin
SET isactive = 0
WHERE name = 'Cloudflare Turnstile Login Protection'
  AND install_path LIKE '%cloudflare-turnstile%';

SELECT id, name, install_path, isactive
FROM ost_plugin
WHERE name = 'Cloudflare Turnstile Login Protection'
  AND install_path LIKE '%cloudflare-turnstile%';
