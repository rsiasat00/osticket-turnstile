<?php
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(__DIR__ . '/config.php');

/** osTicket 1.18+ login integration without modifying core files. */
class CloudflareTurnstilePlugin extends Plugin {
    var $config_class = 'CloudflareTurnstileConfig';

    function bootstrap() {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $kind = preg_match('~/scp/login\.php$~i', $script) ? 'staff'
            : (preg_match('~(?:^|/)login\.php$~i', $script) ? 'client' : null);
        if (!$kind) return;
        $cfg = $this->getConfig();
        if (!$cfg->get($kind . '_enabled')) return;

        // Only protect the password flows; never interfere with staff 2FA or SSO.
        $passwordPost = $kind === 'staff' ? isset($_POST['userid']) : isset($_POST['luser']);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST' && $passwordPost) {
            // Validate this submission before osTicket tries the credentials.
            // Client logins are rendered again on the same POST when the password
            // is incorrect. Keep buffering for that response so the fresh login
            // form gets a new Turnstile widget; a successful login redirects.
            if (!$this->verify($cfg, $kind)) self::reject($kind);
            if ($kind === 'staff') return; // Staff login is AJAX; leave unchanged.
        } elseif ($method !== 'GET') {
            // Do not inject a challenge into unrelated POSTs or methods.
            return;
        }

        $siteKey = trim((string)$cfg->get('site_key'));
        $secret = trim((string)$cfg->get('secret_key'));
        // A config error must not result in an unprotected password POST.
        ob_start(function ($html) use ($siteKey, $secret, $kind) {
            return self::inject($html, $siteKey, $kind, $secret !== '');
        });
    }

    private static function inject($html, $siteKey, $kind, $hasSecret) {
        // Never inject into a JSON response, redirect, or irrelevant form.
        if (!$html || stripos($html, '<form') === false) return $html;
        if ($kind === 'staff' && stripos($html, 'name="do" value="2fa"') !== false) return $html;

        $action = $kind === 'staff' ? 'staff-login' : 'client-login';
        $widget = '<div class="ost-turnstile" style="margin:12px 0 6px 0;max-width:100%;">';
        if ($siteKey !== '' && $hasSecret) {
            $widget .= '<div class="cf-turnstile" data-sitekey="'
                . htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8')
                . '" data-action="' . $action . '" data-size="'
                . 'flexible' . '"></div>';
        } else {
            $widget .= '<p role="alert">Turnstile is not configured. Contact an administrator.</p>';
        }
        $widget .= '</div>';

        // osTicket sends CSP while rendering its login header. Extend that
        // existing policy before output is flushed to the browser.
        self::allowTurnstileCsp();

        $injected = false;
        if ($kind === 'staff') {
            // Limit this to the password form; avoid the 2FA submit button.
            $pattern = '~(<form\b[^>]*\bid=["\']login["\'][^>]*>.*?<input\b[^>]*\bname=["\']userid["\'][^>]*>.*?)(<button\b[^>]*\btype=["\']submit["\'][^>]*>)~is';
            $html = preg_replace_callback($pattern, function ($matches) use ($widget) {
                return $matches[1] . $widget . $matches[2];
            }, $html, 1, $count);
            $injected = ($count === 1);
        } else {
            // Locate the client form, password field and its submit control.
            // Do not depend on input attribute order or a particular <p> tag.
            $html = preg_replace_callback(
                '~<form\b(?=[^>]*\bid\s*=\s*"clientLogin")[^>]*>.*?</form\s*>~is',
                function ($match) use ($widget, &$injected) {
                    $form = $match[0];
                    if (!preg_match('~<input\b(?=[^>]*\bname\s*=\s*"lpasswd")[^>]*>~is', $form, $password, PREG_OFFSET_CAPTURE)) {
                        return $form;
                    }
                    $afterPassword = $password[0][1] + strlen($password[0][0]);
                    if (!preg_match('~<input\b(?=[^>]*\btype\s*=\s*"submit")[^>]*>~is', $form, $submit, PREG_OFFSET_CAPTURE, $afterPassword)) {
                        return $form;
                    }
                    $submitPos = $submit[0][1];
                    $between = substr($form, $afterPassword, $submitPos - $afterPassword);
                    // Prefer before the paragraph wrapping the Sign In button.
                    // This keeps the widget inside the left login panel.
                    $position = $submitPos;
                    if (preg_match_all('~<p\b[^>]*>~is', $between, $paras, PREG_OFFSET_CAPTURE)) {
                        $last = end($paras[0]);
                        $position = $afterPassword + $last[1];
                    }
                    $injected = true;
                    return substr($form, 0, $position) . $widget . substr($form, $position);
                },
                $html,
                1
            );
        }

        // If the expected anchor changes in a future osTicket update, leave the page
        // untouched rather than placing the widget in the wrong area.
        if (!$injected) return $html;

        if ($kind === 'staff') {
            // Scoped override, only when staff login protection is enabled.
            $css = '<style id="ost-turnstile-staff-button-style">'
                . '#loginBody form#login button, #loginBody input[value="Send Email"] {'
                . 'float:initial; margin:0 auto 21px auto !important;}'
                . '#loginBody form#login .ost-turnstile {'
                . 'width:100%;max-width:100%;display:flex;justify-content:center;}'
                . '</style>';
            $html = preg_replace('~</head>~i', $css . '</head>', $html, 1, $styleCount);
            if (!$styleCount) $html .= $css;
        }
        if ($kind === 'client') {
            // Cloudflare's flexible widget needs approximately 300px minimum.
            // Keep its width independent of the site's narrower input fields.
            $css = '<style id="ost-turnstile-client-width">'
                . 'form#clientLogin .ost-turnstile {'
                . 'width:300px;max-width:100%;margin:12px auto 6px auto !important;'
                . 'box-sizing:border-box;}'
                . '</style>';
            $html = preg_replace('~</head>~i', $css . '</head>', $html, 1, $styleCount);
            if (!$styleCount) $html .= $css;
        }
        if ($siteKey !== '' && $hasSecret) {
            $js = '';
            $js .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
            // Staff form uses AJAX, so its challenge must reset after failed authentication.
            if ($kind === 'staff') {
                $js .= '<script>document.addEventListener("DOMContentLoaded",function(){if(window.jQuery){'
                    . 'jQuery(document).ajaxComplete(function(e,x,o){if(o && o.url && /login\.php/.test(o.url) '
                    . '&& window.turnstile){document.querySelectorAll(".cf-turnstile").forEach(function(el){'
                    . 'try{window.turnstile.reset(el)}catch(e){}})}})}});</script>';
            }
            $html = preg_replace('~</body>~i', $js . '</body>', $html, 1, $count);
            if (!$count) $html .= $js;
        }
        return $html;
    }

    /** Allow only Cloudflare Turnstile in the existing login-page CSP. */
    private static function allowTurnstileCsp() {
        if (headers_sent()) return;
        $current = null;
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Security-Policy:') === 0) {
                $current = trim(substr($header, strlen('Content-Security-Policy:')));
                break;
            }
        }
        // If no PHP CSP exists, another layer may supply one. Do not
        // invent a new policy or silently weaken a proxy-managed policy.
        if ($current === null) return;
        $directives = array();
        foreach (explode(';', $current) as $item) {
            $item = trim($item);
            if ($item !== '') $directives[] = $item;
        }
        $origin = 'https://challenges.cloudflare.com';
        foreach (array('script-src', 'frame-src') as $directive) {
            $found = false;
            foreach ($directives as &$item) {
                $pieces = preg_split('/\s+/', $item);
                if (strtolower($pieces[0]) !== $directive) continue;
                $found = true;
                if (!in_array($origin, $pieces, true)) $item .= ' ' . $origin;
                break;
            }
            unset($item);
            if (!$found) $directives[] = $directive . ' ' . $origin;
        }
        header('Content-Security-Policy: ' . implode('; ', $directives), true);
    }

    private function verify($cfg, $kind) {
        $token = $_POST['cf-turnstile-response'] ?? null;
        $secret = trim((string)$cfg->get('secret_key'));
        $siteKey = trim((string)$cfg->get('site_key'));
        $host = strtolower(trim((string)$cfg->get('expected_hostname')));
        if (!is_string($token) || !$token || strlen($token) > 2048
            || !$secret || !$siteKey || !$host || !function_exists('curl_init')) return false;
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        if (!$ch) return false;
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array('secret'=>$secret,'response'=>$token)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = is_string($body) && $status === 200 ? json_decode($body, true) : null;
        return is_array($result) && !empty($result['success'])
            && strtolower((string)($result['hostname'] ?? '')) === $host
            && ($result['action'] ?? '') === ($kind === 'staff' ? 'staff-login' : 'client-login');
    }

    private static function reject($kind) {
        $message = 'Human verification failed. Reload the login page and try again.';
        if ($kind === 'staff' && !empty($_POST['ajax'])) {
            Http::response(200, json_encode(array('status'=>401,'message'=>$message,'show_reset'=>false)), 'application/json');
        } else {
            Http::response(403, $message);
        }
        exit;
    }
}
