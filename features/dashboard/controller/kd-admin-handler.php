<?php
/**
 * Admin dashboard feature controller.
 * Handles admin menu creation, CSV exports, subscriber deletions, settings, and multi-channel campaigns.
 * Integrates manual (free) sending routes for SMS/WhatsApp and clipboard exporting utilities.
 */

if (!defined('ABSPATH')) {
    exit;
}

class kdwn_Admin_Handler {
    /**
     * Bind admin hooks and AJAX controllers.
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'kdwn_add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'kdwn_enqueue_admin_assets'));
        add_action('admin_init', array($this, 'kdwn_handle_csv_export'));

        // AJAX handlers for admin actions
        add_action('wp_ajax_kdwn_delete_subscriber', array($this, 'kdwn_ajax_delete_subscriber'));
        add_action('wp_ajax_kdwn_delete_all_subscribers', array($this, 'kdwn_ajax_delete_all_subscribers'));
        add_action('wp_ajax_kdwn_get_campaign_stats', array($this, 'kdwn_ajax_get_campaign_stats'));
        add_action('wp_ajax_kdwn_send_batch_emails', array($this, 'kdwn_ajax_send_batch_emails'));
        add_action('wp_ajax_kdwn_reset_subscribers_status', array($this, 'kdwn_ajax_reset_subscribers_status'));
        
        // New AJAX handlers for manual messaging
        add_action('wp_ajax_kdwn_get_next_pending_subscriber', array($this, 'kdwn_ajax_get_next_pending_subscriber'));
        add_action('wp_ajax_kdwn_get_active_contacts', array($this, 'kdwn_ajax_get_active_contacts'));
        add_action('wp_ajax_kdwn_mark_subscriber_notified', array($this, 'kdwn_ajax_mark_subscriber_notified'));
        add_action('wp_ajax_kdwn_add_custom_country', array($this, 'kdwn_ajax_add_custom_country'));
        add_action('wp_ajax_kdwn_add_custom_service', array($this, 'kdwn_ajax_add_custom_service'));
        add_action('wp_ajax_kdwn_delete_custom_service', array($this, 'kdwn_ajax_delete_custom_service'));
        add_action('wp_ajax_kdwn_save_settings', array($this, 'kdwn_ajax_save_settings'));
    }

    /**
     * Register the Waitlist & Notify Dashboard page in the WordPress admin menu.
     */
    public function kdwn_add_admin_menu() {
        add_menu_page(
            __('Waitlist & Notify', 'khvichadev-waitlist-notify'),
            __('Waitlist & Notify', 'khvichadev-waitlist-notify'),
            'manage_options',
            'khvichadev-waitlist-notify',
            array('kdwn_Admin_Page', 'kdwn_render_dashboard'),
            'dashicons-email-alt',
            30
        );
    }

    /**
     * Enqueue CSS and JS assets for the admin dashboard.
     */
    public function kdwn_enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_khvichadev-waitlist-notify') {
            return;
        }

        // Explicitly enqueue WordPress core dashicons style
        wp_enqueue_style('dashicons');

        wp_enqueue_style(
            'kdwn-admin-outfit-font',
            'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
            array(),
            KDWN_VERSION
        );

        wp_enqueue_style(
            'kdwn-admin-styles',
            KDWN_URL . 'features/dashboard/ui/kd-admin.css',
            array('kdwn-admin-outfit-font'),
            KDWN_VERSION
        );

        wp_enqueue_script(
            'kdwn-notifications-kd',
            KDWN_URL . 'features/dashboard/ui/notifications-kd.js',
            array(),
            KDWN_VERSION,
            true
        );

        wp_enqueue_script(
            'kdwn-admin-script',
            KDWN_URL . 'features/dashboard/ui/kd-admin.js',
            array('jquery', 'kdwn-notifications-kd'),
            KDWN_VERSION,
            true
        );

        wp_localize_script('kdwn-admin-script', 'kdwn_admin_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('kdwn_admin_nonce')
        ));
    }

    /**
     * Handle CSV downloads of the subscriber list.
     */
    public function kdwn_handle_csv_export() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'khvichadev-waitlist-notify') {
            return;
        }

        if (!isset($_GET['action']) || $_GET['action'] !== 'kdwn_export_csv') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html(__('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'kdwn_export_csv_nonce')) {
            wp_die(esc_html(__('Security check failed. Please reload the dashboard.', 'khvichadev-waitlist-notify')));
        }

        $service_id = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 1;

        // Clear output buffer to prevent corrupted characters or layout wrappers in CSV
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=waitlist_subscribers_' . gmdate('Y-m-d') . '.csv');

        $csv_data = "\xEF\xBB\xBF"; // UTF-8 BOM

        // Add headers
        $headers = array('ID', 'Name', 'Email', 'Phone', 'WhatsApp', 'Status', 'Registered Date');
        $csv_data .= implode(',', array_map(array($this, 'kdwn_escape_csv_field'), $headers)) . "\n";

        global $wpdb;
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, email, phone, whatsapp, status, created_at FROM {$wpdb->prefix}kdwn_subscribers WHERE service_id = %d ORDER BY created_at DESC",
                $service_id
            ), 
            ARRAY_A
        );

        if ($results) {
            foreach ($results as $row) {
                // Prevent CSV/Formula Injection by escaping values starting with =, +, -, @
                $escaped_row = array_map(function($value) {
                    if (is_string($value) && in_array(substr($value, 0, 1), array('=', '+', '-', '@'), true)) {
                        $value = "'" . $value;
                    }
                    return $this->kdwn_escape_csv_field($value);
                }, $row);
                $csv_data .= implode(',', $escaped_row) . "\n";
            }
        }

        echo $csv_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Escapes a single field for CSV output.
     */
    private function kdwn_escape_csv_field($field) {
        $field = str_replace('"', '""', $field);
        if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false || strpos($field, "\r") !== false) {
            $field = '"' . $field . '"';
        }
        return $field;
    }

    /**
     * AJAX action to delete a single subscriber.
     */
    public function kdwn_ajax_delete_subscriber() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id) {
            wp_send_json_error(array('message' => __('Invalid subscriber ID.', 'khvichadev-waitlist-notify')));
        }

        $deleted = kdwn_Database::kdwn_delete_subscriber($id);

        if ($deleted) {
            wp_send_json_success(array('message' => __('Subscriber successfully removed.', 'khvichadev-waitlist-notify')));
        } else {
            wp_send_json_error(array('message' => __('Could not remove subscriber.', 'khvichadev-waitlist-notify')));
        }
    }

    /**
     * AJAX action to delete all subscribers for the active service.
     */
    public function kdwn_ajax_delete_all_subscribers() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
        if (!$service_id) {
            wp_send_json_error(array('message' => __('Invalid service ID.', 'khvichadev-waitlist-notify')));
        }

        $deleted = kdwn_Database::kdwn_delete_all_subscribers($service_id);

        if ($deleted !== false) {
            wp_send_json_success(array('message' => __('All subscribers successfully deleted.', 'khvichadev-waitlist-notify')));
        } else {
            wp_send_json_error(array('message' => __('Could not delete subscribers.', 'khvichadev-waitlist-notify')));
        }
    }

    /**
     * AJAX action to retrieve statistics for a send campaign.
     */
    public function kdwn_ajax_get_campaign_stats() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $channel = isset($_POST['channel']) ? sanitize_text_field(wp_unslash($_POST['channel'])) : 'email';
        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 1;

        global $wpdb;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}kdwn_subscribers 
                 WHERE status = %s 
                   AND service_id = %d 
                   AND (
                       (%s = 'email' AND email != '') OR 
                       (%s IN ('sms', 'manual_sms', 'custom_sms') AND phone != '') OR 
                       (%s IN ('whatsapp', 'manual_whatsapp', 'manual_whatsapp_app', 'custom_whatsapp') AND whatsapp != '') OR
                       (%s NOT IN ('email', 'sms', 'manual_sms', 'custom_sms', 'whatsapp', 'manual_whatsapp', 'manual_whatsapp_app', 'custom_whatsapp'))
                   )",
                'subscribed',
                (int) $service_id,
                $channel,
                $channel,
                $channel,
                $channel
            )
        );

        wp_send_json_success(array('total_to_notify' => $count));
    }

    /**
     * AJAX action to send campaign notifications in chunks.
     * Supports Email, Twilio SMS, and Twilio WhatsApp.
     */
    public function kdwn_ajax_send_batch_emails() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $channel = isset($_POST['channel']) ? sanitize_text_field(wp_unslash($_POST['channel'])) : 'email';
        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $message_body = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';
        $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 15;
        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 1;

        if (empty($message_body) || ($channel === 'email' && empty($subject))) {
            wp_send_json_error(array('message' => __('Subject/Message cannot be empty.', 'khvichadev-waitlist-notify')));
        }

        global $wpdb;

        $subscribers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, email, phone, whatsapp FROM {$wpdb->prefix}kdwn_subscribers 
                 WHERE status = %s 
                   AND service_id = %d 
                   AND (
                       (%s = 'email' AND email != '') OR 
                       (%s IN ('sms', 'custom_sms') AND phone != '') OR 
                       (%s IN ('whatsapp', 'custom_whatsapp') AND whatsapp != '') OR
                       (%s NOT IN ('email', 'sms', 'custom_sms', 'whatsapp', 'custom_whatsapp'))
                   )
                 ORDER BY id ASC LIMIT %d",
                'subscribed',
                (int) $service_id,
                $channel,
                $channel,
                $channel,
                $channel,
                (int) $batch_size
            ),
            ARRAY_A
        );

        if (empty($subscribers)) {
            wp_send_json_success(array(
                'processed' => 0,
                'sent'      => 0,
                'finished'  => true
            ));
        }

        $sent_targets = array();
        $failed_targets = array();
        $success_count = 0;
        $notified_ids = array();

        if ($channel === 'email') {
            add_filter('wp_mail_content_type', array($this, 'kdwn_set_html_mail_type'));
        }

        foreach ($subscribers as $sub) {
            // Replace personalization tags
            $personal_message = str_replace(
                array('{name}', '{email}'),
                array($sub['name'], $sub['email']),
                $message_body
            );

            $send_success = false;
            $error_message = '';

            if ($channel === 'email') {
                $personal_subject = str_replace(
                    array('{name}', '{email}'),
                    array($sub['name'], $sub['email']),
                    $subject
                );

                $html_message = $this->kdwn_get_email_template($personal_message, $sub['name']);
                $send_success = wp_mail($sub['email'], $personal_subject, $html_message);
                $target_label = $sub['email'];
                if (!$send_success) {
                    $error_message = __('wp_mail failed to send.', 'khvichadev-waitlist-notify');
                }

            } elseif ($channel === 'sms') {
                $response = $this->kdwn_send_twilio_message($sub['phone'], $personal_message, false, $service_id);
                $send_success = $response['success'];
                $target_label = $sub['phone'];
                if (!$send_success) {
                    $error_message = isset($response['error']) ? $response['error'] : __('Twilio SMS error.', 'khvichadev-waitlist-notify');
                }

            } elseif ($channel === 'whatsapp') {
                $response = $this->kdwn_send_twilio_message($sub['whatsapp'], $personal_message, true, $service_id);
                $send_success = $response['success'];
                $target_label = $sub['whatsapp'];
                if (!$send_success) {
                    $error_message = isset($response['error']) ? $response['error'] : __('Twilio WhatsApp error.', 'khvichadev-waitlist-notify');
                }
            } elseif ($channel === 'custom_sms') {
                $response = $this->kdwn_send_custom_http_message($sub['phone'], $personal_message, 'sms', $service_id);
                $send_success = $response['success'];
                $target_label = $sub['phone'];
                if (!$send_success) {
                    $error_message = isset($response['error']) ? $response['error'] : __('Custom HTTP Gateway error.', 'khvichadev-waitlist-notify');
                }
            } elseif ($channel === 'custom_whatsapp') {
                $response = $this->kdwn_send_custom_http_message($sub['whatsapp'], $personal_message, 'whatsapp', $service_id);
                $send_success = $response['success'];
                $target_label = $sub['whatsapp'];
                if (!$send_success) {
                    $error_message = isset($response['error']) ? $response['error'] : __('Custom HTTP Gateway error.', 'khvichadev-waitlist-notify');
                }
            }

            if ($send_success) {
                kdwn_Database::kdwn_update_subscriber_status($sub['id'], 'notified');
                $sent_targets[] = $target_label;
                $success_count++;
                $notified_ids[$sub['id']] = 'notified';
            } else {
                kdwn_Database::kdwn_update_subscriber_status($sub['id'], 'failed');
                $failed_targets[] = array(
                    'target' => $target_label,
                    'error'  => $error_message
                );
                $notified_ids[$sub['id']] = 'failed';
            }
        }

        if ($channel === 'email') {
            remove_filter('wp_mail_content_type', array($this, 'kdwn_set_html_mail_type'));
        }

        wp_send_json_success(array(
            'processed' => count($subscribers),
            'sent'      => $success_count,
            'emails'    => $sent_targets,
            'failed_emails' => $failed_targets,
            'notified_ids' => $notified_ids,
            'finished'  => count($subscribers) < $batch_size
        ));
    }

    /**
     * Sends an SMS or WhatsApp notification via the Twilio API or simulation logs.
     */
    private function kdwn_send_twilio_message($to, $body, $is_whatsapp = false, $service_id = 1) {
        $config = kdwn_Database::kdwn_get_gateway_config($service_id);
        $sid = $config['twilio_sid'];
        $token = $config['twilio_token'];
        $from = $is_whatsapp ? $config['twilio_wa_from'] : $config['twilio_sms_from'];

        $body_plain = wp_strip_all_tags(html_entity_decode($body));

        if (empty($sid) || empty($token) || empty($from)) {
            return array('success' => false, 'error' => __('Twilio Credentials Not Found.', 'khvichadev-waitlist-notify'));
        }

        $formatted_to = $is_whatsapp ? 'whatsapp:' . $to : $to;
        $formatted_from = $is_whatsapp ? 'whatsapp:' . $from : $from;

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode("{$sid}:{$token}")
            ),
            'body' => array(
                'To'   => $formatted_to,
                'From' => $formatted_from,
                'Body' => $body_plain
            )
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $res_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300) {
            return array('success' => true);
        } else {
            $error_message = isset($res_body['message']) ? $res_body['message'] : 'Twilio API error code: ' . $code;
            return array('success' => false, 'error' => $error_message);
        }
    }

    /**
     * AJAX action to fetch the next pending subscriber in the manual send queue.
     */
    public function kdwn_ajax_get_next_pending_subscriber() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $channel = isset($_POST['channel']) ? sanitize_text_field(wp_unslash($_POST['channel'])) : 'manual_sms';
        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 1;
        $exclude_ids = isset($_POST['exclude_ids']) ? array_map('intval', (array) $_POST['exclude_ids']) : array();

        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, email, phone, whatsapp FROM {$wpdb->prefix}kdwn_subscribers 
                 WHERE status = %s 
                   AND service_id = %d 
                   AND (
                       (%s = 'manual_sms' AND phone != '') OR 
                       (%s IN ('manual_whatsapp', 'manual_whatsapp_app') AND whatsapp != '') OR
                       (%s NOT IN ('manual_sms', 'manual_whatsapp', 'manual_whatsapp_app'))
                   )
                 ORDER BY id ASC LIMIT 100",
                'subscribed',
                (int) $service_id,
                $channel,
                $channel,
                $channel
            ),
            ARRAY_A
        );

        $sub = null;
        if (!empty($results)) {
            foreach ($results as $row) {
                if (!in_array((int) $row['id'], $exclude_ids, true)) {
                    $sub = $row;
                    break;
                }
            }
        }

        if (empty($sub)) {
            wp_send_json_success(array('finished' => true));
        } else {
            wp_send_json_success(array(
                'finished' => false,
                'id'       => $sub['id'],
                'name'     => $sub['name'],
                'email'    => $sub['email'],
                'phone'    => $sub['phone'],
                'whatsapp' => $sub['whatsapp']
            ));
        }
    }

    /**
     * AJAX action to get a comma-separated list of active contacts for clipboard copying.
     */
    public function kdwn_ajax_get_active_contacts() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $channel = isset($_POST['channel']) ? sanitize_text_field(wp_unslash($_POST['channel'])) : 'phone';
        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 1;

        global $wpdb;

        $db_results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT phone, whatsapp FROM {$wpdb->prefix}kdwn_subscribers WHERE status = %s AND service_id = %d ORDER BY id ASC",
                'subscribed',
                (int) $service_id
            ),
            ARRAY_A
        );

        $results = array();
        if (!empty($db_results)) {
            foreach ($db_results as $row) {
                $val = $channel === 'whatsapp' ? $row['whatsapp'] : $row['phone'];
                if (!empty($val)) {
                    $results[] = $val;
                }
            }
        }

        if (empty($results)) {
            wp_send_json_error(array('message' => __('No active numbers found to copy.', 'khvichadev-waitlist-notify')));
        }

        wp_send_json_success(array(
            'numbers' => implode(", ", $results),
            'count'   => count($results)
        ));
    }

    /**
     * AJAX action to reset all subscribers status back to 'subscribed'.
     */
    public function kdwn_ajax_reset_subscribers_status() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 1;

        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}kdwn_subscribers SET status = %s WHERE status IN (%s, %s) AND service_id = %d",
                'subscribed',
                'notified',
                'failed',
                $service_id
            )
        );

        wp_send_json_success(array(
            'message' => 'All subscriber statuses have been reset to Subscribed.',
            'count'   => $updated
        ));
    }

    /**
     * AJAX action to mark a subscriber as notified.
     */
    public function kdwn_ajax_mark_subscriber_notified() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id) {
            wp_send_json_error(array('message' => __('Invalid subscriber ID.', 'khvichadev-waitlist-notify')));
        }

        $updated = kdwn_Database::kdwn_update_subscriber_status($id, 'notified');

        if ($updated) {
            wp_send_json_success(array('message' => __('Subscriber status updated to notified.', 'khvichadev-waitlist-notify')));
        } else {
            wp_send_json_error(array('message' => __('Could not update subscriber status.', 'khvichadev-waitlist-notify')));
        }
    }

    /**
     * Filter callback to enforce HTML mail content type.
     */
    public function kdwn_set_html_mail_type() {
        return 'text/html';
    }

    /**
     * Wraps raw email content in a premium HTML frame with inline CSS styling.
     */
    private function kdwn_get_email_template($content, $name) {
        $formatted_content = nl2br($content);
        $site_name = get_bloginfo('name');
        $site_url  = home_url();

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Notification</title>
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #334155; -webkit-font-smoothing: antialiased;">
            <div style="width: 100%; table-layout: fixed; background-color: #f8fafc; padding: 40px 0;">
                <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);">
                    <div style="background: linear-gradient(135deg, #6366f1, #a855f7); padding: 40px 30px; text-align: center;">
                        <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: -0.02em;">' . esc_html($site_name) . '</h1>
                    </div>
                    <div style="padding: 40px 30px; line-height: 1.6; font-size: 16px; color: #334155;">
                        ' . $formatted_content . '
                    </div>
                    <div style="background-color: #f1f5f9; padding: 25px 30px; text-align: center; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0;">
                        <p style="margin: 0;">You received this email because you pre-registered at <a href="' . esc_url($site_url) . '" style="color: #6366f1; text-decoration: none; font-weight: 500;">' . esc_html($site_name) . '</a>.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ';
    }

    /**
     * Dispatch notification message via custom HTTP gateway template.
     * Replaces phone and message template placeholders, and routes via simulated log or live GET request.
     */
    private function kdwn_send_custom_http_message($to, $message, $type, $service_id = 1) {
        $config = kdwn_Database::kdwn_get_gateway_config($service_id);
        $gateway_url = ($type === 'whatsapp') ? $config['custom_wa_url'] : $config['custom_sms_url'];

        if (empty($gateway_url)) {
            return array('success' => false, 'error' => __('Gateway URL is empty.', 'khvichadev-waitlist-notify'));
        }

        /** Extract numeric digits and plus sign from contact number to ensure E.164 compatibility */
        $clean_number = preg_replace('/[^\d+]/', '', $to);

        /** Interpolate URL templates by encoding variables and substituting tags */
        $target_url = str_replace(
            array('{phone}', '{message}'),
            array(urlencode($clean_number), urlencode($message)),
            $gateway_url
        );

        /** Execute the HTTP GET request to dispatch message payload to phone or gateway server */
        $response = wp_remote_get($target_url, array('timeout' => 10));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return array('success' => true);
        } else {
            /* translators: %d: HTTP status code */
            return array('success' => false, 'error' => sprintf(__('Gateway HTTP Status %d.', 'khvichadev-waitlist-notify'), $code));
        }
    }

    /**
     * AJAX action to register a custom country dialing code and set it as the default choice.
     */
    public function kdwn_ajax_add_custom_country() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $country_name = isset($_POST['country_name']) ? sanitize_text_field(wp_unslash($_POST['country_name'])) : '';
        $country_code = isset($_POST['country_code']) ? sanitize_text_field(wp_unslash($_POST['country_code'])) : '';

        if (empty($country_name)) {
            wp_send_json_error(array('message' => __('Country name or initials cannot be empty.', 'khvichadev-waitlist-notify')));
        }
        if (empty($country_code)) {
            wp_send_json_error(array('message' => __('Dialing prefix code cannot be empty.', 'khvichadev-waitlist-notify')));
        }

        $country_name = trim($country_name);
        $country_code = trim($country_code);

        if ($country_code[0] !== '+') {
            $country_code = '+' . $country_code;
        }

        if (!preg_match('/^\+\d{1,7}$/', $country_code)) {
            wp_send_json_error(array('message' => __('Dialing prefix must be a plus sign (+) followed by digits (e.g. +995).', 'khvichadev-waitlist-notify')));
        }

        $custom_countries = get_option('kdwn_custom_countries', array());
        if (!is_array($custom_countries)) {
            $custom_countries = array();
        }

        $key = strtoupper($country_name);
        $custom_countries[$key] = array(
            'name' => $country_name,
            'code' => $country_code
        );

        update_option('kdwn_custom_countries', $custom_countries);

        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 1;
        $fields_config = kdwn_Database::kdwn_get_fields_config($service_id);
        if (!is_array($fields_config)) {
            $fields_config = array();
        }
        $fields_config['default_country_code'] = $country_code;
        $option_suffix = ($service_id > 1) ? '_' . $service_id : '';
        update_option('kdwn_fields_config' . $option_suffix, $fields_config);

        wp_send_json_success(array(
            'message' => __('Custom country dialing code added and set as default.', 'khvichadev-waitlist-notify'),
            'key'     => esc_attr($key),
            'name'    => esc_html($country_name),
            'code'    => esc_attr($country_code)
        ));
    }

    /**
     * AJAX action to register a custom service.
     */
    public function kdwn_ajax_add_custom_service() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $service_name = isset($_POST['service_name']) ? sanitize_text_field(wp_unslash($_POST['service_name'])) : '';
        $service_desc = isset($_POST['service_desc']) ? sanitize_text_field(wp_unslash($_POST['service_desc'])) : '';

        if (empty($service_name)) {
            wp_send_json_error(array('message' => __('Service name cannot be empty.', 'khvichadev-waitlist-notify')));
        }

        // Prevent duplicate service names (case-insensitively)
        $services = kdwn_Database::kdwn_get_services();
        $lowercase_name = strtolower(trim($service_name));
        foreach ($services as $srv) {
            if (strtolower(trim($srv['name'])) === $lowercase_name) {
                wp_send_json_error(array('message' => __('A service with this name already exists.', 'khvichadev-waitlist-notify')));
            }
        }

        $new_service = kdwn_Database::kdwn_add_service($service_name, $service_desc);

        if ($new_service) {
            wp_send_json_success(array(
                'message' => __('Service successfully created.', 'khvichadev-waitlist-notify'),
                'id'      => $new_service['id'],
                'name'    => $new_service['name']
            ));
        } else {
            wp_send_json_error(array('message' => __('Could not create new service.', 'khvichadev-waitlist-notify')));
        }
    }

    /**
     * AJAX action to delete a custom service.
     */
    public function kdwn_ajax_delete_custom_service() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;

        if ($service_id <= 1) {
            wp_send_json_error(array('message' => __('Cannot delete default service.', 'khvichadev-waitlist-notify')));
        }

        $deleted = kdwn_Database::kdwn_delete_service($service_id);

        if ($deleted) {
            wp_send_json_success(array('message' => __('Service and all subscribers successfully deleted.', 'khvichadev-waitlist-notify')));
        } else {
            wp_send_json_error(array('message' => __('Could not delete service.', 'khvichadev-waitlist-notify')));
        }
    }

    /**
     * AJAX action to save waitlist configurations.
     */
    public function kdwn_ajax_save_settings() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_admin_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'khvichadev-waitlist-notify')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'khvichadev-waitlist-notify')));
        }

        $active_service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 1;

        // Save global option for delete data on uninstall
        update_option('kdwn_delete_data_on_uninstall', isset($_POST['delete_data_on_uninstall']) ? 1 : 0);

        // Save form fields visibility and requirements configurations
        $fields_config = array(
            'email' => array(
                'enabled' => isset($_POST['field_email_enabled']),
                'required' => isset($_POST['field_email_required'])
            ),
            'phone' => array(
                'enabled' => isset($_POST['field_phone_enabled']),
                'required' => isset($_POST['field_phone_required'])
            ),
            'whatsapp' => array(
                'enabled' => isset($_POST['field_whatsapp_enabled']),
                'required' => isset($_POST['field_whatsapp_required'])
            ),
            'default_country_code'  => isset($_POST['default_country_code']) ? sanitize_text_field(wp_unslash($_POST['default_country_code'])) : '',
            'show_subscriber_count' => isset($_POST['show_subscriber_count']),
            'consent_enabled'       => isset($_POST['consent_enabled'])
        );
        $option_suffix = ($active_service_id > 1) ? '_' . $active_service_id : '';
        update_option('kdwn_fields_config' . $option_suffix, $fields_config);

        // Handle masked password input for twilio_token
        $existing_gateway_config = kdwn_Database::kdwn_get_gateway_config($active_service_id);
        $existing_token = isset($existing_gateway_config['twilio_token']) ? $existing_gateway_config['twilio_token'] : '';
        $submitted_token = isset($_POST['twilio_token']) ? sanitize_text_field(wp_unslash($_POST['twilio_token'])) : '';
        if (empty($submitted_token) || strpos($submitted_token, '•') !== false) {
            $twilio_token = $existing_token;
        } else {
            $twilio_token = $submitted_token;
        }

        // Save gateway configurations for Twilio and Custom HTTP Gateways
        $gateway_config = array(
            'twilio_sid'      => isset($_POST['twilio_sid']) ? sanitize_text_field(wp_unslash($_POST['twilio_sid'])) : '',
            'twilio_token'    => $twilio_token,
            'twilio_sms_from' => isset($_POST['twilio_sms_from']) ? sanitize_text_field(wp_unslash($_POST['twilio_sms_from'])) : '',
            'twilio_wa_from'  => isset($_POST['twilio_wa_from']) ? sanitize_text_field(wp_unslash($_POST['twilio_wa_from'])) : '',
            'custom_sms_url'  => isset($_POST['custom_sms_url']) ? sanitize_text_field(wp_unslash($_POST['custom_sms_url'])) : '',
            'custom_wa_url'   => isset($_POST['custom_wa_url']) ? sanitize_text_field(wp_unslash($_POST['custom_wa_url'])) : ''
        );
        update_option('kdwn_gateway_config' . $option_suffix, $gateway_config);

        // Save customizable frontend form texts
        $form_texts_saved = array(
            'form_title'        => isset($_POST['form_title']) ? sanitize_text_field(wp_unslash($_POST['form_title'])) : '',
            'form_subtitle'     => isset($_POST['form_subtitle']) ? sanitize_text_field(wp_unslash($_POST['form_subtitle'])) : '',
            'name_label'        => isset($_POST['name_label']) ? sanitize_text_field(wp_unslash($_POST['name_label'])) : '',
            'email_label'       => isset($_POST['email_label']) ? sanitize_text_field(wp_unslash($_POST['email_label'])) : '',
            'phone_label'       => isset($_POST['phone_label']) ? sanitize_text_field(wp_unslash($_POST['phone_label'])) : '',
            'whatsapp_label'    => isset($_POST['whatsapp_label']) ? sanitize_text_field(wp_unslash($_POST['whatsapp_label'])) : '',
            'submit_btn'        => isset($_POST['submit_btn']) ? sanitize_text_field(wp_unslash($_POST['submit_btn'])) : '',
            'success_title'     => isset($_POST['success_title']) ? sanitize_text_field(wp_unslash($_POST['success_title'])) : '',
            'success_msg'       => isset($_POST['success_msg']) ? sanitize_text_field(wp_unslash($_POST['success_msg'])) : '',
            'social_proof_text' => isset($_POST['social_proof_text']) ? sanitize_text_field(wp_unslash($_POST['social_proof_text'])) : '',
            'badge_label'       => isset($_POST['badge_label']) ? sanitize_text_field(wp_unslash($_POST['badge_label'])) : '',
            'consent_label'     => isset($_POST['consent_label']) ? sanitize_text_field(wp_unslash($_POST['consent_label'])) : ''
        );
        update_option('kdwn_form_texts' . $option_suffix, $form_texts_saved);

        wp_send_json_success(array('message' => __('Settings saved successfully.', 'khvichadev-waitlist-notify')));
    }
}
