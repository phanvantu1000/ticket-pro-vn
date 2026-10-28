<?php

if (!defined('ABSPATH')) {
    exit;
}

class TPV_DB {
    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'tpv_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $tickets = self::table('tickets');
        $messages = self::table('messages');
        $attachments = self::table('attachments');
        $participants = self::table('participants');
        $blocks = self::table('ip_blocks');
        $audit = self::table('audit_log');

        dbDelta("CREATE TABLE $tickets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_code varchar(32) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            subject varchar(255) NOT NULL,
            category varchar(32) NOT NULL DEFAULT 'general',
            priority varchar(16) NOT NULL DEFAULT 'normal',
            assignee_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(24) NOT NULL DEFAULT 'open',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            last_message_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY user_status (user_id,status),
            KEY assignee_status (assignee_id,status),
            KEY updated_at (updated_at)
        ) $charset;");
        dbDelta("CREATE TABLE $participants (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            participant_role varchar(16) NOT NULL DEFAULT 'related',
            added_by bigint(20) unsigned NOT NULL,
            added_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ticket_user (ticket_id,user_id),
            KEY user_ticket (user_id,ticket_id)
        ) $charset;");
        dbDelta("CREATE TABLE $messages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) unsigned NOT NULL,
            author_id bigint(20) unsigned NOT NULL,
            author_role varchar(16) NOT NULL,
            body longtext NOT NULL,
            ip_hash char(64) NOT NULL,
            ip_display varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_created (ticket_id,created_at)
        ) $charset;");
        dbDelta("CREATE TABLE $attachments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            message_id bigint(20) unsigned NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            original_name varchar(255) NOT NULL,
            mime_type varchar(128) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY message_id (message_id),
            KEY attachment_id (attachment_id)
        ) $charset;");
        dbDelta("CREATE TABLE $blocks (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ip_hash char(64) NOT NULL,
            ip_display varchar(64) NOT NULL,
            reason varchar(255) NOT NULL DEFAULT '',
            blocked_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ip_hash (ip_hash)
        ) $charset;");
        dbDelta("CREATE TABLE $audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_name varchar(80) NOT NULL,
            object_type varchar(32) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            details longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_created (event_name,created_at)
        ) $charset;");
    }

    public static function audit($event, $type, $id, $details) {
        global $wpdb;
        $wpdb->insert(self::table('audit_log'), array(
            'actor_id' => get_current_user_id(), 'event_name' => $event,
            'object_type' => $type, 'object_id' => absint($id),
            'details' => wp_json_encode($details), 'created_at' => current_time('mysql', true),
        ), array('%d', '%s', '%s', '%d', '%s', '%s'));
    }

    public static function purge_expired_data() {
        global $wpdb;
        $settings = TPV_Plugin::settings();
        $ip_cutoff = gmdate('Y-m-d H:i:s', time() - absint($settings['ip_retention_days']) * DAY_IN_SECONDS);
        $audit_cutoff = gmdate('Y-m-d H:i:s', time() - absint($settings['audit_retention_days']) * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table('messages') . ' SET ip_display = %s WHERE created_at < %s', '', $ip_cutoff));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('audit_log') . ' WHERE created_at < %s', $audit_cutoff));
    }

    /** Permanently remove data owned by Ticket Pro VN, after an explicit admin confirmation. */
    public static function purge_all_data() {
        global $wpdb;

        $attachment_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT post_id FROM ' . $wpdb->postmeta . ' WHERE meta_key = %s', '_tpv_private_attachment'
        ));
        $attachments_table = self::table('attachments');
        if (self::table_exists($attachments_table)) {
            $attachment_ids = array_merge($attachment_ids, $wpdb->get_col('SELECT attachment_id FROM ' . $attachments_table));
        }
        $attachment_ids = array_unique(array_filter(array_map('absint', $attachment_ids)));

        if (!function_exists('wp_delete_attachment')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
        foreach ($attachment_ids as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }

        $tables = array('attachments', 'messages', 'participants', 'tickets', 'ip_blocks', 'audit_log');
        foreach ($tables as $name) {
            $table = self::table($name);
            // Table names are built exclusively from the active WordPress table prefix and a fixed list.
            $result = $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            if ($result === false) {
                return new WP_Error('tpv_purge_failed', __('Ticket data could not be completely removed. Please restore from a backup and try again.', 'ticket-pro-vn'));
            }
        }

        $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('tpv_ticket_enabled', 'tpv_ticket_expires_at')");
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s',
            $wpdb->esc_like('_transient_tpv_') . '%',
            $wpdb->esc_like('_transient_timeout_tpv_') . '%'
        ));
        foreach (array('tpv_settings', 'tpv_blacklist_terms', 'tpv_db_version', 'tpv_license_key', 'tpv_license_state', 'tpv_install_id', 'tpv-submit-ticket-page', 'tpv-my-tickets-page') as $option) {
            delete_option($option);
        }
        wp_clear_scheduled_hook('tpv_daily_license_check');
        wp_clear_scheduled_hook('tpv_daily_data_cleanup');

        return array('attachments' => count($attachment_ids), 'tables' => count($tables));
    }

    private static function table_exists($table) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }
}
