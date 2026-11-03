<?php
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.forms.php');

class CloudflareTurnstileConfig extends PluginConfig {
    function getOptions() {
        return array(
            'site_key' => new TextboxField(array(
                'label' => 'Cloudflare Site Key', 'required' => true,
                'configuration' => array('size' => 45, 'length' => 128),
                'hint' => 'The public site key for the allowed hostname.'
            )),
            'secret_key' => new PasswordField(array(
                'widget' => 'PasswordWidget',
                'label' => 'Cloudflare Secret Key',
                'required' => !$this->get('secret_key'),
                'validator' => 'noop',
                'configuration' => array(
                    'size' => 45, 'length' => 0,
                    'key' => $this->getNamespace(),
                    'placeholder' => $this->get('secret_key')
                        ? '******** (leave blank to keep existing secret)'
                        : 'Cloudflare Secret Key',
                ),
                'hint' => 'Private key used only for Siteverify. Leave blank to retain the saved secret when editing.'
            )),
            'staff_enabled' => new BooleanField(array(
                'label' => 'Protect staff password login', 'default' => true,
            )),
            'client_enabled' => new BooleanField(array(
                'label' => 'Protect client password login', 'default' => true,
            )),
            'expected_hostname' => new TextboxField(array(
                'label' => 'Expected hostname', 'required' => true,
                'configuration' => array('size' => 45, 'length' => 255),
                'hint' => 'The trusted public hostname permitted to produce Turnstile tokens (for example osticket.example.com). It usually matches the address in your browser. Do not include https://, a port, or a path.'
            )),
        );
    }
    function pre_save(&$config, &$errors) {
        // A blank password widget means keep the existing secret, not erase it.
        if (empty($config['secret_key']) && $this->get('secret_key'))
            $config['secret_key'] = $this->get('secret_key');
        $host = trim((string)($config['expected_hostname'] ?? ''));
        if (!$host || !preg_match('/^[a-z0-9.-]+$/i', $host))
            $errors['expected_hostname'] = 'Enter a valid hostname without scheme, path or port.';
        return !$errors;
    }
}
