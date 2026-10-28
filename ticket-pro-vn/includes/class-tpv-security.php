<?php

if (!defined('ABSPATH')) {
    exit;
}

class TPV_Security {
    public static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function hash_ip($ip) {
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    public static function is_ip_blocked($ip) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . TPV_DB::table('ip_blocks') . ' WHERE ip_hash = %s', self::hash_ip($ip)
        ));
    }

    public static function contains_url($value) {
        $value = wp_strip_all_tags($value);
        return (bool) preg_match('#(?:https?|ftp)\s*:\s*/\s*/|www\s*\.#iu', $value);
    }

    public static function contains_blocked_term($value) {
        $terms = get_option('tpv_blacklist_terms', array());
        if (!is_array($terms) || empty($terms)) {
            return false;
        }
        $needle = self::lower(wp_strip_all_tags($value));
        foreach ($terms as $term) {
            $term = trim(self::lower($term));
            if ($term !== '' && self::contains($needle, $term)) {
                return true;
            }
        }
        return false;
    }

    public static function lower($value) {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    public static function contains($haystack, $needle) {
        return function_exists('mb_strpos') ? mb_strpos($haystack, $needle, 0, 'UTF-8') !== false : strpos($haystack, $needle) !== false;
    }

    public static function text_length($value) {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    public static function valid_message($body, $subject) {
        $settings = TPV_Plugin::settings();
        if (self::contains_url($body) || self::contains_url($subject)) {
            return new WP_Error('tpv_url', __('Links are not allowed in tickets.', 'ticket-pro-vn'));
        }
        if (self::contains_blocked_term($body) || self::contains_blocked_term($subject)) {
            return new WP_Error('tpv_blocked_text', __('Your message cannot be submitted.', 'ticket-pro-vn'));
        }
        if (self::text_length($body) > absint($settings['message_limit'])) {
            return new WP_Error('tpv_too_long', __('Your message is too long.', 'ticket-pro-vn'));
        }
        if ($subject !== '' && self::text_length($subject) > absint($settings['subject_limit'])) {
            return new WP_Error('tpv_subject_long', __('Your subject is too long.', 'ticket-pro-vn'));
        }
        return true;
    }

    public static function rate_limited($action, $user_id, $ip) {
        $key = 'tpv_rate_' . md5($action . '|' . $user_id . '|' . $ip);
        $count = (int) get_transient($key);
        if ($count >= 5) {
            return true;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS * 10);
        return false;
    }

    public static function can_manage_tickets() {
        return current_user_can('manage_options') || current_user_can('tpv_manage_tickets');
    }

    /**
     * Check the capabilities of a message author rather than the current viewer.
     * This keeps staff identity consistent in every ticket conversation.
     */
    public static function is_staff_user($user_id) {
        $user = $user_id ? get_user_by('id', $user_id) : false;
        return $user && (user_can($user, 'manage_options') || user_can($user, 'tpv_manage_tickets'));
    }

    public static function user_email_verified($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) { return false; }
        return (bool) apply_filters('tpv_user_email_verified', is_email($user->user_email), $user);
    }

    public static function user_can_view_ticket($ticket, $user_id) {
        if (!$ticket || !$user_id) { return false; }
        if (self::can_manage_tickets()) { return true; }
        return (int) $ticket->user_id === (int) $user_id || TPV_Plugin::is_ticket_participant($ticket->id, $user_id);
    }

    public static function user_can_reply_ticket($ticket, $user_id) {
        if (!self::user_can_view_ticket($ticket, $user_id)) {
            return new WP_Error('tpv_ticket_access', __('You cannot reply to this ticket.', 'ticket-pro-vn'));
        }
        if (self::can_manage_tickets()) { return true; }
        if ((int) $ticket->user_id === (int) $user_id) { return self::user_can_submit($user_id); }
        if (!self::user_email_verified($user_id)) {
            return new WP_Error('tpv_email', __('Please verify your email address before using support.', 'ticket-pro-vn'));
        }
        return true;
    }

    public static function user_can_submit($user_id) {
        $user = $user_id ? get_user_by('id', $user_id) : false;
        if (!$user) {
            return new WP_Error('tpv_login', __('Please sign in to use support tickets.', 'ticket-pro-vn'));
        }
        // Administrators and support agents are governed by their capability, not customer access flags.
        $is_manager = user_can($user, 'manage_options') || user_can($user, 'tpv_manage_tickets');
        if ($is_manager) {
            return true;
        }
        if (!self::user_email_verified($user_id)) {
            return new WP_Error('tpv_email', __('Please verify your email address before using support.', 'ticket-pro-vn'));
        }
        // Missing metadata means enabled for newly registered members; only an explicit 0 suspends access.
        if (get_user_meta($user_id, 'tpv_ticket_enabled', true) === '0') {
            return new WP_Error('tpv_not_enabled', __('Support access is not enabled for your account.', 'ticket-pro-vn'));
        }
        $expires = get_user_meta($user_id, 'tpv_ticket_expires_at', true);
        if ($expires && strtotime($expires . ' 23:59:59') < current_time('timestamp')) {
            return new WP_Error('tpv_expired', __('Your support access has expired.', 'ticket-pro-vn'));
        }
        return true;
    }
}
