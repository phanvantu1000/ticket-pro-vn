<?php

if (!defined('ABSPATH')) {
    exit;
}

class TPV_Admin {
    public static function register() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_bar_menu', array(__CLASS__, 'support_toolbar'), 90);
        add_action('admin_post_tpv_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_tpv_activate_license', array(__CLASS__, 'activate_license'));
        add_action('admin_post_tpv_purge_all_data', array(__CLASS__, 'purge_all_data'));
        add_action('admin_post_tpv_change_ticket_status', array(__CLASS__, 'change_ticket_status'));
        add_action('admin_post_tpv_assign_ticket', array(__CLASS__, 'assign_ticket'));
        add_action('admin_post_tpv_block_ip', array(__CLASS__, 'block_ip'));
        add_action('show_user_profile', array(__CLASS__, 'user_fields'));
        add_action('edit_user_profile', array(__CLASS__, 'user_fields'));
        add_action('user_register', array(__CLASS__, 'enable_new_user'));
        add_action('personal_options_update', array(__CLASS__, 'save_user_fields'));
        add_action('edit_user_profile_update', array(__CLASS__, 'save_user_fields'));
        add_filter('manage_users_columns', array(__CLASS__, 'user_list_column'));
        add_filter('manage_users_custom_column', array(__CLASS__, 'user_list_value'), 10, 3);
        add_filter('bulk_actions-users', array(__CLASS__, 'user_bulk_actions'));
        add_filter('handle_bulk_actions-users', array(__CLASS__, 'handle_user_bulk'), 10, 3);
    }

    public static function menu() {
        add_menu_page(__('Ticket Pro VN', 'ticket-pro-vn'), __('Ticket Pro VN', 'ticket-pro-vn'), 'tpv_manage_tickets', 'tpv-tickets', array(__CLASS__, 'tickets_page'), 'dashicons-sos', 58);
        add_submenu_page('tpv-tickets', __('Tickets', 'ticket-pro-vn'), __('Tickets', 'ticket-pro-vn'), 'tpv_manage_tickets', 'tpv-tickets', array(__CLASS__, 'tickets_page'));
        add_submenu_page('tpv-tickets', __('Settings', 'ticket-pro-vn'), __('Settings', 'ticket-pro-vn'), 'manage_options', 'tpv-settings', array(__CLASS__, 'settings_page'));
        add_submenu_page('tpv-tickets', __('License & Pro', 'ticket-pro-vn'), __('License & Pro', 'ticket-pro-vn'), 'manage_options', 'tpv-license', array(__CLASS__, 'license_page'));
    }

    public static function assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($hook, 'tpv-') === false && strpos($page, 'tpv-') === false) {
            return;
        }
        wp_enqueue_style('ticket-pro-vn-admin', TPV_URL . 'assets/css/tpv-admin.css', array(), TPV_VERSION);
        wp_enqueue_style('ticket-pro-vn-admin-detail', TPV_URL . 'assets/css/tpv-admin-detail.css', array('ticket-pro-vn-admin'), TPV_VERSION);
        wp_enqueue_script('ticket-pro-vn-admin', TPV_URL . 'assets/js/tpv-admin-settings.js', array(), TPV_VERSION, true);
        wp_enqueue_script('ticket-pro-vn-lightbox', TPV_URL . 'assets/js/ticket-pro-vn.js', array(), TPV_VERSION, true);
    }

    public static function support_toolbar($admin_bar) {
        if (!is_user_logged_in() || TPV_Security::can_manage_tickets()) {
            return;
        }
        $access = TPV_Security::user_can_submit(get_current_user_id());
        $submit_page = absint(get_option('tpv-submit-ticket-page'));
        $tickets_page = absint(get_option('tpv-my-tickets-page'));
        if (is_wp_error($access) || !$submit_page || !$tickets_page) {
            return;
        }
        $submit_url = get_permalink($submit_page);
        $tickets_url = get_permalink($tickets_page);
        if (!$submit_url || !$tickets_url) {
            return;
        }
        $admin_bar->add_node(array(
            'id' => 'tpv-support-shortcuts',
            'title' => '<span class="ab-icon dashicons dashicons-sos" aria-hidden="true"></span><span class="ab-label">' . esc_html__('Support', 'ticket-pro-vn') . '</span>',
            'href' => $submit_url,
            'meta' => array('class' => 'tpv-toolbar-support', 'title' => __('Open the Ticket Pro VN support center', 'ticket-pro-vn')),
        ));
        $admin_bar->add_node(array(
            'id' => 'tpv-submit-ticket-shortcut',
            'parent' => 'tpv-support-shortcuts',
            'title' => esc_html__('New ticket', 'ticket-pro-vn'),
            'href' => $submit_url,
            'meta' => array('title' => __('Create a new support ticket', 'ticket-pro-vn')),
        ));
        $admin_bar->add_node(array(
            'id' => 'tpv-my-tickets-shortcut',
            'parent' => 'tpv-support-shortcuts',
            'title' => esc_html__('My tickets', 'ticket-pro-vn'),
            'href' => $tickets_url,
            'meta' => array('title' => __('View your support ticket list', 'ticket-pro-vn')),
        ));
    }

    public static function tickets_page() {
        if (!TPV_Security::can_manage_tickets()) { return; }
        global $wpdb;
        echo '<div class="wrap tpv-tickets-page">';
        $ticket_id = isset($_GET['ticket_id']) ? absint($_GET['ticket_id']) : 0;
        if ($ticket_id) { self::ticket_page($ticket_id); echo '</div>'; return; }
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $status_items = array('open', 'answered', 'waiting_customer', 'closed');
        $where = '1=1'; $args = array();
        if (in_array($status, $status_items, true)) { $where .= ' AND status = %s'; $args[] = $status; }
        $query = 'SELECT t.*, u.display_name, u.user_email, a.display_name AS assignee_name FROM ' . TPV_DB::table('tickets') . ' t LEFT JOIN ' . $wpdb->users . ' u ON t.user_id=u.ID LEFT JOIN ' . $wpdb->users . ' a ON t.assignee_id=a.ID WHERE ' . $where . ' ORDER BY t.updated_at DESC LIMIT 100';
        $tickets = $args ? $wpdb->get_results($wpdb->prepare($query, $args)) : $wpdb->get_results($query);
        $counts = self::ticket_status_counts();
        $current_label = $status ? TPV_Plugin::status_label($status) : __('All tickets', 'ticket-pro-vn');
        ?>
        <section class="tpv-tickets-hero">
            <div><span class="tpv-settings-kicker"><?php esc_html_e('Support desk', 'ticket-pro-vn'); ?></span><h1><?php esc_html_e('Support tickets', 'ticket-pro-vn'); ?></h1><p><?php esc_html_e('Review customer requests, keep each conversation moving, and prioritize the work that needs attention.', 'ticket-pro-vn'); ?></p></div>
            <div class="tpv-tickets-hero-total"><span class="dashicons dashicons-format-status" aria-hidden="true"></span><strong><?php echo esc_html(number_format_i18n($counts['all'])); ?></strong><span><?php esc_html_e('total tickets', 'ticket-pro-vn'); ?></span></div>
        </section>
        <nav class="tpv-ticket-filters" aria-label="<?php esc_attr_e('Ticket status filters', 'ticket-pro-vn'); ?>">
            <a class="tpv-ticket-filter<?php echo $status ? '' : ' is-active'; ?>" href="<?php echo esc_url(admin_url('admin.php?page=tpv-tickets')); ?>"<?php echo $status ? '' : ' aria-current="page"'; ?>><span><?php esc_html_e('All tickets', 'ticket-pro-vn'); ?></span><b><?php echo esc_html(number_format_i18n($counts['all'])); ?></b></a>
            <?php foreach ($status_items as $item) : ?>
                <a class="tpv-ticket-filter tpv-ticket-filter-<?php echo esc_attr($item); ?><?php echo $status === $item ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'tpv-tickets', 'status' => $item), admin_url('admin.php'))); ?>"<?php echo $status === $item ? ' aria-current="page"' : ''; ?>><span><?php echo esc_html(TPV_Plugin::status_label($item)); ?></span><b><?php echo esc_html(number_format_i18n($counts[$item])); ?></b></a>
            <?php endforeach; ?>
        </nav>
        <section class="tpv-ticket-table-card">
            <div class="tpv-ticket-table-heading"><div><span class="tpv-settings-kicker"><?php echo esc_html($current_label); ?></span><h2><?php echo esc_html(sprintf(_n('%d ticket', '%d tickets', count($tickets), 'ticket-pro-vn'), count($tickets))); ?></h2></div><p><?php esc_html_e('Select a ticket code to open its full conversation.', 'ticket-pro-vn'); ?></p></div>
            <div class="tpv-ticket-table-scroll"><table class="widefat fixed striped tpv-ticket-table"><thead><tr><th><?php esc_html_e('Code', 'ticket-pro-vn'); ?></th><th><?php esc_html_e('Customer', 'ticket-pro-vn'); ?></th><th><?php esc_html_e('Subject', 'ticket-pro-vn'); ?></th><th><?php esc_html_e('Assigned to', 'ticket-pro-vn'); ?></th><th><?php esc_html_e('Support topic', 'ticket-pro-vn'); ?></th><th><?php esc_html_e('Priority', 'ticket-pro-vn'); ?></th><th><?php esc_html_e('Status', 'ticket-pro-vn'); ?></th><th><?php esc_html_e('Updated', 'ticket-pro-vn'); ?></th></tr></thead><tbody>
            <?php foreach ($tickets as $ticket) : $category = isset($ticket->category) ? $ticket->category : 'general'; $priority = isset($ticket->priority) ? $ticket->priority : 'normal'; ?>
                <tr><td data-label="<?php esc_attr_e('Code', 'ticket-pro-vn'); ?>"><a class="tpv-ticket-code-link" href="<?php echo esc_url(add_query_arg(array('page' => 'tpv-tickets', 'ticket_id' => $ticket->id), admin_url('admin.php'))); ?>"><?php echo esc_html($ticket->ticket_code); ?><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a></td><td data-label="<?php esc_attr_e('Customer', 'ticket-pro-vn'); ?>"><div class="tpv-ticket-customer"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><div><strong><?php echo esc_html($ticket->display_name ? $ticket->display_name : __('Deleted user', 'ticket-pro-vn')); ?></strong><small><?php echo esc_html($ticket->user_email); ?></small></div></div></td><td data-label="<?php esc_attr_e('Subject', 'ticket-pro-vn'); ?>"><strong class="tpv-ticket-subject"><?php echo esc_html($ticket->subject); ?></strong></td><td data-label="<?php esc_attr_e('Assigned to', 'ticket-pro-vn'); ?>"><span class="tpv-ticket-assignee"><span class="dashicons dashicons-businessperson" aria-hidden="true"></span><?php echo esc_html($ticket->assignee_name ? $ticket->assignee_name : __('Unassigned', 'ticket-pro-vn')); ?></span></td><td data-label="<?php esc_attr_e('Support topic', 'ticket-pro-vn'); ?>"><span class="tpv-ticket-topic"><?php echo esc_html(TPV_Plugin::category_label($category)); ?></span></td><td data-label="<?php esc_attr_e('Priority', 'ticket-pro-vn'); ?>"><span class="tpv-ticket-priority tpv-ticket-priority-<?php echo esc_attr($priority); ?>"><?php echo esc_html(TPV_Plugin::priority_label($priority)); ?></span></td><td data-label="<?php esc_attr_e('Status', 'ticket-pro-vn'); ?>"><span class="tpv-ticket-status tpv-ticket-status-<?php echo esc_attr($ticket->status); ?>"><i aria-hidden="true"></i><?php echo esc_html(TPV_Plugin::status_label($ticket->status)); ?></span></td><td data-label="<?php esc_attr_e('Updated', 'ticket-pro-vn'); ?>"><time class="tpv-ticket-updated" datetime="<?php echo esc_attr($ticket->updated_at); ?>"><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $ticket->updated_at)); ?></time></td></tr>
            <?php endforeach; ?>
            <?php if (!$tickets) : ?><tr><td colspan="8"><div class="tpv-ticket-empty"><span class="dashicons dashicons-sos" aria-hidden="true"></span><strong><?php esc_html_e('No tickets found.', 'ticket-pro-vn'); ?></strong><p><?php esc_html_e('Tickets matching this status will appear here.', 'ticket-pro-vn'); ?></p></div></td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
        <?php
        echo '</div>';
    }

    private static function ticket_status_counts() {
        global $wpdb;
        $counts = array('all' => 0, 'open' => 0, 'answered' => 0, 'waiting_customer' => 0, 'closed' => 0);
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS ticket_count FROM ' . TPV_DB::table('tickets') . ' GROUP BY status');
        foreach ($rows as $row) {
            if (isset($counts[$row->status])) { $counts[$row->status] = (int) $row->ticket_count; }
        }
        foreach (array('open', 'answered', 'waiting_customer', 'closed') as $item) { $counts['all'] += $counts[$item]; }
        return $counts;
    }

    private static function ticket_page($ticket_id) {
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPV_DB::table('tickets') . ' WHERE id=%d', $ticket_id));
        if (!$ticket) { echo '<p>' . esc_html__('Ticket not found.', 'ticket-pro-vn') . '</p>'; return; }
        $last_ip = $wpdb->get_var($wpdb->prepare('SELECT ip_display FROM ' . TPV_DB::table('messages') . ' WHERE ticket_id=%d ORDER BY id DESC LIMIT 1', $ticket_id));
        echo '<section class="tpv-ticket-admin-meta"><div><span class="tpv-admin-meta-label">' . esc_html__('Customer IP', 'ticket-pro-vn') . '</span><strong>' . esc_html($last_ip ? $last_ip : __('Unavailable', 'ticket-pro-vn')) . '</strong></div>';
        if ($last_ip && current_user_can('manage_options')) { echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tpv_block_ip" /><input type="hidden" name="ip" value="' . esc_attr($last_ip) . '" />'; wp_nonce_field('tpv_block_ip'); submit_button(__('Block IP', 'ticket-pro-vn'), 'secondary small', 'submit', false); echo '</form>'; }
        echo '</section>';
        echo '<form class="tpv-ticket-assignment" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tpv_assign_ticket" /><input type="hidden" name="ticket_id" value="' . esc_attr($ticket_id) . '" />'; wp_nonce_field('tpv_assign_ticket'); echo '<label for="tpv-assignee-' . esc_attr($ticket_id) . '">' . esc_html__('Assigned support agent', 'ticket-pro-vn') . '</label> <select id="tpv-assignee-' . esc_attr($ticket_id) . '" name="assignee_id"><option value="0">' . esc_html__('Unassigned', 'ticket-pro-vn') . '</option>';
        foreach (TPV_Plugin::support_agents() as $agent) { echo '<option value="' . esc_attr($agent->ID) . '" ' . selected((int) $ticket->assignee_id, (int) $agent->ID, false) . '>' . esc_html($agent->display_name . ' (' . $agent->user_email . ')') . '</option>'; }
        echo '</select> '; submit_button(__('Assign', 'ticket-pro-vn'), 'secondary', 'submit', false); echo '</form>';
        TPV_Front::admin_ticket_detail($ticket_id);
        echo '<form class="tpv-ticket-status-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tpv_change_ticket_status" /><input type="hidden" name="ticket_id" value="' . esc_attr($ticket_id) . '" />'; wp_nonce_field('tpv_change_ticket_status'); echo '<select name="status">'; foreach (array('open','answered','waiting_customer','closed') as $item) { echo '<option value="' . esc_attr($item) . '" ' . selected($ticket->status, $item, false) . '>' . esc_html(TPV_Plugin::status_label($item)) . '</option>'; } echo '</select> '; submit_button(__('Update status', 'ticket-pro-vn'), 'secondary', 'submit', false); echo '</form>';
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) { return; }
        $settings = TPV_Plugin::settings();
        $selected_groups = TPV_Plugin::attachment_groups_from_extensions(TPV_Plugin::attachment_extensions($settings));
        $blacklist_terms = get_option('tpv_blacklist_terms', array());
        if (!is_array($blacklist_terms)) { $blacklist_terms = array(); }
        ?>
        <div class="wrap tpv-settings-page">
            <div class="tpv-settings-hero">
                <span class="tpv-settings-kicker"><?php esc_html_e('Ticket Pro VN', 'ticket-pro-vn'); ?></span>
                <h1><?php esc_html_e('Ticket Pro VN settings', 'ticket-pro-vn'); ?></h1>
                <p><?php esc_html_e('Configure safe customer attachments, message limits, and moderation rules.', 'ticket-pro-vn'); ?></p>
            </div>
            <?php self::admin_flash(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tpv_save_settings" />
                <?php wp_nonce_field('tpv_save_settings'); ?>
                <div class="tpv-settings-grid">
                    <section class="tpv-settings-card">
                        <div class="tpv-settings-card-head"><span class="dashicons dashicons-editor-textcolor"></span><div><h2><?php esc_html_e('Message rules', 'ticket-pro-vn'); ?></h2><p><?php esc_html_e('Set enough room for customers to describe their issue without accepting excessive input.', 'ticket-pro-vn'); ?></p></div></div>
                        <div class="tpv-settings-number-grid">
                            <label><?php esc_html_e('Subject limit', 'ticket-pro-vn'); ?><input type="number" name="subject_limit" value="<?php echo esc_attr($settings['subject_limit']); ?>" min="1" max="500" /><span><?php esc_html_e('characters', 'ticket-pro-vn'); ?></span></label>
                            <label><?php esc_html_e('Message limit', 'ticket-pro-vn'); ?><input type="number" name="message_limit" value="<?php echo esc_attr($settings['message_limit']); ?>" min="1" max="50000" /><span><?php esc_html_e('characters', 'ticket-pro-vn'); ?></span></label>
                        </div>
                    </section>

                    <section class="tpv-settings-card tpv-settings-card-wide">
                        <div class="tpv-settings-card-head"><span class="dashicons dashicons-admin-links"></span><div><h2><?php esc_html_e('Login routing', 'ticket-pro-vn'); ?></h2><p><?php esc_html_e('Choose where customers should sign in. Leave this blank to detect WooCommerce My Account or use the WordPress login page automatically.', 'ticket-pro-vn'); ?></p></div></div>
                        <label class="tpv-login-url-input"><?php esc_html_e('Login path', 'ticket-pro-vn'); ?><span class="tpv-login-path-field"><span class="tpv-login-path-prefix"><?php echo esc_html(untrailingslashit(home_url('/')) . '/'); ?></span><input type="text" name="login_url" value="<?php echo esc_attr(TPV_Plugin::login_path()); ?>" placeholder="<?php esc_attr_e('Leave blank for automatic detection', 'ticket-pro-vn'); ?>" /></span><span><?php esc_html_e('Only enter the part after your domain, for example: dangnhap-ticket.', 'ticket-pro-vn'); ?> <?php esc_html_e('After login, customers return to the Ticket page they opened.', 'ticket-pro-vn'); ?></span></label>
                    </section>

                    <section class="tpv-settings-card tpv-settings-card-attachments">
                        <div class="tpv-settings-card-head"><span class="dashicons dashicons-paperclip"></span><div><h2><?php esc_html_e('Attachments', 'ticket-pro-vn'); ?></h2><p><?php esc_html_e('Choose only the file categories your support team needs. Image uploads are optimized as WebP before storage.', 'ticket-pro-vn'); ?></p></div></div>
                        <label class="tpv-switch"><input id="tpv-attachments-enabled" type="checkbox" name="attachments_enabled" value="1" <?php checked(!empty($settings['attachments_enabled']), true); ?> /><span class="tpv-switch-track" aria-hidden="true"></span><span><strong><?php esc_html_e('Enable attachments', 'ticket-pro-vn'); ?></strong><small><?php esc_html_e('Allow customers and support staff to attach files to a ticket.', 'ticket-pro-vn'); ?></small></span></label>
                        <div id="tpv-attachment-options" class="tpv-attachment-options<?php echo empty($settings['attachments_enabled']) ? ' is-disabled' : ''; ?>">
                            <div class="tpv-settings-number-grid">
                                <label><?php esc_html_e('Maximum files', 'ticket-pro-vn'); ?><input type="number" name="attachment_max_files" value="<?php echo esc_attr($settings['attachment_max_files']); ?>" min="1" max="10" /><span><?php esc_html_e('files per message', 'ticket-pro-vn'); ?></span></label>
                                <label><?php esc_html_e('Maximum size', 'ticket-pro-vn'); ?><input type="number" name="attachment_max_mb" value="<?php echo esc_attr($settings['attachment_max_mb']); ?>" min="1" max="50" /><span><?php esc_html_e('MB per file', 'ticket-pro-vn'); ?></span></label>
                            </div>
                            <p class="tpv-settings-note"><span class="dashicons dashicons-shield"></span><?php esc_html_e('Default: up to 3 files, 5 MB each. This is a maximum of 15 MB of original upload data per message.', 'ticket-pro-vn'); ?></p>
                            <fieldset class="tpv-file-types"><legend><?php esc_html_e('Allowed file types', 'ticket-pro-vn'); ?></legend><p><?php esc_html_e('Select one or more common categories. Macro-enabled Office files are always blocked.', 'ticket-pro-vn'); ?></p><div class="tpv-file-type-grid"><?php foreach (TPV_Plugin::attachment_groups() as $key => $group) : ?><label><input type="checkbox" name="attachment_types[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $selected_groups, true)); ?> /><span class="tpv-file-type-check" aria-hidden="true">&#10003;</span><span><strong><?php echo esc_html(TPV_Plugin::attachment_group_label($key)); ?></strong><small><?php echo esc_html(strtoupper(implode(', ', $group['extensions']))); ?></small></span></label><?php endforeach; ?></div></fieldset>
                        </div>
                    </section>

                    <section class="tpv-settings-card tpv-settings-card-wide">
                        <div class="tpv-settings-card-head"><span class="dashicons dashicons-hidden"></span><div><h2><?php esc_html_e('Blocked words and phrases', 'ticket-pro-vn'); ?></h2><p><?php esc_html_e('Messages containing these terms cannot be submitted. The customer is not shown which term matched.', 'ticket-pro-vn'); ?></p></div></div>
                        <label class="tpv-blacklist-label" for="tpv-blacklist-terms"><?php esc_html_e('Blocked list', 'ticket-pro-vn'); ?><textarea id="tpv-blacklist-terms" name="blacklist_terms" rows="7" placeholder="<?php esc_attr_e('Example: spam, promotional link, inappropriate phrase', 'ticket-pro-vn'); ?>"><?php echo esc_textarea(implode(",\n", $blacklist_terms)); ?></textarea></label>
                        <p class="description"><?php esc_html_e('Enter words or phrases separated by commas or new lines. Saving an empty field clears the list.', 'ticket-pro-vn'); ?></p>
                    </section>

                    <section class="tpv-settings-card tpv-settings-card-wide tpv-retention-card">
                        <div class="tpv-settings-card-head"><span class="dashicons dashicons-database"></span><div><h2><?php esc_html_e('Data retention', 'ticket-pro-vn'); ?></h2><p><?php esc_html_e('Set the retention period used when scheduled cleanup removes stored IP and audit information.', 'ticket-pro-vn'); ?></p></div></div>
                        <div class="tpv-settings-number-grid">
                            <label><?php esc_html_e('IP retention', 'ticket-pro-vn'); ?><input type="number" name="ip_retention_days" value="<?php echo esc_attr($settings['ip_retention_days']); ?>" min="1" /><span><?php esc_html_e('days', 'ticket-pro-vn'); ?></span></label>
                            <label><?php esc_html_e('Audit-log retention', 'ticket-pro-vn'); ?><input type="number" name="audit_retention_days" value="<?php echo esc_attr($settings['audit_retention_days']); ?>" min="1" /><span><?php esc_html_e('days', 'ticket-pro-vn'); ?></span></label>
                        </div>
                    </section>
                </div>
                <div class="tpv-settings-submit"><?php submit_button(__('Save settings', 'ticket-pro-vn'), 'primary large', 'submit', false); ?></div>
            </form>
            <section class="tpv-danger-zone" aria-labelledby="tpv-danger-zone-title">
                <div class="tpv-danger-zone-copy"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><h2 id="tpv-danger-zone-title"><?php esc_html_e('Danger zone', 'ticket-pro-vn'); ?></h2><p><?php esc_html_e('Permanently delete all Ticket Pro VN data before removing the plugin. This cannot be undone.', 'ticket-pro-vn'); ?></p></div></div>
                <button type="button" class="button button-secondary tpv-danger-zone-button" data-tpv-open-purge-modal="1"><?php esc_html_e('Delete all Ticket Pro VN data', 'ticket-pro-vn'); ?></button>
            </section>
            <div class="tpv-purge-modal" data-tpv-purge-modal="1" hidden>
                <div class="tpv-purge-modal-backdrop" data-tpv-close-purge-modal="1"></div>
                <section class="tpv-purge-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="tpv-purge-modal-title">
                    <button type="button" class="tpv-purge-modal-close" data-tpv-close-purge-modal="1" aria-label="<?php esc_attr_e('Close', 'ticket-pro-vn'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span><h2 id="tpv-purge-modal-title"><?php esc_html_e('Delete all Ticket Pro VN data?', 'ticket-pro-vn'); ?></h2><p><?php esc_html_e('This permanently deletes ticket history, messages, private attachments, Ticket Pro VN database tables, settings, license data, and ticket-access metadata.', 'ticket-pro-vn'); ?></p><p><?php esc_html_e('WordPress user accounts and pages are not deleted.', 'ticket-pro-vn'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tpv_purge_all_data" />
                        <?php wp_nonce_field('tpv_purge_all_data'); ?>
                        <label for="tpv-purge-confirmation"><?php esc_html_e('Type DELETE to confirm', 'ticket-pro-vn'); ?><input id="tpv-purge-confirmation" name="confirmation" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" data-tpv-purge-confirmation="1" /></label>
                        <div class="tpv-purge-modal-actions"><button type="button" class="button" data-tpv-close-purge-modal="1"><?php esc_html_e('Cancel', 'ticket-pro-vn'); ?></button><button type="submit" class="button button-primary" disabled data-tpv-confirm-purge="1"><?php esc_html_e('Delete permanently', 'ticket-pro-vn'); ?></button></div>
                    </form>
                </section>
            </div>
        </div>
        <?php
    }

    public static function license_page() {
        if (!current_user_can('manage_options')) { return; }
        $state = get_option('tpv_license_state', array());
        $stored_key = get_option('tpv_license_key', '');
        $duration = isset($state['duration_days']) ? absint($state['duration_days']) : 0;
        $term = $duration ? sprintf(_n('%d day', '%d days', $duration, 'ticket-pro-vn'), $duration) : __('Perpetual', 'ticket-pro-vn');
        $activated = !empty($state['activated_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($state['activated_at'])) : __('Not activated yet', 'ticket-pro-vn');
        $expires = !empty($state['expires_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($state['expires_at'])) : ($duration ? __('Starts on first activation', 'ticket-pro-vn') : __('Perpetual', 'ticket-pro-vn'));
        $labels = array('active' => __('Active', 'ticket-pro-vn'), 'expired' => __('Expired', 'ticket-pro-vn'), 'suspended' => __('Suspended', 'ticket-pro-vn'), 'revoked' => __('Revoked', 'ticket-pro-vn'));
        $status_key = !empty($state['status']) ? sanitize_key($state['status']) : 'pending';
        $status = isset($labels[$status_key]) ? $labels[$status_key] : __('Awaiting activation', 'ticket-pro-vn');
        ?>
        <div class="wrap tpv-license-page">
            <section class="tpv-license-hero">
                <div><span class="tpv-settings-kicker"><?php esc_html_e('Security and access', 'ticket-pro-vn'); ?></span><h1><?php esc_html_e('Ticket Pro VN License & Pro', 'ticket-pro-vn'); ?></h1><p><?php esc_html_e('A license is valid only for this HTTPS root domain. www and non-www are treated as one domain.', 'ticket-pro-vn'); ?></p></div>
                <span class="tpv-license-hero-status is-<?php echo esc_attr($status_key); ?>"><i aria-hidden="true"></i><?php echo esc_html($status); ?></span>
            </section>
            <?php self::admin_flash(); ?>

            <section class="tpv-license-card tpv-license-features-card" style="margin-bottom: 24px; padding: 20px; background: #fff; border-radius: 8px; border: 1px solid #c3c4c7;">
                <div class="tpv-license-card-head" style="margin-bottom: 16px;">
                    <span class="dashicons dashicons-star-filled" style="color: #f0b849; font-size: 28px; width: 28px; height: 28px; margin-right: 12px;"></span>
                    <div>
                        <h2 style="margin: 0 0 4px 0;"><?php esc_html_e('Free vs Pro Features', 'ticket-pro-vn'); ?></h2>
                        <p style="margin: 0; color: #646970;"><?php esc_html_e('Enjoy core support features for free, or unlock advanced business capabilities with a Pro license.', 'ticket-pro-vn'); ?></p>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                    <div style="background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 6px; padding: 16px;">
                        <h3 style="margin-top: 0; display: flex; align-items: center; justify-content: space-between;">
                            <span><?php esc_html_e('Free Edition', 'ticket-pro-vn'); ?></span>
                            <span style="background: #2271b1; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 4px;"><?php esc_html_e('Included', 'ticket-pro-vn'); ?></span>
                        </h3>
                        <ul style="list-style: none; padding: 0; margin: 12px 0 0 0; line-height: 2;">
                            <li>&#10003; <?php esc_html_e('Unlimited Customer Support Tickets', 'ticket-pro-vn'); ?></li>
                            <li>&#10003; <?php esc_html_e('Frontend Submit & My Tickets Portals', 'ticket-pro-vn'); ?></li>
                            <li>&#10003; <?php esc_html_e('Admin Ticket Management Dashboard', 'ticket-pro-vn'); ?></li>
                            <li>&#10003; <?php esc_html_e('WooCommerce & Custom Login Routing', 'ticket-pro-vn'); ?></li>
                            <li>&#10003; <?php esc_html_e('English & Vietnamese Language Packs', 'ticket-pro-vn'); ?></li>
                        </ul>
                    </div>
                    <div style="background: #f0f6fc; border: 1px solid #72aee6; border-radius: 6px; padding: 16px;">
                        <h3 style="margin-top: 0; display: flex; align-items: center; justify-content: space-between;">
                            <span style="color: #135e96;"><?php esc_html_e('Pro Edition', 'ticket-pro-vn'); ?></span>
                            <?php if ($status_key === 'active') : ?>
                                <span style="background: #008a20; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 4px;"><?php esc_html_e('Active', 'ticket-pro-vn'); ?></span>
                            <?php else : ?>
                                <span style="background: #dba617; color: #000; font-size: 11px; padding: 2px 8px; border-radius: 4px; font-weight: bold;"><?php esc_html_e('Get Pro', 'ticket-pro-vn'); ?></span>
                            <?php endif; ?>
                        </h3>
                        <ul style="list-style: none; padding: 0; margin: 12px 0 0 0; line-height: 2;">
                            <li>&#9733; <?php esc_html_e('Multi-Format File Attachments (WebP, PDF, Office)', 'ticket-pro-vn'); ?></li>
                            <li>&#9733; <?php esc_html_e('Support Agent Assignment & Dedicated Roles', 'ticket-pro-vn'); ?></li>
                            <li>&#9733; <?php esc_html_e('Spam Keyword Blacklist & Link Blocking', 'ticket-pro-vn'); ?></li>
                            <li>&#9733; <?php esc_html_e('Customer IP Blocking & Automated Data Retention', 'ticket-pro-vn'); ?></li>
                            <li>&#9733; <?php esc_html_e('Priority Technical Support from OSB Team', 'ticket-pro-vn'); ?></li>
                        </ul>
                        <div style="margin-top: 14px;">
                            <a href="https://osb.vn" target="_blank" rel="noopener noreferrer" class="button button-primary" style="text-decoration: none;">
                                <?php esc_html_e('Get a License Key on OSB.VN', 'ticket-pro-vn'); ?> &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <div class="tpv-license-grid<?php echo $state ? '' : ' is-unactivated'; ?>">
                <?php if ($state) : ?>
                    <section class="tpv-license-card tpv-license-state-card">
                        <div class="tpv-license-card-head"><span class="dashicons dashicons-shield-alt"></span><div><h2><?php esc_html_e('Current state', 'ticket-pro-vn'); ?></h2><p><?php esc_html_e('This signed state is confirmed by the Ticket Pro VN license service.', 'ticket-pro-vn'); ?></p></div></div>
                        <table class="tpv-license-state-table"><tbody><tr><th><?php esc_html_e('Status', 'ticket-pro-vn'); ?></th><td><span class="tpv-license-status is-<?php echo esc_attr($status_key); ?>"><i aria-hidden="true"></i><?php echo esc_html($status); ?></span></td></tr><tr><th><?php esc_html_e('License term', 'ticket-pro-vn'); ?></th><td><?php echo esc_html($term); ?></td></tr><tr><th><?php esc_html_e('Activation date', 'ticket-pro-vn'); ?></th><td><?php echo esc_html($activated); ?></td></tr><tr><th><?php esc_html_e('Expiry date', 'ticket-pro-vn'); ?></th><td><?php echo esc_html($expires); ?></td></tr></tbody></table>
                    </section>
                <?php endif; ?>
                <section class="tpv-license-card tpv-license-action-card">
                    <div class="tpv-license-card-head"><span class="dashicons dashicons-admin-network"></span><div><h2><?php echo esc_html($stored_key ? __('License actions', 'ticket-pro-vn') : __('Activate license', 'ticket-pro-vn')); ?></h2><p><?php esc_html_e('The license service and verification key are built into Ticket Pro VN and cannot be changed here.', 'ticket-pro-vn'); ?></p></div></div>
                    <form class="tpv-license-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
                        <input type="hidden" name="action" value="tpv_activate_license" />
                        <?php wp_nonce_field('tpv_activate_license'); ?>
                        <?php if ($stored_key) : ?>
                            <div class="tpv-license-key-safe"><span class="dashicons dashicons-lock" aria-hidden="true"></span><p><?php esc_html_e('Your current key is stored securely and is not shown here.', 'ticket-pro-vn'); ?></p></div>
                            <details class="tpv-license-change-key"><summary><?php esc_html_e('Change license key', 'ticket-pro-vn'); ?><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div><p><?php esc_html_e('Enter a new key only when your provider issued a replacement. Leave it empty to keep the current key.', 'ticket-pro-vn'); ?></p><label><?php esc_html_e('License key', 'ticket-pro-vn'); ?><input type="text" name="license_key" autocomplete="off" autocapitalize="none" spellcheck="false" inputmode="text" placeholder="TPV-..." /></label></div></details>
                            <?php submit_button(__('Revalidate license', 'ticket-pro-vn'), 'primary large', 'submit', false); ?>
                        <?php else : ?>
                            <label class="tpv-license-key-input"><?php esc_html_e('License key', 'ticket-pro-vn'); ?><input required type="text" name="license_key" autocomplete="off" autocapitalize="none" spellcheck="false" inputmode="text" placeholder="TPV-..." /><span><?php esc_html_e('Enter the license key provided by your provider.', 'ticket-pro-vn'); ?></span></label>
                            <?php submit_button(__('Activate and validate', 'ticket-pro-vn'), 'primary large', 'submit', false); ?>
                        <?php endif; ?>
                    </form>
                </section>
            </div>
        </div>
        <?php
    }

    public static function save_settings() {
        self::verify('tpv_save_settings');
        $defaults = TPV_Plugin::defaults(); $existing = TPV_Plugin::settings(); $settings = array();
        $limits = array('subject_limit' => 500, 'message_limit' => 50000, 'attachment_max_files' => 10, 'attachment_max_mb' => 50, 'ip_retention_days' => 3650, 'audit_retention_days' => 3650);
        foreach ($limits as $key => $maximum) { $settings[$key] = min($maximum, max(1, absint(isset($_POST[$key]) ? $_POST[$key] : $existing[$key]))); }
        $settings['attachments_enabled'] = empty($_POST['attachments_enabled']) ? 0 : 1;
        $login_url = isset($_POST['login_url']) ? wp_unslash($_POST['login_url']) : '';
        $settings['login_url'] = TPV_Plugin::normalize_login_url($login_url);
        $posted_groups = isset($_POST['attachment_types']) && is_array($_POST['attachment_types']) ? wp_unslash($_POST['attachment_types']) : TPV_Plugin::attachment_groups_from_extensions(TPV_Plugin::attachment_extensions($existing));
        $extensions = TPV_Plugin::attachment_extensions_from_groups($posted_groups);
        if (!$extensions) { $extensions = TPV_Plugin::attachment_extensions($defaults); }
        $settings['attachment_mimes'] = implode(',', $extensions);
        update_option('tpv_settings', $settings, false);
        $blacklist_value = isset($_POST['blacklist_terms']) ? wp_unslash($_POST['blacklist_terms']) : '';
        update_option('tpv_blacklist_terms', self::normalize_blacklist_terms($blacklist_value), false);
        TPV_DB::audit('settings_updated', 'settings', 0, array()); self::redirect_admin('tpv-settings', __('Settings saved.', 'ticket-pro-vn'));
    }

    public static function purge_all_data() {
        self::verify('tpv_purge_all_data');
        $confirmation = isset($_POST['confirmation']) ? trim((string) wp_unslash($_POST['confirmation'])) : '';
        if ($confirmation !== 'DELETE') {
            self::redirect_admin('tpv-settings', __('To delete data, type DELETE exactly as shown.', 'ticket-pro-vn'), true);
        }
        $result = TPV_DB::purge_all_data();
        if (is_wp_error($result)) {
            self::redirect_admin('tpv-settings', $result->get_error_message(), true);
        }
        self::redirect_admin('tpv-settings', sprintf(__('Ticket Pro VN data was permanently deleted: %1$d attachments and %2$d database tables removed.', 'ticket-pro-vn'), $result['attachments'], $result['tables']));
    }

    public static function normalize_blacklist_terms($value) {
        $terms = preg_split('/[\r\n,]+/', (string) $value);
        $normalized = array();
        $seen = array();
        foreach ($terms as $term) {
            $term = trim(sanitize_text_field($term));
            $key = TPV_Security::lower($term);
            if ($term !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $normalized[] = $term;
            }
        }
        return $normalized;
    }

    public static function activate_license() {
        self::verify('tpv_activate_license');
        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        if (!$key) { $key = get_option('tpv_license_key', ''); }
        if (!$key) { self::redirect_admin('tpv-license', __('Enter the license key provided by your provider.', 'ticket-pro-vn'), true); }
        $result = TPV_License::request('activate', $key);
        self::redirect_admin('tpv-license', is_wp_error($result) ? $result->get_error_message() : __('License activated.', 'ticket-pro-vn'), is_wp_error($result));
    }

    public static function change_ticket_status() {
        self::verify_manager('tpv_change_ticket_status'); $ticket_id = absint($_POST['ticket_id']); $status = sanitize_key($_POST['status']);
        if (!in_array($status, array('open','answered','waiting_customer','closed'), true)) { wp_die(esc_html__('Invalid status.', 'ticket-pro-vn'), 400); }
        global $wpdb; $wpdb->update(TPV_DB::table('tickets'), array('status' => $status, 'updated_at' => current_time('mysql', true)), array('id' => $ticket_id), array('%s','%s'), array('%d')); TPV_DB::audit('ticket_status_changed', 'ticket', $ticket_id, array('status' => $status));
        wp_safe_redirect(add_query_arg(array('page'=>'tpv-tickets','ticket_id'=>$ticket_id), admin_url('admin.php'))); exit;
    }

    public static function assign_ticket() {
        self::verify_manager('tpv_assign_ticket');
        $ticket_id = absint(isset($_POST['ticket_id']) ? $_POST['ticket_id'] : 0);
        $assignee_id = absint(isset($_POST['assignee_id']) ? $_POST['assignee_id'] : 0);
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . TPV_DB::table('tickets') . ' WHERE id=%d', $ticket_id));
        if (!$ticket) { wp_die(esc_html__('Ticket not found.', 'ticket-pro-vn'), 404); }
        if ($assignee_id) {
            $assignee = get_user_by('id', $assignee_id);
            if (!$assignee || !user_can($assignee, 'tpv_manage_tickets')) { wp_die(esc_html__('Invalid support agent.', 'ticket-pro-vn'), 400); }
        }
        $wpdb->update(TPV_DB::table('tickets'), array('assignee_id' => $assignee_id, 'updated_at' => current_time('mysql', true)), array('id' => $ticket_id), array('%d','%s'), array('%d'));
        TPV_DB::audit('ticket_assigned', 'ticket', $ticket_id, array('assignee_id' => $assignee_id));
        wp_safe_redirect(add_query_arg(array('page'=>'tpv-tickets','ticket_id'=>$ticket_id), admin_url('admin.php'))); exit;
    }

    public static function block_ip() {
        self::verify('tpv_block_ip'); $ip = sanitize_text_field($_POST['ip']); if (!filter_var($ip, FILTER_VALIDATE_IP)) { wp_die(esc_html__('Invalid IP.', 'ticket-pro-vn'), 400); }
        global $wpdb; $wpdb->replace(TPV_DB::table('ip_blocks'), array('ip_hash'=>TPV_Security::hash_ip($ip),'ip_display'=>$ip,'reason'=>'','blocked_by'=>get_current_user_id(),'created_at'=>current_time('mysql', true)), array('%s','%s','%s','%d','%s')); TPV_DB::audit('ip_blocked', 'ip', 0, array('ip' => $ip));
        wp_safe_redirect(wp_get_referer()); exit;
    }

    public static function user_fields($user) {
        if (!current_user_can('manage_options')) { return; }
        $enabled = get_user_meta($user->ID, 'tpv_ticket_enabled', true) !== '0';
        echo '<h2>' . esc_html__('Ticket Pro VN access', 'ticket-pro-vn') . '</h2><table class="form-table"><tr><th><label for="tpv_ticket_enabled">' . esc_html__('Enable ticket access', 'ticket-pro-vn') . '</label></th><td><label><input name="tpv_ticket_enabled" id="tpv_ticket_enabled" type="checkbox" value="1" ' . checked($enabled, true, false) . ' /> ' . esc_html__('Enabled by default for registered users. Uncheck to suspend this user from creating or replying to tickets.', 'ticket-pro-vn') . '</label></td></tr><tr><th><label for="tpv_ticket_expires_at">' . esc_html__('Support access expiry', 'ticket-pro-vn') . '</label></th><td><input name="tpv_ticket_expires_at" id="tpv_ticket_expires_at" type="date" value="' . esc_attr(get_user_meta($user->ID, 'tpv_ticket_expires_at', true)) . '" /></td></tr></table>';
    }

    public static function enable_new_user($user_id) {
        if (get_user_meta($user_id, 'tpv_ticket_enabled', true) === '') {
            update_user_meta($user_id, 'tpv_ticket_enabled', '1');
        }
    }

    public static function save_user_fields($user_id) {
        if (!current_user_can('manage_options') || !current_user_can('edit_user', $user_id)) { return; }
        update_user_meta($user_id, 'tpv_ticket_enabled', empty($_POST['tpv_ticket_enabled']) ? '0' : '1');
        $date = isset($_POST['tpv_ticket_expires_at']) ? sanitize_text_field($_POST['tpv_ticket_expires_at']) : ''; update_user_meta($user_id, 'tpv_ticket_expires_at', preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : ''); TPV_DB::audit('user_access_updated', 'user', $user_id, array('enabled' => empty($_POST['tpv_ticket_enabled']) ? 0 : 1));
    }

    public static function user_list_column($columns) { $columns['tpv_access'] = __('Ticket access', 'ticket-pro-vn'); return $columns; }
    public static function user_list_value($value, $column, $user_id) { if ($column !== 'tpv_access') { return $value; } $user = get_user_by('id', $user_id); $manager = $user && (user_can($user, 'manage_options') || user_can($user, 'tpv_manage_tickets')); $enabled = $manager || get_user_meta($user_id, 'tpv_ticket_enabled', true) !== '0'; $expires = get_user_meta($user_id, 'tpv_ticket_expires_at', true); return $enabled ? esc_html($expires ? sprintf(__('Enabled until %s', 'ticket-pro-vn'), $expires) : __('Enabled', 'ticket-pro-vn')) : esc_html__('Disabled', 'ticket-pro-vn'); }
    public static function user_bulk_actions($actions) { $actions['tpv_enable_30'] = __('Enable ticket access for 30 days', 'ticket-pro-vn'); $actions['tpv_enable_365'] = __('Enable ticket access for 365 days', 'ticket-pro-vn'); $actions['tpv_disable'] = __('Disable ticket access', 'ticket-pro-vn'); return $actions; }
    public static function handle_user_bulk($redirect, $action, $user_ids) { if (!current_user_can('manage_options') || !in_array($action, array('tpv_enable_30','tpv_enable_365','tpv_disable'), true)) { return $redirect; } foreach ($user_ids as $user_id) { if ($action === 'tpv_disable') { update_user_meta($user_id, 'tpv_ticket_enabled', '0'); } else { $days = $action === 'tpv_enable_30' ? 30 : 365; update_user_meta($user_id, 'tpv_ticket_enabled', '1'); update_user_meta($user_id, 'tpv_ticket_expires_at', gmdate('Y-m-d', time() + $days * DAY_IN_SECONDS)); } TPV_DB::audit('user_access_bulk_updated', 'user', $user_id, array('action' => $action)); } return add_query_arg('tpv_bulk_updated', count($user_ids), $redirect); }

    private static function verify($action) { if (!current_user_can('manage_options') || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], $action)) { wp_die(esc_html__('Invalid request.', 'ticket-pro-vn'), 403); } }
    private static function verify_manager($action) { if (!TPV_Security::can_manage_tickets() || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], $action)) { wp_die(esc_html__('Invalid request.', 'ticket-pro-vn'), 403); } }
    private static function admin_flash() {
        $key = 'tpv_admin_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if (!$flash || !is_array($flash) || empty($flash['message'])) { return; }
        delete_transient($key);
        $error = !empty($flash['error']);
        echo '<div class="tpv-admin-flash ' . ($error ? 'is-error' : 'is-success') . '" role="' . ($error ? 'alert' : 'status') . '" aria-live="polite"><span class="dashicons ' . ($error ? 'dashicons-warning' : 'dashicons-yes-alt') . '" aria-hidden="true"></span><p>' . esc_html($flash['message']) . '</p><button type="button" class="tpv-admin-flash-close" aria-label="' . esc_attr__('Dismiss notification', 'ticket-pro-vn') . '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></div>';
    }

    private static function redirect_admin($page, $message, $error = false) { set_transient('tpv_admin_flash_' . get_current_user_id(), array('message' => $message, 'error' => (bool) $error), MINUTE_IN_SECONDS); wp_safe_redirect(add_query_arg('page', $page, admin_url('admin.php'))); exit; }
}
