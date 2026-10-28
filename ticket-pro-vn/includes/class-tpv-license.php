<?php

if (!defined('ABSPATH')) {
    exit;
}

class TPV_License {
    const GRACE_DAYS = 7;

    public static function normalize_domain($host) {
        $host = strtolower(trim((string) $host));
        $host = preg_replace('#^https?://#', '', $host);
        $host = preg_replace('#/.*$#', '', $host);
        $host = preg_replace('#^www\.#', '', $host);
        return trim($host, '.');
    }

    public static function current_domain() {
        $url = home_url('/');
        if (strpos($url, 'https://') !== 0) {
            return new WP_Error('tpv_https', __('Ticket Pro VN requires HTTPS.', 'ticket-pro-vn'));
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || filter_var($host, FILTER_VALIDATE_IP)) {
            return new WP_Error('tpv_domain', __('A public HTTPS domain is required.', 'ticket-pro-vn'));
        }
        return self::normalize_domain($host);
    }

    public static function install_id() {
        $id = get_option('tpv_install_id', '');
        if (!$id) {
            $id = wp_generate_uuid4();
            update_option('tpv_install_id', $id, false);
        }
        return $id;
    }

    public static function can_operate() {
        $domain = self::current_domain();
        if (is_wp_error($domain)) {
            return $domain;
        }
        $license = get_option('tpv_license_state', array());
        if (empty($license['domain']) || $license['domain'] !== $domain || empty($license['status'])) {
            return new WP_Error('tpv_license', __('This site has no valid Ticket Pro VN license.', 'ticket-pro-vn'));
        }
        if (!empty($license['expires_at'])) {
            $expires_at = strtotime($license['expires_at']);
            if (!$expires_at || $expires_at <= time()) {
                return new WP_Error('tpv_license_expired', __('This Ticket Pro VN license has expired. Renew it, then validate this site again.', 'ticket-pro-vn'));
            }
        }
        if ($license['status'] !== 'active') {
            return new WP_Error('tpv_license_inactive', __('The Ticket Pro VN license is inactive.', 'ticket-pro-vn'));
        }
        $last = isset($license['validated_at']) ? strtotime($license['validated_at']) : 0;
        if (!$last || $last < (time() - self::GRACE_DAYS * DAY_IN_SECONDS)) {
            return new WP_Error('tpv_license_grace', __('License validation is overdue. Please reconnect this site.', 'ticket-pro-vn'));
        }
        return true;
    }

    public static function is_pro() {
        return self::can_operate() === true;
    }

    public static function cron_validate() {
        $key = get_option('tpv_license_key', '');
        if ($key) {
            self::request('validate', $key);
        }
    }

    public static function public_key() {
        static $key = null;
        if ($key === null) {
            $key = is_readable(TPV_LICENSE_PUBLIC_KEY_FILE) ? file_get_contents(TPV_LICENSE_PUBLIC_KEY_FILE) : '';
        }
        return $key;
    }

    public static function request($operation, $key) {
        $server = esc_url_raw(TPV_LICENSE_SERVER_URL);
        $domain = self::current_domain();
        if (!$server || is_wp_error($domain)) {
            return is_wp_error($domain) ? $domain : new WP_Error('tpv_server', __('The built-in license service is unavailable.', 'ticket-pro-vn'));
        }
        $response = wp_remote_post(trailingslashit($server) . 'api/v1/' . $operation, array(
            'timeout' => 15,
            'sslverify' => true,
            'headers' => array('Accept' => 'application/json'),
            'body' => array('license_key' => $key, 'domain' => $domain, 'install_id' => self::install_id()),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $status_code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status_code < 200 || $status_code >= 300) {
            $server_error = is_array($data) && !empty($data['error']) ? sanitize_text_field($data['error']) : '';
            $messages = array(
                'License key was not found' => __('The license key was not found or has been replaced.', 'ticket-pro-vn'),
                'License is bound to another domain or installation' => __('This license is already linked to another website or installation.', 'ticket-pro-vn'),
                'license_key, HTTPS domain, and install_id are required' => __('The license activation request is incomplete.', 'ticket-pro-vn'),
            );
            $message = isset($messages[$server_error]) ? $messages[$server_error] : ($server_error ?: __('The license service could not process this request.', 'ticket-pro-vn'));
            return new WP_Error('tpv_server_response', $message);
        }
        if (!is_array($data) || empty($data['payload']) || empty($data['signature'])) {
            return new WP_Error('tpv_response', __('The license server returned an invalid response.', 'ticket-pro-vn'));
        }
        $payload = base64_decode($data['payload'], true);
        if (!$payload || !self::verify($payload, $data['signature'])) {
            return new WP_Error('tpv_signature', __('The license response signature is invalid.', 'ticket-pro-vn'));
        }
        $payload = json_decode($payload, true);
        if (!is_array($payload) || empty($payload['domain']) || $payload['domain'] !== $domain) {
            return new WP_Error('tpv_domain_response', __('The license does not match this domain.', 'ticket-pro-vn'));
        }
        $payload['validated_at'] = current_time('mysql', true);
        update_option('tpv_license_state', $payload, false);
        if ($operation === 'activate') {
            update_option('tpv_license_key', $key, false);
        }
        TPV_DB::audit('license_' . $operation, 'license', 0, array('status' => $payload['status'], 'domain' => $domain));
        return $payload;
    }

    public static function verify($payload, $signature) {
        $key = self::public_key();
        if (!$key || !function_exists('openssl_verify')) {
            return false;
        }
        return openssl_verify($payload, base64_decode($signature), $key, OPENSSL_ALGO_SHA256) === 1;
    }
}
