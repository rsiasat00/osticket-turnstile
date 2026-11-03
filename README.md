# Cloudflare Turnstile Login Protection

**Version:** 0.3.7  
**Author:** Ralph Julian Siasat  
**Minimum osTicket version:** 1.18  
**Environment used for development:** osTicket 1.18.1, PHP 8.2

Plug-and-play Cloudflare Turnstile verification for agent and client password-based logins.

## Installation

1. Copy `include/plugins/cloudflare-turnstile/` into your osTicket application's `include/plugins/` folder.
2. In **Admin Panel → Manage → Plugins**, install Cloudflare Turnstile Login Protection and configure a plugin instance.
3. Enter the Cloudflare **Site Key**, **Secret Key**, and **Expected hostname**; choose whether to protect staff and/or client password logins.
4. Enable the plugin instance. If applicable, clear PHP OPcache before testing.
5. Confirm successful and rejected logins on both `/scp/login.php` and `/login.php`.

No changes to osTicket core files or a patch script are required. The plugin uses the osTicket bootstrap and buffered login-page output to place Turnstile within the existing forms.

## Emergency recovery using SQL

If Cloudflare Turnstile fails, run one of the supplied SQL scripts using your usual
MySQL/MariaDB administration tool. This works with Docker, shared hosting, or traditional
web-server installations. Back up your database first.

1. Confirm your osTicket database table prefix (usually `ost_`). If different, replace
   `ost_` in the chosen SQL file before running it.
2. For staff-only access, run `recovery/disable-staff-turnstile.sql`. It sets
   `staff_enabled=0` in **all instances of this plugin**, leaving client protection on.
   It also inserts the setting if the row is absent.
3. If the plugin itself needs to be disabled, run `recovery/disable-plugin.sql`.
   It sets this plugin's `isactive=0`, leaving other plugins untouched.
4. Start a fresh login request (and clear server-side PHP/session caches if a stale
   configuration is observed), sign in normally, then repair Turnstile from the admin UI.

Both scripts SELECT the matching records before and after the change for verification.
They target the plugin by its installed name and path; if the first SELECT shows no
matching plugin, **do not guess an ID**. Check the prefix and installed path first.

Normal osTicket agent username/password and configured 2FA are still required.
Restrict agent login to trusted IPs or VPN while emergency protection is disabled.
Re-enable the plugin/setting from the osTicket admin UI once repaired.

If the plugin PHP itself has a fatal error, the full-disable script is the preferred
first recovery action; if a PHP class fails to load before plugin status is checked,
restoring a known-good plugin file may additionally be required.

## Settings

- **Site Key:** Public Turnstile site key, rendered on protected pages.
- **Secret Key:** Masked in plugin settings; leaving it blank on an existing instance preserves the saved value. Masking is **not** encryption at rest.
- **Expected hostname:** Trusted public browser hostname (for example `osticket.example.com`), without `https://`, port, or path. The Cloudflare Siteverify response must match this setting. Do not substitute the untrusted incoming HTTP `Host` header.
- **Staff login / Client login:** Independently enable Turnstile for normal username/password authentication.

## Security and compatibility notes

- Built against the osTicket 1.18.1 login markup; the manifest sets a minimum of **1.18**, but this does **not** establish compatibility with every later minor release. Revalidate injection behavior after osTicket upgrades.
- The plugin checks Turnstile submissions using Cloudflare's server-side `siteverify` API and validates the returned hostname and action.
- The scope is **normal password logins only**. SSO/external authentication, staff 2FA submissions, ticket-number access, and password-reset flows are not covered.
- The plugin uses output buffering and matching against login HTML because osTicket 1.18.1 lacks dedicated login-widget injection hooks. A change in login templates may require updating the plugin.
- Enabled but incomplete configuration fails closed for protected password submissions. Keep an administrative rollback route available before deployment.
- The secret is stored in the osTicket configuration database. Protect database credentials, backups, and administrator access.
- Cloudflare Turnstile needs `https://challenges.cloudflare.com` permitted by the effective CSP `script-src` and `frame-src`. The plugin adjusts PHP-originated CSP on protected pages; independent proxy/CDN headers may need separate updates.
- Disable caching on login pages and retain server/proxy rate-limiting.

## Deployment checks

1. Open both login pages and confirm the widget is present when the corresponding option is enabled.
2. Confirm successful challenges and an actual successful password login.
3. Submit without a Turnstile token; confirm login is rejected before credential checking.
4. Test incorrect-password retries (the client widget must reappear immediately after the error), staff AJAX responses, and any configured 2FA/SSO flows.
5. Disable the plugin instance and confirm the unmodified login pages work.

**Cloudflare troubleshooting:** If a challenge displays **“Unable to connect to website”**, check allowed hostnames, browser network failures, CSP, browser extensions, and reverse-proxy response headers. Confirm successful Siteverify responses on the deployed hostname before enabling enforcement for all users.

## Production deployment checklist

1. Commit the `include/plugins/cloudflare-turnstile` directory and the `recovery` SQL scripts to your Git repository. Do **not** commit production Site Keys, Secret Keys, or database dumps.
2. Back up the osTicket database and the currently deployed plugin files. Deploy to QA first using the actual public QA hostname and keys authorized for it.
3. Confirm the plugin configuration can be saved and re-edited without exposing or clearing the Secret Key.
4. Test both login pages: widget display, valid credentials plus valid Turnstile, incorrect credentials, missing token rejection, expired/reused token rejection, and staff AJAX retry.
5. Test the emergency SQL scripts in a non-production environment with your installation's real database prefix. Confirm staff-only recovery, full disable, and normal login after recovery.
6. Deploy to production during a controlled change window with database access available to recover administrator login. Enable and test staff login before enabling client login enforcement.
7. Check PHP/HTTP error logs, CSP failures, Turnstile network calls, and osTicket login behavior. If verification fails, run the staff-only emergency script, then investigate.

**Bug fix in v0.3.7:** Client password POSTs that pass Turnstile verification now retain response injection, so a failed password attempt displays a fresh widget on the same login page. Staff AJAX behavior is unchanged.

**Release status:** The package passes static PHP syntax checks. Production readiness depends on the above environment-specific integration and recovery tests; these have not been performed against your deployed server here.
