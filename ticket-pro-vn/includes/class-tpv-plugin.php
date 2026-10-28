<?php

if (!defined('ABSPATH')) {
    exit;
}

class TPV_Plugin {
    private static $instance;
    const DB_VERSION = '1.4.0';

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        self::maybe_upgrade();
        add_action('init', array($this, 'load_textdomain'));
        add_action('wp_enqueue_scripts', array($this, 'assets'));
        add_action('template_redirect', array($this, 'render_custom_login_route'), 0);
        add_action('template_redirect', array($this, 'prevent_support_page_cache'), 1);
        add_filter('login_redirect', array($this, 'redirect_ticket_customer'), 20, 3);
        add_filter('woocommerce_login_redirect', array($this, 'redirect_woocommerce_customer'), 20, 2);
        add_action('woocommerce_login_form', array($this, 'woocommerce_login_form_redirect'));
        add_action('admin_init', array($this, 'keep_ticket_customer_frontend'));
        add_action('template_redirect', array($this, 'redirect_customer_account_page'), 20);
        add_filter('show_admin_bar', array($this, 'show_customer_admin_bar'));
        add_action('tpv_daily_license_check', array('TPV_License', 'cron_validate'));
        add_action('tpv_daily_data_cleanup', array('TPV_DB', 'purge_expired_data'));
        TPV_Front::register();
        TPV_Admin::register();
    }

    public static function defaults() {
        return array(
            'subject_limit' => 160, 'message_limit' => 5000,
            'attachments_enabled' => 0, 'attachment_max_files' => 3,
            'attachment_max_mb' => 5, 'attachment_mimes' => 'jpg,jpeg,png,webp',
            'ip_retention_days' => 365, 'audit_retention_days' => 730,
            'login_url' => '',
        );
    }

    public static function settings() {
        return wp_parse_args(get_option('tpv_settings', array()), self::defaults());
    }

    public static function normalize_login_url($value) {
        $value = trim((string) $value);
        if (!$value) {
            return '';
        }
        if (preg_match('#^https?://#i', $value)) {
            return wp_http_validate_url($value) ? esc_url_raw($value) : '';
        }
        $path = preg_replace('/[?#].*$/', '', $value);
        $path = preg_replace('#/+#', '/', '/' . ltrim($path, '/'));
        return $path !== '/' ? esc_url_raw(home_url($path)) : '';
    }

    public static function login_path() {
        $url = !empty(self::settings()['login_url']) ? esc_url_raw(self::settings()['login_url']) : '';
        if (!$url) {
            return '';
        }
        $target = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!empty($target['host']) && !empty($home['host']) && strtolower($target['host']) === strtolower($home['host']) && !empty($target['path'])) {
            $path = $target['path'];
            $home_path = isset($home['path']) ? untrailingslashit($home['path']) : '';
            if ($home_path && strpos($path, $home_path . '/') === 0) {
                $path = substr($path, strlen($home_path));
            }
            return trim($path, '/');
        }
        return $url;
    }

    public static function login_url($redirect = '') {
        $redirect = $redirect ? wp_validate_redirect($redirect, home_url('/')) : home_url('/');
        $settings = self::settings();
        $url = !empty($settings['login_url']) ? esc_url_raw($settings['login_url']) : '';
        $woocommerce = false;
        $woocommerce_url = function_exists('wc_get_page_permalink') ? esc_url_raw(wc_get_page_permalink('myaccount')) : '';
        if (!$url && $woocommerce_url) {
            $url = $woocommerce_url;
        }
        if ($url && $woocommerce_url && untrailingslashit($url) === untrailingslashit($woocommerce_url)) {
            $woocommerce = true;
        }
        if (!$url) {
            return wp_login_url($redirect);
        }
        $url = apply_filters('tpv_login_url', $url, $redirect);
        if (!$url || !wp_http_validate_url($url)) {
            return wp_login_url($redirect);
        }
        // WooCommerce reads this value from the login form POST; the hook below adds it there.
        return add_query_arg($woocommerce ? 'tpv_redirect' : 'redirect_to', $redirect, $url);
    }

    /** Return the current site's registration screen only when registration is enabled. */
    public static function registration_url($redirect = '') {
        if (!get_option('users_can_register')) {
            return '';
        }
        $redirect = $redirect ? wp_validate_redirect($redirect, home_url('/')) : home_url('/');
        return add_query_arg('redirect_to', $redirect, wp_registration_url());
    }

    public static function logout_url($redirect = '') {
        return function_exists('wc_logout_url') ? wc_logout_url($redirect) : wp_logout_url($redirect);
    }

    public static function attachment_groups() {
        return array(
            'images' => array('label' => 'Images', 'extensions' => array('jpg', 'jpeg', 'png', 'webp')),
            'pdf' => array('label' => 'PDF', 'extensions' => array('pdf')),
            'word' => array('label' => 'Word', 'extensions' => array('doc', 'docx')),
            'excel' => array('label' => 'Excel', 'extensions' => array('xls', 'xlsx', 'csv')),
            'text' => array('label' => 'Text', 'extensions' => array('txt')),
        );
    }

    public static function attachment_group_label($group) {
        $groups = self::attachment_groups();
        return isset($groups[$group]) ? __($groups[$group]['label'], 'ticket-pro-vn') : '';
    }

    public static function supported_attachment_extensions() {
        $extensions = array();
        foreach (self::attachment_groups() as $group) {
            foreach ($group['extensions'] as $extension) {
                $extensions[] = $extension;
            }
        }
        return array_values(array_unique($extensions));
    }

    public static function attachment_extensions($settings = null) {
        if ($settings === null) {
            $settings = self::settings();
        }
        $raw = isset($settings['attachment_mimes']) ? $settings['attachment_mimes'] : '';
        $requested = is_array($raw) ? $raw : explode(',', strtolower($raw));
        $supported = self::supported_attachment_extensions();
        $extensions = array();
        foreach ($requested as $extension) {
            $extension = strtolower(trim($extension));
            if (in_array($extension, $supported, true) && !in_array($extension, $extensions, true)) {
                $extensions[] = $extension;
            }
        }
        return $extensions ? $extensions : self::attachment_groups()['images']['extensions'];
    }

    public static function attachment_groups_from_extensions($extensions) {
        $selected = array();
        foreach (self::attachment_groups() as $key => $group) {
            foreach ($group['extensions'] as $extension) {
                if (in_array($extension, $extensions, true)) {
                    $selected[] = $key;
                    break;
                }
            }
        }
        return $selected;
    }

    public static function attachment_extensions_from_groups($groups) {
        if (!is_array($groups)) {
            return array();
        }
        $definitions = self::attachment_groups();
        $extensions = array();
        foreach ($groups as $group) {
            $group = sanitize_key($group);
            if (!isset($definitions[$group])) {
                continue;
            }
            foreach ($definitions[$group]['extensions'] as $extension) {
                if (!in_array($extension, $extensions, true)) {
                    $extensions[] = $extension;
                }
            }
        }
        return $extensions;
    }

    public static function attachment_accept_attribute($settings = null) {
        $accept = array();
        foreach (self::attachment_extensions($settings) as $extension) {
            $accept[] = '.' . $extension;
        }
        return implode(',', $accept);
    }

    public static function attachment_mime_types($extension) {
        $types = array(
            'jpg' => array('image/jpeg'),
            'jpeg' => array('image/jpeg'),
            'png' => array('image/png'),
            'webp' => array('image/webp'),
            'pdf' => array('application/pdf'),
            'doc' => array('application/msword', 'application/CDFV2'),
            'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'),
            'xls' => array('application/vnd.ms-excel', 'application/CDFV2'),
            'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'),
            'csv' => array('text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'),
            'txt' => array('text/plain'),
        );
        return isset($types[$extension]) ? $types[$extension] : array();
    }

    public static function attachment_upload_mimes($extensions) {
        $mimes = array();
        $has_images = false;
        foreach ($extensions as $extension) {
            $types = self::attachment_mime_types($extension);
            if ($types) {
                $mimes[$extension] = $types[0];
            }
            if (self::is_image_attachment_extension($extension)) {
                $has_images = true;
            }
        }
        if ($has_images) {
            // Image uploads are stored as WebP even when a legacy setting listed only JPG or PNG.
            $mimes['webp'] = 'image/webp';
        }
        return $mimes;
    }

    public static function is_image_attachment_extension($extension) {
        return in_array($extension, array('jpg', 'jpeg', 'png', 'webp'), true);
    }

    public function load_textdomain() {
        load_plugin_textdomain('ticket-pro-vn', false, dirname(plugin_basename(TPV_FILE)) . '/languages');
    }

    public function assets() {
        if (is_page(self::support_page_id('tpv-submit-ticket-page', 'tpv_submit_ticket')) || is_page(self::support_page_id('tpv-my-tickets-page', 'tpv_my_tickets'))) {
            wp_enqueue_style('ticket-pro-vn', TPV_URL . 'assets/css/ticket-pro-vn.css', array(), TPV_VERSION);
            wp_enqueue_script('ticket-pro-vn', TPV_URL . 'assets/js/ticket-pro-vn.js', array(), TPV_VERSION, true);
        }
    }

    /** Render a self-contained login form when a same-site custom URL is not a WP page. */
    public function render_custom_login_route() {
        if (is_user_logged_in() || is_page() || (function_exists('is_account_page') && is_account_page())) {
            return;
        }
        $configured = self::settings();
        $login_url = !empty($configured['login_url']) ? esc_url_raw($configured['login_url']) : '';
        if (!$login_url || !wp_http_validate_url($login_url)) {
            return;
        }
        $target = wp_parse_url($login_url);
        $home = wp_parse_url(home_url('/'));
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $request_path = wp_parse_url(home_url($request_uri), PHP_URL_PATH);
        $target_path = isset($target['path']) ? $target['path'] : '/';
        if (empty($target['host']) || empty($home['host']) || strtolower($target['host']) !== strtolower($home['host']) || untrailingslashit($request_path) !== untrailingslashit($target_path)) {
            return;
        }
        $redirect = isset($_GET['redirect_to']) ? wp_validate_redirect(wp_unslash($_GET['redirect_to']), home_url('/')) : home_url('/');
        wp_enqueue_style('dashicons');
        wp_enqueue_style('ticket-pro-vn', TPV_URL . 'assets/css/ticket-pro-vn.css', array(), TPV_VERSION);
        status_header(200);
        nocache_headers();
        get_header();
        echo '<main class="tpv-custom-login"><section class="tpv-custom-login-card"><span class="tpv-kicker">' . esc_html__('Support center', 'ticket-pro-vn') . '</span><h1>' . esc_html__('Sign in to continue', 'ticket-pro-vn') . '</h1><p>' . esc_html__('Sign in to access your support tickets.', 'ticket-pro-vn') . '</p>';
        wp_login_form(array('redirect' => $redirect, 'remember' => true));
        $registration_url = self::registration_url($redirect);
        if ($registration_url) {
            echo '<p class="tpv-registration-link">' . esc_html__('Do not have an account yet?', 'ticket-pro-vn') . ' <a href="' . esc_url($registration_url) . '">' . esc_html__('Create an account', 'ticket-pro-vn') . '</a></p>';
        }
        echo '</section></main>';
        get_footer();
        exit;
    }

    public function prevent_support_page_cache() {
        $submit_page = self::support_page_id('tpv-submit-ticket-page', 'tpv_submit_ticket');
        $tickets_page = self::support_page_id('tpv-my-tickets-page', 'tpv_my_tickets');
        if (!$submit_page && !$tickets_page) {
            return;
        }
        if (is_page($submit_page) || is_page($tickets_page)) {
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            nocache_headers();
        }
    }

    public function redirect_ticket_customer($redirect_to, $requested_redirect_to, $user) {
        if (!$user instanceof WP_User || !$this->is_ticket_customer($user->ID)) {
            return $redirect_to;
        }
        return $this->is_support_redirect($requested_redirect_to) ? $requested_redirect_to : $this->customer_support_url();
    }

    public function redirect_woocommerce_customer($redirect_to, $user) {
        if ($user instanceof WP_User && $this->is_ticket_customer($user->ID)) {
            return $this->is_support_redirect($redirect_to) ? $redirect_to : $this->customer_support_url();
        }
        return $redirect_to;
    }

    public function woocommerce_login_form_redirect() {
        $target = isset($_GET['tpv_redirect']) ? wp_validate_redirect(wp_unslash($_GET['tpv_redirect']), '') : '';
        if ($this->is_support_redirect($target)) {
            echo '<input type="hidden" name="redirect" value="' . esc_attr($target) . '" />';
        }
    }
    public function redirect_customer_account_page() {
        if (!$this->is_ticket_customer(get_current_user_id()) || !function_exists('is_account_page') || !is_account_page()) {
            return;
        }
        wp_safe_redirect($this->customer_support_url());
        exit;
    }

    public function keep_ticket_customer_frontend() {
        // admin-post.php handles ticket forms in the frontend; do not redirect
        // it before its registered admin_post action gets a chance to run.
        $script = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';
        if (wp_doing_ajax() || $script === 'admin-post.php' || (defined('DOING_CRON') && DOING_CRON) || !$this->is_ticket_customer(get_current_user_id())) {
            return;
        }
        wp_safe_redirect($this->customer_support_url());
        exit;
    }

    public function show_customer_admin_bar($show) {
        return $this->is_ticket_customer(get_current_user_id()) ? false : $show;
    }

    private function is_ticket_customer($user_id) {
        $user = $user_id ? get_user_by('id', $user_id) : false;
        if (!$user || user_can($user, 'manage_options') || user_can($user, 'tpv_manage_tickets')) {
            return false;
        }
        return get_user_meta($user_id, 'tpv_ticket_enabled', true) !== '0';
    }

    private function is_support_redirect($url) {
        if (!$url) { return false; }
        $url = wp_validate_redirect($url, '');
        if (!$url) { return false; }
        $submit = self::submit_ticket_url();
        $tickets = self::my_tickets_url();
        return ($submit && strpos($url, $submit) === 0) || ($tickets && strpos($url, $tickets) === 0);
    }

    private function customer_support_url() {
        return self::my_tickets_url();
    }

    /**
     * Resolve support pages by shortcode as well as by option. This prevents a
     * stale/misconfigured page option from sending customers to WooCommerce's
     * My Account dashboard instead of the ticket portal.
     */
    public static function my_tickets_url() {
        $page_id = self::support_page_id('tpv-my-tickets-page', 'tpv_my_tickets');
        // A missing list page should still land customers on a usable ticket form.
        return $page_id ? get_permalink($page_id) : self::submit_ticket_url();
    }

    public static function submit_ticket_url() {
        $page_id = self::support_page_id('tpv-submit-ticket-page', 'tpv_submit_ticket');
        return $page_id ? get_permalink($page_id) : home_url('/');
    }

    private static function support_page_id($option, $shortcode) {
        $configured_id = absint(get_option($option));
        $configured = $configured_id ? get_post($configured_id) : false;
        if ($configured && $configured->post_type === 'page' && has_shortcode($configured->post_content, $shortcode)) {
            return $configured_id;
        }

        $pages = get_pages(array('post_status' => 'publish', 'post_type' => 'page', 'number' => 100));
        foreach ($pages as $page) {
            if (has_shortcode($page->post_content, $shortcode)) {
                update_option($option, (int) $page->ID, false);
                return (int) $page->ID;
            }
        }
        return 0;
    }

    public static function activate() {
        TPV_DB::install();
        update_option('tpv_db_version', self::DB_VERSION, false);
        self::ensure_roles();
        add_option('tpv_settings', self::defaults());
        TPV_License::install_id();
        self::create_page('tpv-submit-ticket-page', __('Submit a ticket', 'ticket-pro-vn'), '[tpv_submit_ticket]');
        self::create_page('tpv-my-tickets-page', __('My support tickets', 'ticket-pro-vn'), '[tpv_my_tickets]');
        self::exclude_support_pages_from_toc();
        if (get_option('tpv_license_key', '') && !wp_next_scheduled('tpv_daily_license_check')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tpv_daily_license_check');
        }
        if (!wp_next_scheduled('tpv_daily_data_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tpv_daily_data_cleanup');
        }
    }

    public static function maybe_upgrade() {
        self::ensure_roles();
        if (get_option('tpv_db_version') !== self::DB_VERSION) {
            TPV_DB::install();
            update_option('tpv_db_version', self::DB_VERSION, false);
        }
    }

    public static function ensure_roles() {
        $administrator = get_role('administrator');
        if ($administrator) { $administrator->add_cap('tpv_manage_tickets'); }
        $agent = get_role('tpv_support_agent');
        if (!$agent) { $agent = add_role('tpv_support_agent', __('Support Agent', 'ticket-pro-vn'), array('read' => true, 'tpv_manage_tickets' => true)); }
        if ($agent) { $agent->add_cap('read'); $agent->add_cap('tpv_manage_tickets'); }
    }

    public static function ticket_participants($ticket_id) {
        global $wpdb;
        $participants = TPV_DB::table('participants');
        return $wpdb->get_results($wpdb->prepare('SELECT p.*, u.display_name, u.user_email, u.user_login FROM ' . $participants . ' p INNER JOIN ' . $wpdb->users . ' u ON p.user_id=u.ID WHERE p.ticket_id=%d ORDER BY p.added_at ASC', $ticket_id));
    }

    public static function is_ticket_participant($ticket_id, $user_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . TPV_DB::table('participants') . ' WHERE ticket_id=%d AND user_id=%d', $ticket_id, $user_id));
    }

    public static function support_agents() {
        // user_can() needs full WP_User objects, not partial database rows.
        $users = get_users(array('fields' => 'all'));
        $agents = array();
        foreach ($users as $user) { if (user_can($user, 'tpv_manage_tickets')) { $agents[] = $user; } }
        return $agents;
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('tpv_daily_license_check');
        wp_clear_scheduled_hook('tpv_daily_data_cleanup');
    }

    private static function create_page($option, $title, $content) {
        if (get_option($option)) {
            return;
        }
        $page_id = wp_insert_post(array('post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_type' => 'page'));
        if ($page_id && !is_wp_error($page_id)) {
            update_option($option, $page_id, false);
        }
    }

    private static function exclude_support_pages_from_toc() {
        foreach (array('tpv-submit-ticket-page', 'tpv-my-tickets-page') as $option) {
            $page_id = absint(get_option($option));
            if ($page_id) { update_post_meta($page_id, '_ez-toc-exclude', '*'); }
        }
    }

    public static function ticket_code() {
        global $wpdb;
        do {
            $code = 'TPV-' . gmdate('Ymd') . '-' . strtoupper(wp_generate_password(5, false, false));
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . TPV_DB::table('tickets') . ' WHERE ticket_code = %s', $code));
        } while ($exists);
        return $code;
    }

    public static function status_label($status) {
        $labels = array('open' => __('Open', 'ticket-pro-vn'), 'answered' => __('Answered', 'ticket-pro-vn'), 'waiting_customer' => __('Waiting for customer', 'ticket-pro-vn'), 'closed' => __('Closed', 'ticket-pro-vn'));
        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    public static function categories() {
        return array(
            'technical' => __('Technical issue', 'ticket-pro-vn'),
            'account' => __('Account and login', 'ticket-pro-vn'),
            'payment' => __('Payment', 'ticket-pro-vn'),
            'general' => __('General consultation', 'ticket-pro-vn'),
        );
    }

    public static function priorities() {
        return array(
            'normal' => __('Normal', 'ticket-pro-vn'),
            'high' => __('High', 'ticket-pro-vn'),
        );
    }

    public static function category_label($category) {
        $items = self::categories();
        return isset($items[$category]) ? $items[$category] : $items['general'];
    }

    public static function priority_label($priority) {
        $items = self::priorities();
        return isset($items[$priority]) ? $items[$priority] : $items['normal'];
    }
}
