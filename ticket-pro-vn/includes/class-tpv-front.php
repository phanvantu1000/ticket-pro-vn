<?php

if (!defined('ABSPATH')) {
    exit;
}

class TPV_Front {
    public static function register() {
        add_shortcode('tpv_submit_ticket', array(__CLASS__, 'submit_shortcode'));
        add_shortcode('tpv_my_tickets', array(__CLASS__, 'tickets_shortcode'));
        add_action('admin_post_tpv_create_ticket', array(__CLASS__, 'create_ticket'));
        add_action('admin_post_tpv_reply_ticket', array(__CLASS__, 'reply_ticket'));
        add_action('admin_post_tpv_add_participant', array(__CLASS__, 'add_participant'));
        add_action('admin_post_tpv_remove_participant', array(__CLASS__, 'remove_participant'));
        add_action('admin_post_tpv_download_attachment', array(__CLASS__, 'download_attachment'));
    }

    public static function submit_shortcode() {
        self::ensure_assets();
        ob_start();
        $access = TPV_Security::user_can_submit(get_current_user_id());
        self::notice();
        echo self::logout_button();
        if (!is_user_logged_in()) {
            echo self::login_prompt(__('Please sign in to use support tickets.', 'ticket-pro-vn'));
            return ob_get_clean();
        }
        if (is_wp_error($access)) {
            echo '<p class="tpv-error">' . esc_html($access->get_error_message()) . '</p>';
            return ob_get_clean();
        }
        $settings = TPV_Plugin::settings();
        $attachment_formats = strtoupper(implode(', ', TPV_Plugin::attachment_extensions($settings)));
        $expiry = get_user_meta(get_current_user_id(), 'tpv_ticket_expires_at', true);
        ?>
        <section class="tpv-portal-head ez-toc-exclude-headings">
            <div><span class="tpv-kicker"><?php esc_html_e('Customer support', 'ticket-pro-vn'); ?></span><h1><?php esc_html_e('How can we help?', 'ticket-pro-vn'); ?></h1><p><?php esc_html_e('Describe your request clearly. Our team will reply in the same ticket thread.', 'ticket-pro-vn'); ?></p></div>
            <a class="tpv-outline-button" href="<?php echo esc_url(TPV_Plugin::my_tickets_url()); ?>"><?php esc_html_e('View my tickets', 'ticket-pro-vn'); ?></a>
        </section>
        <div class="tpv-guidance"><span><?php esc_html_e('Secure support', 'ticket-pro-vn'); ?></span><span><?php esc_html_e('Replies stay in one conversation', 'ticket-pro-vn'); ?></span><?php if ($expiry) : ?><span><?php echo esc_html(sprintf(__('Access until %s', 'ticket-pro-vn'), mysql2date(get_option('date_format'), $expiry))); ?></span><?php endif; ?></div>
        <form class="tpv-form tpv-compose" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tpv_create_ticket" />
            <?php wp_nonce_field('tpv_create_ticket'); ?>
            <div class="tpv-field-grid"><p><label><?php esc_html_e('Support topic', 'ticket-pro-vn'); ?><select name="category"><?php foreach (TPV_Plugin::categories() as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></p><p><label><?php esc_html_e('Priority', 'ticket-pro-vn'); ?><select name="priority"><?php foreach (TPV_Plugin::priorities() as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></p></div>
            <p><label><?php esc_html_e('Subject', 'ticket-pro-vn'); ?><span class="tpv-field-help"><?php esc_html_e('A short summary helps us triage your request.', 'ticket-pro-vn'); ?></span><input required maxlength="<?php echo esc_attr($settings['subject_limit']); ?>" type="text" name="subject" placeholder="<?php esc_attr_e('For example: I cannot sign in to my account', 'ticket-pro-vn'); ?>" /></label></p>
            <p><label><?php esc_html_e('Message', 'ticket-pro-vn'); ?><span class="tpv-field-help"><?php esc_html_e('Include steps, error messages, and relevant details. Links are not accepted for security.', 'ticket-pro-vn'); ?></span><textarea required maxlength="<?php echo esc_attr($settings['message_limit']); ?>" name="body" rows="8" placeholder="<?php esc_attr_e('Please tell us what happened and what you expected.', 'ticket-pro-vn'); ?>"></textarea></label></p>
            <p class="tpv-participant-field"><label><?php esc_html_e('Related WordPress user', 'ticket-pro-vn'); ?><span class="tpv-field-help"><?php esc_html_e('Optional. An administrator can add someone who should be able to view and reply to this ticket later.', 'ticket-pro-vn'); ?></span><input type="text" name="user_lookup" autocomplete="off" placeholder="<?php esc_attr_e('Username or account email', 'ticket-pro-vn'); ?>" /></label></p>
            <?php if (!empty($settings['attachments_enabled'])) : ?>
                <p class="tpv-attachments"><label><?php esc_html_e('Attachments', 'ticket-pro-vn'); ?><span class="tpv-field-help"><?php echo esc_html(sprintf(__('Up to %1$d files, %2$d MB each.', 'ticket-pro-vn'), $settings['attachment_max_files'], $settings['attachment_max_mb'])); ?> <?php echo esc_html(sprintf(__('Allowed formats: %s.', 'ticket-pro-vn'), $attachment_formats)); ?> <?php esc_html_e('Images are converted to optimized WebP before being stored.', 'ticket-pro-vn'); ?></span></label><?php echo self::attachment_picker($settings, 'tpv-create-attachments'); ?></p>
            <?php endif; ?>
            <div class="tpv-compose-footer"><p class="tpv-privacy"><?php esc_html_e('Your account information, message, attachments, and IP address are recorded to provide support.', 'ticket-pro-vn'); ?></p><button type="submit"><?php esc_html_e('Submit ticket', 'ticket-pro-vn'); ?></button></div>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function tickets_shortcode() {
        global $wpdb;
        self::ensure_assets();
        ob_start();
        if (!is_user_logged_in()) {
            echo self::login_prompt(__('Please sign in to view your tickets.', 'ticket-pro-vn'));
            return ob_get_clean();
        }
        self::notice();
        echo self::logout_button();
        $ticket_id = isset($_GET['tpv_ticket']) ? absint($_GET['tpv_ticket']) : 0;
        if ($ticket_id) {
            self::ticket_detail($ticket_id, get_current_user_id(), false);
            return ob_get_clean();
        }
        $table = TPV_DB::table('tickets');
        $participants_table = TPV_DB::table('participants');
        $is_support_agent = TPV_Security::can_manage_tickets();
        $status = isset($_GET['tpv_status']) ? sanitize_key($_GET['tpv_status']) : '';
        $search = isset($_GET['tpv_search']) ? sanitize_text_field(wp_unslash($_GET['tpv_search'])) : '';
        if ($is_support_agent) {
            $where = 't.assignee_id = %d';
            $args = array(get_current_user_id());
        } else {
            $where = '(t.user_id = %d OR EXISTS (SELECT 1 FROM ' . $participants_table . ' p WHERE p.ticket_id = t.id AND p.user_id = %d))';
            $args = array(get_current_user_id(), get_current_user_id());
        }
        if (in_array($status, array('open', 'answered', 'waiting_customer', 'closed'), true)) {
            $where .= ' AND t.status = %s';
            $args[] = $status;
        }
        if ($search !== '') {
            $where .= ' AND (t.ticket_code LIKE %s OR t.subject LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $args[] = $like;
            $args[] = $like;
        }
        $sql = $wpdb->prepare('SELECT t.*, owner.display_name AS owner_name, agent.display_name AS assignee_name FROM ' . $table . ' t LEFT JOIN ' . $wpdb->users . ' owner ON owner.ID = t.user_id LEFT JOIN ' . $wpdb->users . ' agent ON agent.ID = t.assignee_id WHERE ' . $where . ' ORDER BY t.updated_at DESC LIMIT 50', $args);
        $tickets = $wpdb->get_results($sql);
        ?>
        <section class="tpv-portal-head tpv-list-head ez-toc-exclude-headings"><div><span class="tpv-kicker"><?php esc_html_e('Support center', 'ticket-pro-vn'); ?></span><h1><?php echo esc_html($is_support_agent ? __('Assigned tickets', 'ticket-pro-vn') : __('My tickets', 'ticket-pro-vn')); ?></h1><p><?php esc_html_e($is_support_agent ? 'Review and reply to tickets assigned to you.' : 'Track every request and continue the conversation here.', 'ticket-pro-vn'); ?></p></div><?php if (!$is_support_agent) : ?><a class="tpv-outline-button" href="<?php echo esc_url(TPV_Plugin::submit_ticket_url()); ?>"><?php esc_html_e('New ticket', 'ticket-pro-vn'); ?></a><?php endif; ?></section>
        <form class="tpv-search" method="get"><input type="search" name="tpv_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search tickets', 'ticket-pro-vn'); ?>" /><select name="tpv_status"><option value=""><?php esc_html_e('All statuses', 'ticket-pro-vn'); ?></option><?php foreach (array('open', 'answered', 'waiting_customer', 'closed') as $item) : ?><option value="<?php echo esc_attr($item); ?>" <?php selected($item, $status); ?>><?php echo esc_html(TPV_Plugin::status_label($item)); ?></option><?php endforeach; ?></select><button><?php esc_html_e('Filter', 'ticket-pro-vn'); ?></button></form>
        <div class="tpv-ticket-list"><?php if (!$tickets) : ?><div class="tpv-empty"><strong><?php esc_html_e($is_support_agent ? 'No tickets are assigned to you' : 'No tickets yet', 'ticket-pro-vn'); ?></strong><p><?php esc_html_e($is_support_agent ? 'New tickets assigned to you will appear here.' : 'When you need help, create a ticket and we will keep every reply here.', 'ticket-pro-vn'); ?></p><?php if (!$is_support_agent) : ?><a href="<?php echo esc_url(TPV_Plugin::submit_ticket_url()); ?>"><?php esc_html_e('Create your first ticket', 'ticket-pro-vn'); ?></a><?php endif; ?></div><?php endif; foreach ($tickets as $ticket) : ?><a class="tpv-ticket-card" href="<?php echo esc_url(add_query_arg('tpv_ticket', $ticket->id)); ?>"><span class="tpv-ticket-code"><?php echo esc_html($ticket->ticket_code); ?></span><strong><?php echo esc_html($ticket->subject); ?></strong><span class="tpv-ticket-meta"><span class="tpv-tag"><?php echo esc_html(TPV_Plugin::category_label($ticket->category)); ?></span><span class="tpv-status tpv-status-<?php echo esc_attr($ticket->status); ?>"><?php echo esc_html(TPV_Plugin::status_label($ticket->status)); ?></span><?php if ($is_support_agent && !empty($ticket->owner_name)) : ?><span class="tpv-assignee-label"><?php echo esc_html(sprintf(__('Customer: %s', 'ticket-pro-vn'), $ticket->owner_name)); ?></span><?php elseif (!$is_support_agent && !empty($ticket->assignee_name)) : ?><span class="tpv-assignee-label"><?php echo esc_html(sprintf(__('Assigned to: %s', 'ticket-pro-vn'), $ticket->assignee_name)); ?></span><?php endif; ?><time><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $ticket->updated_at)); ?></time></span></a><?php endforeach; ?></div>
        <?php
        return ob_get_clean();
    }

    public static function create_ticket() {
        self::verify_request('tpv_create_ticket');
        $user_id = get_current_user_id();
        $access = TPV_Security::user_can_submit($user_id);
        if (is_wp_error($access)) { self::redirect_notice($access->get_error_message(), true); }
        $ip = TPV_Security::client_ip();
        if (TPV_Security::is_ip_blocked($ip)) { self::redirect_notice(__('Your support access is blocked.', 'ticket-pro-vn'), true); }
        if (TPV_Security::rate_limited('create', $user_id, $ip)) { self::redirect_notice(__('Please wait before submitting another ticket.', 'ticket-pro-vn'), true); }
        $subject = sanitize_text_field(wp_unslash(isset($_POST['subject']) ? $_POST['subject'] : ''));
        $body = sanitize_textarea_field(wp_unslash(isset($_POST['body']) ? $_POST['body'] : ''));
        $related_lookup = sanitize_text_field(wp_unslash(isset($_POST['user_lookup']) ? $_POST['user_lookup'] : ''));
        $category = sanitize_key(isset($_POST['category']) ? $_POST['category'] : 'general');
        $priority = sanitize_key(isset($_POST['priority']) ? $_POST['priority'] : 'normal');
        if (!array_key_exists($category, TPV_Plugin::categories())) { $category = 'general'; }
        if (!array_key_exists($priority, TPV_Plugin::priorities())) { $priority = 'normal'; }
        if (!$subject || !$body) { self::redirect_notice(__('Subject and message are required.', 'ticket-pro-vn'), true); }
        $related_user = $related_lookup ? self::user_from_lookup($related_lookup) : false;
        if ($related_lookup && !$related_user) { self::redirect_notice(__('The related user was not found.', 'ticket-pro-vn'), true); }
        if ($related_user && (int) $related_user->ID === (int) $user_id) { self::redirect_notice(__('Choose another WordPress user as the related person.', 'ticket-pro-vn'), true); }
        if ($related_user && !TPV_Security::user_email_verified($related_user->ID)) { self::redirect_notice(__('The related user must have a verified email address.', 'ticket-pro-vn'), true); }
        $valid = TPV_Security::valid_message($body, $subject);
        if (is_wp_error($valid)) { self::redirect_notice($valid->get_error_message(), true); }
        global $wpdb;
        $now = current_time('mysql', true);
        $created = $wpdb->insert(TPV_DB::table('tickets'), array('ticket_code' => TPV_Plugin::ticket_code(), 'user_id' => $user_id, 'subject' => $subject, 'category' => $category, 'priority' => $priority, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now, 'last_message_at' => $now), array('%s','%d','%s','%s','%s','%s','%s','%s','%s'));
        $ticket_id = (int) $wpdb->insert_id;
        if (!$created || !$ticket_id) { self::redirect_notice(__('Your ticket could not be created. Please try again.', 'ticket-pro-vn'), true); }
        if ($related_user) {
            $participant_added = $wpdb->insert(TPV_DB::table('participants'), array('ticket_id' => $ticket_id, 'user_id' => $related_user->ID, 'participant_role' => 'related', 'added_by' => $user_id, 'added_at' => $now), array('%d', '%d', '%s', '%d', '%s'));
            if (!$participant_added) { $wpdb->delete(TPV_DB::table('tickets'), array('id' => $ticket_id), array('%d')); self::redirect_notice(__('The related user could not be added. Please try again.', 'ticket-pro-vn'), true); }
        }
        $message_id = self::insert_message($ticket_id, $user_id, 'customer', $body, $ip);
        $uploads = self::store_uploads($message_id);
        $audit_data = array('user_id' => $user_id, 'category' => $category, 'priority' => $priority);
        if ($related_user) { $audit_data['related_user_id'] = $related_user->ID; }
        TPV_DB::audit('ticket_created', 'ticket', $ticket_id, $audit_data);
        self::email_admin_new_ticket($ticket_id);
        $notice = !empty($uploads['rejected']) ? __('Your ticket has been submitted, but one or more attachments were not added. Check the allowed file types and limits.', 'ticket-pro-vn') : __('Your ticket has been submitted.', 'ticket-pro-vn');
        self::redirect_notice($notice, false, $ticket_id);
    }

    public static function reply_ticket() {
        self::verify_request('tpv_reply_ticket');
        $ticket_id = absint(isset($_POST['ticket_id']) ? $_POST['ticket_id'] : 0);
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPV_DB::table('tickets') . ' WHERE id = %d', $ticket_id));
        if (!$ticket) { self::redirect_notice(__('Ticket not found.', 'ticket-pro-vn'), true); }
        $user_id = get_current_user_id();
        $is_manager = TPV_Security::can_manage_tickets();
        $access = TPV_Security::user_can_reply_ticket($ticket, $user_id);
        if (is_wp_error($access)) { self::redirect_notice($access->get_error_message(), true, $ticket_id); }
        if ($ticket->status === 'closed' && !$is_manager) { self::redirect_notice(__('This ticket is closed.', 'ticket-pro-vn'), true, $ticket_id); }
        $ip = TPV_Security::client_ip();
        if (TPV_Security::is_ip_blocked($ip) || TPV_Security::rate_limited('reply', $user_id, $ip)) { self::redirect_notice(__('Your reply cannot be submitted right now.', 'ticket-pro-vn'), true, $ticket_id); }
        $body = sanitize_textarea_field(wp_unslash(isset($_POST['body']) ? $_POST['body'] : ''));
        $valid = TPV_Security::valid_message($body, '');
        if (!$body || is_wp_error($valid)) { self::redirect_notice(is_wp_error($valid) ? $valid->get_error_message() : __('Message is required.', 'ticket-pro-vn'), true, $ticket_id); }
        $role = $is_manager ? 'agent' : 'customer';
        $message_id = self::insert_message($ticket_id, $user_id, $role, $body, $ip);
        $uploads = self::store_uploads($message_id);
        $status = $is_manager ? 'answered' : 'waiting_customer';
        $wpdb->update(TPV_DB::table('tickets'), array('status' => $status, 'updated_at' => current_time('mysql', true), 'last_message_at' => current_time('mysql', true)), array('id' => $ticket_id), array('%s','%s','%s'), array('%d'));
        TPV_DB::audit('ticket_replied', 'ticket', $ticket_id, array('role' => $role));
        self::email_ticket_update($ticket_id, $body, $user_id);
        if ($is_manager) {
            wp_safe_redirect(add_query_arg(array('page' => 'tpv-tickets', 'ticket_id' => $ticket_id), admin_url('admin.php')));
            exit;
        }
        $notice = !empty($uploads['rejected']) ? __('Your reply has been submitted, but one or more attachments were not added. Check the allowed file types and limits.', 'ticket-pro-vn') : __('Your reply has been submitted.', 'ticket-pro-vn');
        self::redirect_notice($notice, false, $ticket_id);
    }

    public static function add_participant() {
        self::verify_request('tpv_add_participant');
        $ticket_id = absint(isset($_POST['ticket_id']) ? $_POST['ticket_id'] : 0);
        $lookup = sanitize_text_field(wp_unslash(isset($_POST['user_lookup']) ? $_POST['user_lookup'] : ''));
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPV_DB::table('tickets') . ' WHERE id=%d', $ticket_id));
        $can_manage = TPV_Security::can_manage_tickets() || ($ticket && (int) $ticket->user_id === get_current_user_id());
        if (!$ticket || !$can_manage) { self::redirect_notice(__('You cannot manage participants for this ticket.', 'ticket-pro-vn'), true, $ticket_id); }
        $user = self::user_from_lookup($lookup);
        if (!$user) { self::redirect_notice(__('The related user was not found.', 'ticket-pro-vn'), true, $ticket_id); }
        if ((int) $user->ID === (int) $ticket->user_id) { self::redirect_notice(__('This user already owns the ticket.', 'ticket-pro-vn'), true, $ticket_id); }
        $result = $wpdb->insert(TPV_DB::table('participants'), array('ticket_id' => $ticket_id, 'user_id' => $user->ID, 'participant_role' => 'related', 'added_by' => get_current_user_id(), 'added_at' => current_time('mysql', true)), array('%d', '%d', '%s', '%d', '%s'));
        if (!$result) { self::redirect_notice(__('This user is already related to the ticket.', 'ticket-pro-vn'), true, $ticket_id); }
        TPV_DB::audit('ticket_participant_added', 'ticket', $ticket_id, array('user_id' => $user->ID));
        self::email_ticket_update($ticket_id, __('You were added as a related person on this ticket.', 'ticket-pro-vn'), get_current_user_id(), array($user->ID));
        self::redirect_notice(__('The related user was added to this ticket.', 'ticket-pro-vn'), false, $ticket_id);
    }

    public static function remove_participant() {
        self::verify_request('tpv_remove_participant');
        $ticket_id = absint(isset($_POST['ticket_id']) ? $_POST['ticket_id'] : 0);
        $participant_id = absint(isset($_POST['participant_id']) ? $_POST['participant_id'] : 0);
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPV_DB::table('tickets') . ' WHERE id=%d', $ticket_id));
        $can_manage = TPV_Security::can_manage_tickets() || ($ticket && (int) $ticket->user_id === get_current_user_id());
        if (!$ticket || !$can_manage) { self::redirect_notice(__('You cannot manage participants for this ticket.', 'ticket-pro-vn'), true, $ticket_id); }
        $participant = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPV_DB::table('participants') . ' WHERE id=%d AND ticket_id=%d', $participant_id, $ticket_id));
        if (!$participant) { self::redirect_notice(__('Related user not found.', 'ticket-pro-vn'), true, $ticket_id); }
        $participant_count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TPV_DB::table('participants') . ' WHERE ticket_id=%d', $ticket_id));
        if ($participant_count <= 1) { self::redirect_notice(__('Each ticket must keep at least one related user.', 'ticket-pro-vn'), true, $ticket_id); }
        $wpdb->delete(TPV_DB::table('participants'), array('id' => $participant_id), array('%d'));
        TPV_DB::audit('ticket_participant_removed', 'ticket', $ticket_id, array('user_id' => $participant->user_id));
        self::redirect_notice(__('The related user was removed from this ticket.', 'ticket-pro-vn'), false, $ticket_id);
    }

    private static function verify_request($action) {
        if (!is_user_logged_in() || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], $action)) { wp_die(esc_html__('Invalid request.', 'ticket-pro-vn'), 403); }
    }

    private static function insert_message($ticket_id, $author_id, $role, $body, $ip) {
        global $wpdb;
        $wpdb->insert(TPV_DB::table('messages'), array('ticket_id' => $ticket_id, 'author_id' => $author_id, 'author_role' => $role, 'body' => $body, 'ip_hash' => TPV_Security::hash_ip($ip), 'ip_display' => $ip, 'created_at' => current_time('mysql', true)), array('%d','%d','%s','%s','%s','%s','%s'));
        return (int) $wpdb->insert_id;
    }

    private static function store_uploads($message_id) {
        $results = array('uploaded' => 0, 'rejected' => 0);
        $settings = TPV_Plugin::settings();
        if (empty($settings['attachments_enabled']) || empty($_FILES['tpv_files']) || empty($_FILES['tpv_files']['name']) || !is_array($_FILES['tpv_files']['name'])) { return $results; }
        $files = $_FILES['tpv_files'];
        $count = min(count($files['name']), absint($settings['attachment_max_files']));
        $allowed = TPV_Plugin::attachment_extensions($settings);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        for ($i = 0; $i < $count; $i++) {
            if (empty($files['name'][$i])) { continue; }
            if (!isset($files['error'][$i], $files['tmp_name'][$i], $files['size'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$i]) || $files['size'][$i] > absint($settings['attachment_max_mb']) * 1024 * 1024) { $results['rejected']++; continue; }
            $file = array('name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]);
            $extension = self::valid_attachment_extension($file, $allowed);
            if (!$extension) { $results['rejected']++; continue; }
            $original_name = sanitize_file_name($file['name']);
            if (TPV_Plugin::is_image_attachment_extension($extension)) {
                $file = self::prepare_webp_upload($file);
                if (!$file) { $results['rejected']++; continue; }
                $original_name = sanitize_file_name($file['name']);
            }
            add_filter('upload_dir', array(__CLASS__, 'private_upload_dir'));
            $uploaded = wp_handle_upload($file, array('test_form' => false, 'mimes' => TPV_Plugin::attachment_upload_mimes($allowed)));
            remove_filter('upload_dir', array(__CLASS__, 'private_upload_dir'));
            if (!empty($uploaded['error'])) { $results['rejected']++; continue; }
            $attachment_id = wp_insert_attachment(array('post_mime_type' => $uploaded['type'], 'post_title' => sanitize_file_name($file['name']), 'post_status' => 'private'), $uploaded['file']);
            if ($attachment_id && !is_wp_error($attachment_id)) {
                update_post_meta($attachment_id, '_tpv_private_attachment', '1');
                global $wpdb;
                $wpdb->insert(TPV_DB::table('attachments'), array('message_id' => $message_id, 'attachment_id' => $attachment_id, 'original_name' => $original_name, 'mime_type' => $uploaded['type'], 'created_at' => current_time('mysql', true)), array('%d','%d','%s','%s','%s'));
                $results['uploaded']++;
            } else {
                $results['rejected']++;
            }
        }
        return $results;
    }

    private static function valid_attachment_extension($file, $allowed) {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!$extension || !in_array($extension, $allowed, true) || !in_array($extension, TPV_Plugin::supported_attachment_extensions(), true)) {
            return false;
        }
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        $checked_extension = isset($check['ext']) ? strtolower($check['ext']) : '';
        $checked_type = isset($check['type']) ? $check['type'] : '';
        if (!$checked_extension || $checked_extension !== $extension || !$checked_type || !in_array($checked_type, TPV_Plugin::attachment_mime_types($extension), true)) {
            return false;
        }
        if (TPV_Plugin::is_image_attachment_extension($extension)) {
            $image_info = @getimagesize($file['tmp_name']);
            return $image_info && !empty($image_info['mime']) && in_array($image_info['mime'], TPV_Plugin::attachment_mime_types($extension), true) ? $extension : false;
        }
        if ($extension === 'pdf' && !self::is_pdf($file['tmp_name'])) {
            return false;
        }
        if (($extension === 'docx' || $extension === 'xlsx') && !self::is_safe_office_file($file['tmp_name'], $extension)) {
            return false;
        }
        $real_type = self::detected_file_type($file['tmp_name']);
        if ($real_type && !in_array($real_type, TPV_Plugin::attachment_mime_types($extension), true)) {
            return false;
        }
        return $extension;
    }

    private static function detected_file_type($path) {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $type = finfo_file($finfo, $path);
                finfo_close($finfo);
                return $type ? strtolower($type) : '';
            }
        }
        return function_exists('mime_content_type') ? strtolower(mime_content_type($path)) : '';
    }

    private static function is_pdf($path) {
        $handle = @fopen($path, 'rb');
        if (!$handle) { return false; }
        $signature = fread($handle, 5);
        fclose($handle);
        return $signature === '%PDF-';
    }

    private static function is_safe_office_file($path, $extension) {
        if (!class_exists('ZipArchive')) { return false; }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) { return false; }
        $required_file = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
        $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($required_file) !== false && $zip->locateName('word/vbaProject.bin') === false && $zip->locateName('xl/vbaProject.bin') === false;
        $zip->close();
        return $valid;
    }

    private static function prepare_webp_upload($file) {
        if (!function_exists('imagewebp') || !function_exists('imagecreatetruecolor')) { return false; }
        $image_info = @getimagesize($file['tmp_name']);
        if (!$image_info || empty($image_info[0]) || empty($image_info[1]) || $image_info[0] * $image_info[1] > 24000000) { return false; }
        $mime = isset($image_info['mime']) ? $image_info['mime'] : '';
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) { $source = @imagecreatefromjpeg($file['tmp_name']); }
        elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) { $source = @imagecreatefrompng($file['tmp_name']); }
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) { $source = @imagecreatefromwebp($file['tmp_name']); }
        else { return false; }
        if (!$source) { return false; }

        // Keep screenshots sharp while making storage use predictable.
        $largest_side = max($image_info[0], $image_info[1]);
        $scale = $largest_side > 1920 ? 1920 / $largest_side : 1;
        $width = max(1, (int) round($image_info[0] * $scale));
        $height = max(1, (int) round($image_info[1] * $scale));
        $optimized = imagecreatetruecolor($width, $height);
        imagealphablending($optimized, false);
        imagesavealpha($optimized, true);
        imagefill($optimized, 0, 0, imagecolorallocatealpha($optimized, 0, 0, 0, 127));
        $copied = imagecopyresampled($optimized, $source, 0, 0, 0, 0, $width, $height, $image_info[0], $image_info[1]);
        imagedestroy($source);
        if (!$copied) { imagedestroy($optimized); return false; }
        $name = sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME) . '.webp');
        $temporary_file = wp_tempnam($name);
        if (!$temporary_file) { imagedestroy($optimized); return false; }
        $saved = imagewebp($optimized, $temporary_file, 80);
        imagedestroy($optimized);
        if (!$saved || !filesize($temporary_file)) { if (file_exists($temporary_file)) { unlink($temporary_file); } return false; }
        $size = filesize($temporary_file);
        // Preserve PHP's uploaded-file marker so WordPress can move it securely.
        if (!@copy($temporary_file, $file['tmp_name'])) { unlink($temporary_file); return false; }
        unlink($temporary_file);
        return array('name' => $name, 'type' => 'image/webp', 'tmp_name' => $file['tmp_name'], 'error' => UPLOAD_ERR_OK, 'size' => $size);
    }

    public static function private_upload_dir($dirs) {
        $subdir = '/tpv-private/' . gmdate('Y/m');
        $dirs['subdir'] = $subdir;
        $dirs['path'] = $dirs['basedir'] . $subdir;
        $dirs['url'] = $dirs['baseurl'] . $subdir;
        if (!file_exists($dirs['path'])) {
            wp_mkdir_p($dirs['path']);
        }
        $access_file = $dirs['basedir'] . '/tpv-private/.htaccess';
        if (!file_exists($access_file)) {
            file_put_contents($access_file, "Require all denied\nDeny from all\n");
        }
        return $dirs;
    }

    private static function ticket_detail($ticket_id, $viewer_id, $admin) {
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPV_DB::table('tickets') . ' WHERE id=%d', $ticket_id));
        if (!$ticket || (!$admin && !TPV_Security::user_can_view_ticket($ticket, $viewer_id))) { echo '<p class="tpv-error">' . esc_html__('Ticket not found.', 'ticket-pro-vn') . '</p>'; return; }
        $messages = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . TPV_DB::table('messages') . ' WHERE ticket_id=%d ORDER BY created_at ASC', $ticket_id));
        $back_url = $admin ? admin_url('admin.php?page=tpv-tickets') : TPV_Plugin::my_tickets_url();
        $category = isset($ticket->category) ? $ticket->category : 'general';
        $priority = isset($ticket->priority) ? $ticket->priority : 'normal';
        echo '<section class="tpv-ticket-header ez-toc-exclude-headings"><a class="tpv-back-link" href="' . esc_url($back_url) . '">&larr; ' . esc_html($admin ? __('All tickets', 'ticket-pro-vn') : __('My tickets', 'ticket-pro-vn')) . '</a><div class="tpv-ticket-heading"><div><span class="tpv-ticket-code">' . esc_html($ticket->ticket_code) . '</span><h1>' . esc_html($ticket->subject) . '</h1></div><span class="tpv-status tpv-status-' . esc_attr($ticket->status) . '">' . esc_html(TPV_Plugin::status_label($ticket->status)) . '</span></div><div class="tpv-ticket-meta"><span class="tpv-tag">' . esc_html(TPV_Plugin::category_label($category)) . '</span><span class="tpv-tag tpv-priority-' . esc_attr($priority) . '">' . esc_html(TPV_Plugin::priority_label($priority)) . '</span><time>' . esc_html(sprintf(__('Updated %s', 'ticket-pro-vn'), mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $ticket->updated_at))) . '</time></div></section>';
        self::participants_panel($ticket, $viewer_id, $admin);
        echo '<section class="tpv-conversation ez-toc-exclude-headings"><h2>' . esc_html__('Conversation', 'ticket-pro-vn') . '</h2>';
        foreach ($messages as $message) {
            $author = get_user_by('id', $message->author_id);
            if ($message->author_role === 'agent' || $message->author_role === 'admin') { $role_label = __('Support team', 'ticket-pro-vn'); }
            elseif ((int) $message->author_id === (int) $viewer_id) { $role_label = __('You', 'ticket-pro-vn'); }
            elseif ((int) $message->author_id === (int) $ticket->user_id) { $role_label = __('Ticket owner', 'ticket-pro-vn'); }
            else { $role_label = __('Related person', 'ticket-pro-vn'); }
            $staff_badge = TPV_Security::is_staff_user($message->author_id) ? '<span class="tpv-staff-badge" title="' . esc_attr__('Verified support staff', 'ticket-pro-vn') . '" aria-label="' . esc_attr__('Verified support staff', 'ticket-pro-vn') . '"><span class="tpv-staff-badge-icon" aria-hidden="true">&#10003;</span></span>' : '';
            echo '<article class="tpv-message tpv-' . esc_attr($message->author_role) . '"><div class="tpv-message-top"><strong>' . esc_html($author ? $author->display_name : __('Deleted user', 'ticket-pro-vn')) . '</strong>' . $staff_badge . '<span class="tpv-role">' . esc_html($role_label) . '</span><time>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $message->created_at)) . '</time></div><div class="tpv-message-body">' . nl2br(esc_html($message->body)) . '</div>';
            $attachments = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . TPV_DB::table('attachments') . ' WHERE message_id=%d', $message->id));
            foreach ($attachments as $attachment) {
                $action = 'tpv_download_attachment_' . $attachment->id;
                $base_url = admin_url('admin-post.php?action=tpv_download_attachment&id=' . $attachment->id);
                $download_url = wp_nonce_url($base_url, $action);
                if (strpos($attachment->mime_type, 'image/') === 0) {
                    $preview_url = wp_nonce_url(add_query_arg('preview', '1', $base_url), $action);
                    echo '<div class="tpv-attachment"><a class="tpv-attachment-preview" href="' . esc_url($preview_url) . '" data-tpv-image-preview="1" title="' . esc_attr__('View image', 'ticket-pro-vn') . '"><img src="' . esc_url($preview_url) . '" alt="' . esc_attr($attachment->original_name) . '" loading="lazy" /></a><span class="tpv-attachment-name">' . esc_html($attachment->original_name) . '</span></div>';
                } else { echo '<div class="tpv-attachment-file"><span class="dashicons dashicons-media-default" aria-hidden="true"></span><a href="' . esc_url($download_url) . '" download="' . esc_attr($attachment->original_name) . '">' . esc_html($attachment->original_name) . '</a><span class="tpv-attachment-download-label">' . esc_html__('Download', 'ticket-pro-vn') . '</span></div>'; }
            }
            echo '</article>';
        }
        echo '</section>';
        $attachment_settings = TPV_Plugin::settings();
        $attachment_formats = strtoupper(implode(', ', TPV_Plugin::attachment_extensions($attachment_settings)));
        if ($ticket->status !== 'closed' || $admin) : ?>
            <form class="tpv-form tpv-reply-box ez-toc-exclude-headings" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tpv_reply_ticket" /><input type="hidden" name="ticket_id" value="<?php echo esc_attr($ticket->id); ?>" /><?php wp_nonce_field('tpv_reply_ticket'); ?><div class="tpv-reply-title"><div><span class="tpv-kicker"><?php esc_html_e('Continue this ticket', 'ticket-pro-vn'); ?></span><h2><?php esc_html_e('Write a reply', 'ticket-pro-vn'); ?></h2></div></div><p><label><?php esc_html_e('Reply', 'ticket-pro-vn'); ?><textarea name="body" required rows="6" placeholder="<?php esc_attr_e('Add any details that will help us resolve this request.', 'ticket-pro-vn'); ?>"></textarea></label></p><?php if (!empty($attachment_settings['attachments_enabled'])) : ?><p class="tpv-attachments"><label><?php esc_html_e('Attachments', 'ticket-pro-vn'); ?><span class="tpv-field-help"><?php echo esc_html(sprintf(__('Up to %1$d files, %2$d MB each.', 'ticket-pro-vn'), $attachment_settings['attachment_max_files'], $attachment_settings['attachment_max_mb'])); ?> <?php echo esc_html(sprintf(__('Allowed formats: %s.', 'ticket-pro-vn'), $attachment_formats)); ?> <?php esc_html_e('Images are converted to optimized WebP before being stored.', 'ticket-pro-vn'); ?></span></label><?php echo self::attachment_picker($attachment_settings, 'tpv-reply-attachments-' . $ticket->id); ?></p><?php endif; ?><p><button type="submit"><?php esc_html_e('Send reply', 'ticket-pro-vn'); ?></button></p></form>
        <?php endif;
    }

    private static function participants_panel($ticket, $viewer_id, $admin) {
        $owner = get_user_by('id', $ticket->user_id);
        $assignee = !empty($ticket->assignee_id) ? get_user_by('id', $ticket->assignee_id) : false;
        $participants = TPV_Plugin::ticket_participants($ticket->id);
        $can_manage = $admin || (int) $ticket->user_id === (int) $viewer_id;
        echo '<section class="tpv-participants ez-toc-exclude-headings"><div><span class="tpv-kicker">' . esc_html__('People involved', 'ticket-pro-vn') . '</span><h2>' . esc_html__('Ticket participants', 'ticket-pro-vn') . '</h2><p>' . esc_html__('Owner:', 'ticket-pro-vn') . ' <strong>' . esc_html($owner ? $owner->display_name : __('Deleted user', 'ticket-pro-vn')) . '</strong></p>';
        if ($assignee) { echo '<p>' . esc_html__('Support agent:', 'ticket-pro-vn') . ' <strong>' . esc_html($assignee->display_name) . '</strong></p>'; }
        if ($participants) {
            echo '<ul class="tpv-participant-list">';
            foreach ($participants as $participant) {
                echo '<li><span>' . esc_html($participant->display_name . ' (' . $participant->user_login . ')') . '</span>';
                if ($can_manage && count($participants) > 1) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tpv_remove_participant" /><input type="hidden" name="ticket_id" value="' . esc_attr($ticket->id) . '" /><input type="hidden" name="participant_id" value="' . esc_attr($participant->id) . '" />';
                    wp_nonce_field('tpv_remove_participant');
                    echo '<button type="submit" class="tpv-text-button">' . esc_html__('Remove', 'ticket-pro-vn') . '</button></form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        if ($can_manage && count($participants) === 1) { echo '<p class="tpv-participants-help">' . esc_html__('Each ticket must keep at least one related user.', 'ticket-pro-vn') . '</p>'; }
        echo '</div>';
        if ($can_manage) {
            echo '<form class="tpv-add-participant" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tpv_add_participant" /><input type="hidden" name="ticket_id" value="' . esc_attr($ticket->id) . '" />';
            wp_nonce_field('tpv_add_participant');
            echo '<label>' . esc_html__('Add related WordPress user', 'ticket-pro-vn') . '<input required type="text" name="user_lookup" placeholder="' . esc_attr__('Username or account email', 'ticket-pro-vn') . '" /></label><button type="submit">' . esc_html__('Add user', 'ticket-pro-vn') . '</button></form>';
        }
        echo '</section>';
    }

    public static function admin_ticket_detail($ticket_id) { self::ticket_detail($ticket_id, get_current_user_id(), true); }

    private static function user_from_lookup($lookup) {
        if (!$lookup) { return false; }
        return is_email($lookup) ? get_user_by('email', $lookup) : get_user_by('login', $lookup);
    }

    private static function attachment_picker($settings, $input_id) {
        $input_id = sanitize_html_class($input_id);
        return '<span class="tpv-file-picker">'
            . '<input id="' . esc_attr($input_id) . '" class="tpv-file-picker-input" type="file" name="tpv_files[]" multiple accept="' . esc_attr(TPV_Plugin::attachment_accept_attribute($settings)) . '" />'
            . '<label class="tpv-file-picker-button" for="' . esc_attr($input_id) . '">' . esc_html__('Choose files', 'ticket-pro-vn') . '</label>'
            . '<span class="tpv-file-picker-name" data-empty="' . esc_attr__('No files selected', 'ticket-pro-vn') . '" data-one="' . esc_attr__('1 file selected', 'ticket-pro-vn') . '" data-many="' . esc_attr__('%d files selected', 'ticket-pro-vn') . '">' . esc_html__('No files selected', 'ticket-pro-vn') . '</span>'
            . '</span>';
    }

    private static function ensure_assets() {
        wp_enqueue_style('ticket-pro-vn', TPV_URL . 'assets/css/ticket-pro-vn.css', array(), TPV_VERSION);
        wp_enqueue_script('ticket-pro-vn', TPV_URL . 'assets/js/ticket-pro-vn.js', array(), TPV_VERSION, true);
    }

    private static function logout_button() {
        if (!is_user_logged_in()) {
            return '';
        }
        return '<a class="tpv-logout-button" href="' . esc_url(TPV_Plugin::logout_url(TPV_Plugin::my_tickets_url())) . '"><span class="dashicons dashicons-exit" aria-hidden="true"></span>' . esc_html__('Log out', 'ticket-pro-vn') . '</a>';
    }

    private static function login_prompt($message) {
        $redirect = get_permalink();
        $registration_url = TPV_Plugin::registration_url($redirect);
        $actions = '<div class="tpv-auth-actions"><a class="tpv-login-button" href="' . esc_url(TPV_Plugin::login_url($redirect)) . '"><span class="dashicons dashicons-lock" aria-hidden="true"></span>' . esc_html__('Log in', 'ticket-pro-vn') . '</a>';
        if ($registration_url) {
            $actions .= '<a class="tpv-register-button" href="' . esc_url($registration_url) . '"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span>' . esc_html__('Create an account', 'ticket-pro-vn') . '</a>';
        }
        $help = $registration_url ? '<span class="tpv-auth-help">' . esc_html__('New here? Create an account first, then sign in to submit and track tickets.', 'ticket-pro-vn') . '</span>' : '';
        return '<div class="tpv-auth-prompt"><div><p>' . esc_html($message) . '</p>' . $help . '</div>' . $actions . '</div>';
    }

    public static function download_attachment() {
        $id = absint(isset($_GET['id']) ? $_GET['id'] : 0);
        if (!$id || !is_user_logged_in() || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'tpv_download_attachment_' . $id)) { wp_die(esc_html__('Invalid download request.', 'ticket-pro-vn'), 403); }
        global $wpdb;
        $attachment = $wpdb->get_row($wpdb->prepare('SELECT a.*, m.ticket_id, t.user_id FROM ' . TPV_DB::table('attachments') . ' a JOIN ' . TPV_DB::table('messages') . ' m ON a.message_id=m.id JOIN ' . TPV_DB::table('tickets') . ' t ON m.ticket_id=t.id WHERE a.id=%d', $id));
        $can_view = $attachment && (TPV_Security::can_manage_tickets() || (int) $attachment->user_id === get_current_user_id() || TPV_Plugin::is_ticket_participant($attachment->ticket_id, get_current_user_id()));
        if (!$can_view) { wp_die(esc_html__('You cannot download this file.', 'ticket-pro-vn'), 403); }
        $path = get_attached_file($attachment->attachment_id);
        if (!$path || !file_exists($path)) { wp_die(esc_html__('File not found.', 'ticket-pro-vn'), 404); }
        $preview = !empty($_GET['preview']) && strpos($attachment->mime_type, 'image/') === 0;
        nocache_headers(); header('Content-Type: ' . $attachment->mime_type); header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . rawurlencode($attachment->original_name) . '"'); header('Content-Length: ' . filesize($path)); readfile($path); exit;
    }

    private static function email_admin_new_ticket($ticket_id) {
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPV_DB::table('tickets') . ' WHERE id=%d', $ticket_id));
        if ($ticket) { self::email_ticket_update($ticket_id, __('A new ticket has been created. View it in the WordPress administration area.', 'ticket-pro-vn'), $ticket->user_id); }
    }

    private static function email_ticket_update($ticket_id, $body, $author_id, $only_user_ids = array()) {
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TPV_DB::table('tickets') . ' WHERE id=%d', $ticket_id));
        if (!$ticket) { return; }
        $recipient_ids = $only_user_ids;
        if (!$recipient_ids) {
            $recipient_ids = array($ticket->user_id);
            foreach (TPV_Plugin::ticket_participants($ticket_id) as $participant) { $recipient_ids[] = $participant->user_id; }
            foreach (TPV_Plugin::support_agents() as $agent) { $recipient_ids[] = $agent->ID; }
        }
        $recipient_ids = array_unique(array_map('absint', $recipient_ids));
        $author_is_agent = user_can($author_id, 'tpv_manage_tickets');
        foreach ($recipient_ids as $recipient_id) {
            if (!$recipient_id || (int) $recipient_id === (int) $author_id) { continue; }
            $user = get_user_by('id', $recipient_id);
            if (!$user) { continue; }
            $switched = function_exists('switch_to_locale') ? switch_to_locale(get_user_locale($user)) : false;
            $subject = $author_is_agent ? sprintf(__('[%s] Support replied', 'ticket-pro-vn'), $ticket->ticket_code) : sprintf(__('[%s] Ticket updated', 'ticket-pro-vn'), $ticket->ticket_code);
            wp_mail($user->user_email, $subject, $body . "\n\n" . add_query_arg('tpv_ticket', $ticket_id, TPV_Plugin::my_tickets_url()));
            if ($switched && function_exists('restore_previous_locale')) { restore_previous_locale(); }
        }
    }

    private static function redirect_notice($message, $error, $ticket_id) {
        $ticket_id = $ticket_id ? $ticket_id : 0;
        $url = wp_get_referer() ? wp_get_referer() : home_url('/');
        if ($ticket_id) { $url = add_query_arg('tpv_ticket', $ticket_id, TPV_Plugin::my_tickets_url()); }
        set_transient('tpv_notice_' . get_current_user_id(), array('message' => $message, 'error' => (bool) $error), MINUTE_IN_SECONDS);
        wp_safe_redirect($url); exit;
    }

    private static function notice() { $notice = get_transient('tpv_notice_' . get_current_user_id()); if ($notice) { delete_transient('tpv_notice_' . get_current_user_id()); echo '<p class="' . ($notice['error'] ? 'tpv-error' : 'tpv-success') . '">' . esc_html($notice['message']) . '</p>'; } }
}
