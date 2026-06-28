<?php
/**
 * Admin dashboard renderer class.
 * Outputs the HTML markup for stats, subscribers lists (with filters),
 * email/SMS/WhatsApp campaign runners (with manual and auto views),
 * and the field/gateway settings forms.
 */

if (!defined('ABSPATH')) {
    exit;
}

class kdwn_Admin_Page {
    /**
     * Renders the administrative dashboard interface.
     */
    public static function kdwn_render_dashboard() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table_name = kdwn_Database::kdwn_get_table_name();

        $services = kdwn_Database::kdwn_get_services();

        $active_service_id = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 1;
        if (!isset($services[$active_service_id])) {
            $active_service_id = !empty($services) ? min(array_keys($services)) : 1;
        }

        // Handle saving settings form
        $settings_saved = false;
        if (isset($_POST['kdwn_save_settings_nonce'])) {
            $nonce = sanitize_key(wp_unslash($_POST['kdwn_save_settings_nonce']));
            if (wp_verify_nonce($nonce, 'kdwn_save_settings_action')) {
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

                // Save gateway configurations for Twilio and Custom HTTP Gateways
                $existing_gateway_config = kdwn_Database::kdwn_get_gateway_config($active_service_id);
                $existing_token = isset($existing_gateway_config['twilio_token']) ? $existing_gateway_config['twilio_token'] : '';
                $submitted_token = isset($_POST['twilio_token']) ? sanitize_text_field(wp_unslash($_POST['twilio_token'])) : '';
                if (empty($submitted_token) || strpos($submitted_token, '•') !== false) {
                    $twilio_token = $existing_token;
                } else {
                    $twilio_token = $submitted_token;
                }

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

                $settings_saved = true;
            }
        }

        // Fetch configurations
        $fields_config  = kdwn_Database::kdwn_get_fields_config($active_service_id);
        $gateway_config = kdwn_Database::kdwn_get_gateway_config($active_service_id);
        $form_texts     = kdwn_Database::kdwn_get_form_texts($active_service_id);

        // Filter and Search parameters
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $channel_filter = isset($_GET['channel']) ? sanitize_text_field(wp_unslash($_GET['channel'])) : '';

        // Pagination setup
        $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 20;
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset = ($paged - 1) * $limit;

        // Query subscribers list with search, channel and service filters
        $subscribers = kdwn_Database::kdwn_get_subscribers($limit, $offset, $search, $channel_filter, $active_service_id);
        $total_subscribers = kdwn_Database::kdwn_get_subscribers_count($search, $channel_filter, $active_service_id);
        $total_pages = ceil($total_subscribers / $limit);

        // Fetch dashboard general stats filtered by service_id
        $count_all = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}kdwn_subscribers WHERE service_id = %d", $active_service_id));
        $count_subscribed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}kdwn_subscribers WHERE status = %s AND service_id = %d", 'subscribed', $active_service_id));
        $count_notified = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}kdwn_subscribers WHERE status = %s AND service_id = %d", 'notified', $active_service_id));
        $count_failed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}kdwn_subscribers WHERE status = %s AND service_id = %d", 'failed', $active_service_id));

        $csv_nonce = wp_create_nonce('kdwn_export_csv_nonce');
        ?>
        <div class="kd-admin-wrap" data-service-id="<?php echo (int) $active_service_id; ?>">
            <?php if ($settings_saved) : ?>
                <div class="kd-admin-notice kd-notice-success">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <span class="kd-notice-text"><?php esc_html_e('Settings updated successfully.', 'khvichadev-waitlist-notify'); ?></span>
                    <button type="button" class="kd-notice-close-btn" onclick="this.parentElement.style.display='none';">
                        <span class="dashicons dashicons-dismiss"></span>
                    </button>
                </div>
            <?php endif; ?>
            <div class="kd-admin-header-row">
                <div class="kd-admin-title-area">
                    <h1 class="kd-admin-main-title"><?php esc_html_e('Waitlist & Notify Dashboard', 'khvichadev-waitlist-notify'); ?></h1>
                    <p class="kd-admin-tagline"><?php esc_html_e('Manage fields settings, database list, and multi-channel campaigns.', 'khvichadev-waitlist-notify'); ?></p>
                </div>
                
                <div class="kd-header-actions" style="display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap;">
                    <!-- Add Service Button -->
                    <button type="button" id="kd-add-service-trigger-btn" class="kd-admin-btn kd-btn-outline" style="height: 50px !important; padding: 0 15px !important;" title="<?php esc_attr_e('Add New Service', 'khvichadev-waitlist-notify'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width: 18px; height: 18px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg> <?php esc_html_e('Add Service', 'khvichadev-waitlist-notify'); ?>
                    </button>

                    <!-- Service Switcher Selector -->
                    <?php
                    $active_service_desc = isset($services[$active_service_id]['description']) ? $services[$active_service_id]['description'] : '';
                    ?>
                    <select id="kd-service-selector" class="kd-admin-select" style="width: 220px !important; height: 50px !important; margin: 0 !important;" title="<?php echo esc_attr($active_service_desc); ?>">
                        <?php foreach ($services as $s_id => $s_data) : ?>
                            <option value="<?php echo (int) $s_id; ?>" <?php selected($s_id, $active_service_id); ?> title="<?php echo esc_attr($s_data['description']); ?>">
                                <?php echo esc_html($s_data['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Delete Service Button (Only for custom services) -->
                    <?php if ($active_service_id > 1) : ?>
                        <button type="button" id="kd-delete-service-btn" class="kd-admin-btn" style="height: 50px !important; padding: 0 15px !important; background: rgba(239, 68, 68, 0.15) !important; border: 1px solid rgba(239, 68, 68, 0.3) !important; color: #f87171 !important;" title="<?php esc_attr_e('Delete Active Service', 'khvichadev-waitlist-notify'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 18px; height: 18px; color: #f87171 !important;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg> <?php esc_html_e('Delete Service', 'khvichadev-waitlist-notify'); ?>
                        </button>
                    <?php endif; ?>

                    <!-- Export CSV Button -->
                    <a href="<?php echo esc_url(admin_url('admin.php?page=khvichadev-waitlist-notify&action=kdwn_export_csv&service_id=' . $active_service_id . '&_wpnonce=' . $csv_nonce)); ?>" class="kd-admin-btn kd-btn-outline" style="height: 50px !important;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width: 18px; height: 18px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg> <?php esc_html_e('Export CSV', 'khvichadev-waitlist-notify'); ?>
                    </a>
                </div>
            </div>


            <!-- Dashboard Stats Grid -->
            <div class="kd-stats-grid">
                <div class="kd-stat-card kd-stat-total">
                    <div class="kd-stat-icon">
                        <span class="dashicons dashicons-groups"></span>
                    </div>
                    <div class="kd-stat-info">
                        <span class="kd-stat-number"><?php echo esc_html($count_all); ?></span>
                        <span class="kd-stat-label"><?php esc_html_e('Total Subscribers', 'khvichadev-waitlist-notify'); ?></span>
                    </div>
                </div>

                <div class="kd-stat-card kd-stat-pending">
                    <div class="kd-stat-icon">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div class="kd-stat-info">
                        <span class="kd-stat-number" id="kd-count-subscribed"><?php echo esc_html($count_subscribed); ?></span>
                        <span class="kd-stat-label"><?php esc_html_e('Subscribed (Unnotified)', 'khvichadev-waitlist-notify'); ?></span>
                    </div>
                </div>

                <div class="kd-stat-card kd-stat-completed">
                    <div class="kd-stat-icon">
                        <span class="dashicons dashicons-email-alt"></span>
                    </div>
                    <div class="kd-stat-info">
                        <span class="kd-stat-number" id="kd-count-notified"><?php echo esc_html($count_notified); ?></span>
                        <span class="kd-stat-label"><?php esc_html_e('Notified', 'khvichadev-waitlist-notify'); ?></span>
                    </div>
                </div>

                <div class="kd-stat-card kd-stat-failed">
                    <div class="kd-stat-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="kd-stat-info">
                        <span class="kd-stat-number" id="kd-count-failed"><?php echo esc_html($count_failed); ?></span>
                        <span class="kd-stat-label"><?php esc_html_e('Delivery Failed', 'khvichadev-waitlist-notify'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <h2 class="nav-tab-wrapper kd-nav-tab-wrapper">
                <a href="#subscribers-tab" class="nav-tab nav-tab-active" id="kd-tab-trigger-list"><?php esc_html_e('Subscribers List', 'khvichadev-waitlist-notify'); ?></a>
                <a href="#campaign-tab" class="nav-tab" id="kd-tab-trigger-campaign"><?php esc_html_e('Notification Campaign', 'khvichadev-waitlist-notify'); ?></a>
                <a href="#settings-tab" class="nav-tab" id="kd-tab-trigger-settings"><?php esc_html_e('Form & Gateway Settings', 'khvichadev-waitlist-notify'); ?></a>
            </h2>

            <div class="kd-tab-container">
                <!-- Tab 1: Subscribers list -->
                <div id="subscribers-tab" class="kd-tab-content kd-tab-content-active">
                    <div class="kd-list-toolbar">
                        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="kd-search-form">
                            <input type="hidden" name="page" value="khvichadev-waitlist-notify" />
                            <input type="hidden" name="service_id" value="<?php echo (int) $active_service_id; ?>" />
                            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search database...', 'khvichadev-waitlist-notify'); ?>" class="kd-search-input" />
                            
                            <select name="channel" class="kd-search-input" style="min-width: 150px;" onchange="this.form.submit();">
                                <option value=""><?php esc_html_e('All Channels', 'khvichadev-waitlist-notify'); ?></option>
                                <option value="email" <?php selected($channel_filter, 'email'); ?>><?php esc_html_e('With Email', 'khvichadev-waitlist-notify'); ?></option>
                                <option value="phone" <?php selected($channel_filter, 'phone'); ?>><?php esc_html_e('With Phone', 'khvichadev-waitlist-notify'); ?></option>
                                <option value="whatsapp" <?php selected($channel_filter, 'whatsapp'); ?>><?php esc_html_e('With WhatsApp', 'khvichadev-waitlist-notify'); ?></option>
                            </select>

                            <select name="limit" class="kd-search-input" style="min-width: 110px;" onchange="this.form.submit();" title="<?php esc_attr_e('Subscribers per page', 'khvichadev-waitlist-notify'); ?>">
                                <option value="20" <?php selected($limit, 20); ?>>
                                    <?php
                                    /* translators: %d: number of items per page */
                                    echo esc_html( sprintf( __( '%d / page', 'khvichadev-waitlist-notify' ), 20 ) );
                                    ?>
                                </option>
                                <option value="50" <?php selected($limit, 50); ?>>
                                    <?php
                                    /* translators: %d: number of items per page */
                                    echo esc_html( sprintf( __( '%d / page', 'khvichadev-waitlist-notify' ), 50 ) );
                                    ?>
                                </option>
                                <option value="100" <?php selected($limit, 100); ?>>
                                    <?php
                                    /* translators: %d: number of items per page */
                                    echo esc_html( sprintf( __( '%d / page', 'khvichadev-waitlist-notify' ), 100 ) );
                                    ?>
                                </option>
                            </select>

                            <?php if (!empty($search) || !empty($channel_filter) || isset($_GET['limit'])) : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=khvichadev-waitlist-notify&service_id=' . $active_service_id)); ?>" class="kd-admin-btn kd-btn-outline"><?php esc_html_e('Clear', 'khvichadev-waitlist-notify'); ?></a>
                            <?php endif; ?>
                        </form>

                        <div style="display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap;">
                            <button id="kd-reset-status-btn" class="kd-admin-btn kd-btn-warning" <?php echo ($count_notified === 0 && $count_failed === 0) ? 'style="display: none;"' : ''; ?>>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width: 18px; height: 18px;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg> <?php esc_html_e('Reset Notified to Subscribed', 'khvichadev-waitlist-notify'); ?>
                            </button>

                            <?php if ($count_all > 0) : ?>
                                <button id="kd-delete-all-btn" class="kd-admin-btn kd-btn-danger">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 18px; height: 18px;">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg> <?php esc_html_e('Delete All Subscribers', 'khvichadev-waitlist-notify'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="kd-table-wrapper">
                        <table class="wp-list-table widefat fixed striped table-view-list kd-data-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="manage-column"><?php esc_html_e('Name', 'khvichadev-waitlist-notify'); ?></th>
                                    <th scope="col" class="manage-column"><?php esc_html_e('Email', 'khvichadev-waitlist-notify'); ?></th>
                                    <th scope="col" class="manage-column"><?php esc_html_e('Phone (SMS)', 'khvichadev-waitlist-notify'); ?></th>
                                    <th scope="col" class="manage-column"><?php esc_html_e('WhatsApp', 'khvichadev-waitlist-notify'); ?></th>
                                    <th scope="col" class="manage-column"><?php esc_html_e('Status', 'khvichadev-waitlist-notify'); ?></th>
                                    <th scope="col" class="manage-column"><?php esc_html_e('Registered Date', 'khvichadev-waitlist-notify'); ?></th>
                                    <th scope="col" class="manage-column" style="width: 130px;"><?php esc_html_e('Actions', 'khvichadev-waitlist-notify'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($subscribers)) : ?>
                                    <?php foreach ($subscribers as $row) : 
                                        if ($row['status'] === 'notified') {
                                            $status_class = 'kd-badge-notified';
                                        } elseif ($row['status'] === 'failed') {
                                            $status_class = 'kd-badge-failed';
                                        } else {
                                            $status_class = 'kd-badge-subscribed';
                                        }
                                        ?>
                                        <tr id="kd-subscriber-row-<?php echo (int) $row['id']; ?>">
                                            <td class="font-weight-bold">
                                                <strong><?php echo esc_html($row['name']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['email'])) : ?>
                                                    <a href="mailto:<?php echo esc_attr($row['email']); ?>"><?php echo esc_html($row['email']); ?></a>
                                                <?php else : ?>
                                                    <span class="kd-field-empty">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo !empty($row['phone']) ? esc_html($row['phone']) : '<span class="kd-field-empty">—</span>'; ?>
                                            </td>
                                            <td>
                                                <?php echo !empty($row['whatsapp']) ? esc_html($row['whatsapp']) : '<span class="kd-field-empty">—</span>'; ?>
                                            </td>
                                            <td>
                                                <span class="kd-status-badge <?php echo esc_attr($status_class); ?>">
                                                    <?php echo esc_html(ucfirst($row['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row['created_at']))); ?>
                                            </td>
                                            <td>
                                                <button class="kd-delete-btn" data-id="<?php echo (int) $row['id']; ?>" title="<?php esc_attr_e('Delete subscriber', 'khvichadev-waitlist-notify'); ?>">
                                                    <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Delete', 'khvichadev-waitlist-notify'); ?>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 2rem;"><?php esc_html_e('No subscribers found.', 'khvichadev-waitlist-notify'); ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Links -->
                    <?php if ($total_pages > 1) : ?>
                        <div class="kd-pagination">
                            <?php
                            echo wp_kses_post(
                                paginate_links(array(
                                    'base'      => add_query_arg('paged', '%#%'),
                                    'format'    => '',
                                    'prev_text' => __('&laquo; Previous', 'khvichadev-waitlist-notify'),
                                    'next_text' => __('Next &raquo;', 'khvichadev-waitlist-notify'),
                                    'total'     => $total_pages,
                                    'current'   => $paged,
                                    'type'      => 'plain',
                                ))
                            );
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 2: Notification Campaign -->
                <div id="campaign-tab" class="kd-tab-content">
                    <div class="kd-campaign-layout">
                        <div class="kd-campaign-form-card">
                            <h3 class="kd-card-title"><?php esc_html_e('Send Broadcast Notifications', 'khvichadev-waitlist-notify'); ?></h3>
                            <p class="kd-card-description"><?php esc_html_e('Compose launch notifications and broadcast them to subscribers over email, SMS, or WhatsApp channels.', 'khvichadev-waitlist-notify'); ?></p>

                            <form id="kd-campaign-form">
                                <div class="kd-admin-form-group">
                                    <label for="kd-campaign-channel" class="kd-admin-label"><?php esc_html_e('Delivery Channel', 'khvichadev-waitlist-notify'); ?></label>
                                    <select id="kd-campaign-channel" class="kd-admin-select">
                                        <optgroup label="<?php esc_attr_e('Automated (via Gateways)', 'khvichadev-waitlist-notify'); ?>">
                                            <option value="email" selected><?php esc_html_e('Email (wp_mail)', 'khvichadev-waitlist-notify'); ?></option>
                                            <option value="sms"><?php esc_html_e('SMS (Twilio)', 'khvichadev-waitlist-notify'); ?></option>
                                            <option value="whatsapp"><?php esc_html_e('WhatsApp (Twilio)', 'khvichadev-waitlist-notify'); ?></option>
                                            <option value="custom_sms"><?php esc_html_e('Automated SMS (via Custom HTTP Gateway)', 'khvichadev-waitlist-notify'); ?></option>
                                            <option value="custom_whatsapp"><?php esc_html_e('Automated WhatsApp (via Custom HTTP Gateway)', 'khvichadev-waitlist-notify'); ?></option>
                                        </optgroup>
                                        <optgroup label="<?php esc_attr_e('Manual (Free Gateways)', 'khvichadev-waitlist-notify'); ?>">
                                            <option value="manual_sms"><?php esc_html_e('Manual SMS (via Device Link)', 'khvichadev-waitlist-notify'); ?></option>
                                            <option value="manual_whatsapp"><?php esc_html_e('Manual WhatsApp (via WhatsApp Web)', 'khvichadev-waitlist-notify'); ?></option>
                                            <option value="manual_whatsapp_app"><?php esc_html_e('Manual WhatsApp (via Desktop App - No Reload)', 'khvichadev-waitlist-notify'); ?></option>
                                        </optgroup>
                                    </select>
                                    <p class="kd-field-desc"><?php esc_html_e('Notifications will be sent only to subscribers who registered this specific channel.', 'khvichadev-waitlist-notify'); ?></p>
                                </div>

                                <div class="kd-admin-form-group" id="kd-subject-group">
                                    <label for="kd-campaign-subject" class="kd-admin-label"><?php esc_html_e('Email Subject', 'khvichadev-waitlist-notify'); ?></label>
                                    <input type="text" id="kd-campaign-subject" class="kd-admin-input" placeholder="<?php esc_attr_e('Our application is officially live!', 'khvichadev-waitlist-notify'); ?>" />
                                </div>

                                <div class="kd-admin-form-group">
                                    <label for="kd-campaign-message" class="kd-admin-label" id="kd-message-label"><?php esc_html_e('Email Body (HTML supported)', 'khvichadev-waitlist-notify'); ?></label>
                                    <textarea id="kd-campaign-message" class="kd-admin-textarea" rows="10" placeholder="<?php esc_attr_e("Hi {name},\n\nWe are excited to announce that our app is ready! Click the link below to get started...", 'khvichadev-waitlist-notify'); ?>" required></textarea>
                                </div>

                                <div class="kd-admin-form-group" id="kd-batch-size-group">
                                    <label for="kd-campaign-batch-size" class="kd-admin-label"><?php esc_html_e('Batch Size (Subscribers per request)', 'khvichadev-waitlist-notify'); ?></label>
                                    <select id="kd-campaign-batch-size" class="kd-admin-select">
                                        <option value="5">
                                            <?php
                                            /* translators: %d: number of subscribers */
                                            echo esc_html( sprintf( __( '%d subscribers (Slow / API limits)', 'khvichadev-waitlist-notify' ), 5 ) );
                                            ?>
                                        </option>
                                        <option value="15" selected>
                                            <?php
                                            /* translators: %d: number of subscribers */
                                            echo esc_html( sprintf( __( '%d subscribers (Recommended)', 'khvichadev-waitlist-notify' ), 15 ) );
                                            ?>
                                        </option>
                                        <option value="30">
                                            <?php
                                            /* translators: %d: number of subscribers */
                                            echo esc_html( sprintf( __( '%d subscribers (Fast VPS / SMTP)', 'khvichadev-waitlist-notify' ), 30 ) );
                                            ?>
                                        </option>
                                        <option value="50">
                                            <?php
                                            /* translators: %d: number of subscribers */
                                            echo esc_html( sprintf( __( '%d subscribers (High performance)', 'khvichadev-waitlist-notify' ), 50 ) );
                                            ?>
                                        </option>
                                    </select>
                                    <p class="kd-field-desc"><?php esc_html_e('Prevents timeouts during bulk operations.', 'khvichadev-waitlist-notify'); ?></p>
                                </div>

                                <button type="submit" class="kd-admin-btn kd-btn-primary kd-btn-large" id="kd-start-campaign-btn">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width: 18px; height: 18px;">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                    </svg> <?php esc_html_e('Send Broadcast Campaign', 'khvichadev-waitlist-notify'); ?>
                                </button>
                            </form>
                        </div>

                        <!-- Sidebar Tips -->
                        <div class="kd-campaign-sidebar">
                            <div class="kd-sidebar-card">
                                <h4 class="kd-sidebar-title"><?php esc_html_e('Dynamic Personalization', 'khvichadev-waitlist-notify'); ?></h4>
                                <p><?php esc_html_e('You can use the following tags in your text. They will be resolved per subscriber:', 'khvichadev-waitlist-notify'); ?></p>
                                <ul class="kd-placeholder-list">
                                    <li><code>{name}</code> - <?php esc_html_e("Inserts subscriber's full name", 'khvichadev-waitlist-notify'); ?></li>
                                    <li><code>{email}</code> - <?php esc_html_e("Inserts subscriber's email", 'khvichadev-waitlist-notify'); ?></li>
                                </ul>
                                
                                <div class="kd-sidebar-alert" style="margin-top: 15px;">
                                    <strong><?php esc_html_e('Important Note:', 'khvichadev-waitlist-notify'); ?></strong>
                                    <div style="margin-top: 5px;" id="kd-channel-disclaimer">
                                        <?php esc_html_e('Email campaigns send using standard WordPress mailers. Configure an SMTP plugin to improve inbox delivery rates.', 'khvichadev-waitlist-notify'); ?>
                                    </div>
                                </div>
                            </div>

                            <!-- New Clipboard Copier Card -->
                            <div class="kd-sidebar-card">
                                <h4 class="kd-sidebar-title"><?php esc_html_e('Clipboard Copier (Free)', 'khvichadev-waitlist-notify'); ?></h4>
                                <p><?php esc_html_e('Copy all active phone/WhatsApp numbers to clipboard as a comma-separated list for easy importing into desktop softwares or broadcast groups:', 'khvichadev-waitlist-notify'); ?></p>
                                <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 12px;">
                                    <button type="button" id="kd-copy-phones-btn" class="kd-admin-btn kd-btn-outline" style="justify-content: center; width: 100%;">
                                        <span class="dashicons dashicons-clipboard"></span> <?php esc_html_e('Copy Pending Phones', 'khvichadev-waitlist-notify'); ?>
                                    </button>
                                    <button type="button" id="kd-copy-was-btn" class="kd-admin-btn kd-btn-outline" style="justify-content: center; width: 100%;">
                                        <span class="dashicons dashicons-clipboard"></span> <?php esc_html_e('Copy Pending WhatsApps', 'khvichadev-waitlist-notify'); ?>
                                    </button>
                                </div>
                                <div id="kd-copy-status-msg" style="display: none; font-size: 0.85rem; color: #10b981; margin-top: 10px; font-weight: 600; text-align: center;"><?php esc_html_e('Numbers copied!', 'khvichadev-waitlist-notify'); ?></div>
                            </div>
                        </div>
                    </div>
                </div> <!-- Closes kd-campaign-layout -->
            </div> <!-- Closes campaign-tab -->

            <!-- Tab 3: Form & Gateway Settings -->
            <div id="settings-tab" class="kd-tab-content">
                        <form method="post" action="" class="kd-settings-layout">
                            <?php wp_nonce_field('kdwn_save_settings_action', 'kdwn_save_settings_nonce'); ?>

                            <?php if ($active_service_id === 1) : ?>
                                <div class="kd-info-banner" style="background: rgba(99, 102, 241, 0.08); border: 1px dashed rgba(99, 102, 241, 0.25); padding: 1.2rem 1.5rem; border-radius: 14px; color: #c7d2fe; display: flex; gap: 0.8rem; align-items: flex-start;">
                                    <span class="dashicons dashicons-info" style="font-size: 20px; width: 20px; height: 20px; color: #818cf8; flex-shrink: 0; margin-top: 2px;"></span>
                                    <div style="font-size: 0.95rem; line-height: 1.5;">
                                        <strong><?php esc_html_e('Default Service Settings:', 'khvichadev-waitlist-notify'); ?></strong> <?php esc_html_e("These settings are global defaults. When you create any new service, these configurations will be copied automatically as its initial setup. You can then modify them individually from that service's dashboard.", 'khvichadev-waitlist-notify'); ?>
                                    </div>
                                </div>
                            <?php else : ?>
                                <div class="kd-info-banner" style="background: rgba(245, 158, 11, 0.08); border: 1px dashed rgba(245, 158, 11, 0.25); padding: 1.2rem 1.5rem; border-radius: 14px; color: #fde047; display: flex; gap: 0.8rem; align-items: flex-start;">
                                    <span class="dashicons dashicons-info" style="font-size: 20px; width: 20px; height: 20px; color: #fbbf24; flex-shrink: 0; margin-top: 2px;"></span>
                                    <div style="font-size: 0.95rem; line-height: 1.5;">
                                        <strong><?php esc_html_e('Individual Campaign Settings:', 'khvichadev-waitlist-notify'); ?></strong> <?php esc_html_e("This service was initialized with parameters cloned from the default service. You can now modify and customize them to define individual settings specifically for this service/product waitlist campaign.", 'khvichadev-waitlist-notify'); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Shortcode Integration Card -->
                            <div class="kd-settings-card">
                                <h3 class="kd-card-title"><?php esc_html_e('Shortcodes & Integration', 'khvichadev-waitlist-notify'); ?></h3>
                                <p class="kd-card-description"><?php esc_html_e('Copy these shortcodes and paste them into any page, post, or widget on your WordPress site to display the registration form or subscriber count for this service.', 'khvichadev-waitlist-notify'); ?></p>
                                
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; align-items: start;">
                                    <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 1.2rem; display: flex; flex-direction: column; justify-content: space-between;">
                                        <div>
                                            <h4 style="margin: 0 0 8px 0; font-size: 1rem; color: #ffffff; font-weight: 600;"><?php esc_html_e('1. Registration Form Shortcode', 'khvichadev-waitlist-notify'); ?></h4>
                                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 0 0 15px 0; line-height: 1.4;"><?php esc_html_e('Renders the early access signup form with the active fields and texts configured for this service.', 'khvichadev-waitlist-notify'); ?></p>
                                        </div>
                                        <div style="display: flex; gap: 0.5rem; align-items: center; background: rgba(0, 0, 0, 0.2); padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.05);">
                                            <code style="color: #818cf8; font-family: monospace; font-size: 0.9rem; flex: 1; word-break: break-all;" id="kd-shortcode-form-code">[kdwn_signup service="<?php echo esc_attr($services[$active_service_id]['name']); ?>"]</code>
                                            <button type="button" class="kd-admin-btn kd-btn-outline kd-copy-shortcode-btn" data-target="kd-shortcode-form-code" style="height: 32px !important; padding: 0 10px !important; font-size: 0.75rem; border-radius: 6px !important; flex-shrink: 0;" title="<?php esc_attr_e('Copy to clipboard', 'khvichadev-waitlist-notify'); ?>"><?php esc_html_e('Copy', 'khvichadev-waitlist-notify'); ?></button>
                                        </div>
                                    </div>
                                    
                                    <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 1.2rem; display: flex; flex-direction: column; justify-content: space-between;">
                                        <div>
                                            <h4 style="margin: 0 0 8px 0; font-size: 1rem; color: #ffffff; font-weight: 600;"><?php esc_html_e('2. Subscriber Count Shortcode', 'khvichadev-waitlist-notify'); ?></h4>
                                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 0 0 15px 0; line-height: 1.4;"><?php esc_html_e('Displays the total number of subscribed users for this service as a beautifully styled badge. Add <code>format="raw"</code> to output only the plain number (e.g. <code>[kdwn_subscriber_count service="..." format="raw"]</code>).', 'khvichadev-waitlist-notify'); ?></p>
                                        </div>
                                        <div style="display: flex; gap: 0.5rem; align-items: center; background: rgba(0, 0, 0, 0.2); padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.05);">
                                            <code style="color: #34d399; font-family: monospace; font-size: 0.9rem; flex: 1; word-break: break-all;" id="kd-shortcode-count-code">[kdwn_subscriber_count service="<?php echo esc_attr($services[$active_service_id]['name']); ?>"]</code>
                                            <button type="button" class="kd-admin-btn kd-btn-outline kd-copy-shortcode-btn" data-target="kd-shortcode-count-code" style="height: 32px !important; padding: 0 10px !important; font-size: 0.75rem; border-radius: 6px !important; flex-shrink: 0;" title="<?php esc_attr_e('Copy to clipboard', 'khvichadev-waitlist-notify'); ?>"><?php esc_html_e('Copy', 'khvichadev-waitlist-notify'); ?></button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Fields Config Card -->
                            <div class="kd-settings-card">
                                <h3 class="kd-card-title"><?php esc_html_e('Form Field Requirements', 'khvichadev-waitlist-notify'); ?></h3>
                                <p class="kd-card-description"><?php esc_html_e('Choose which fields to enable on your waitlist registration form, and configure which ones are required.', 'khvichadev-waitlist-notify'); ?></p>
                                
                                <table class="form-table kd-settings-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Field Name', 'khvichadev-waitlist-notify'); ?></th>
                                            <th style="text-align: center; width: 120px;"><?php esc_html_e('Enabled', 'khvichadev-waitlist-notify'); ?></th>
                                            <th style="text-align: center; width: 120px;"><?php esc_html_e('Required', 'khvichadev-waitlist-notify'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong><?php esc_html_e('Full Name', 'khvichadev-waitlist-notify'); ?></strong></td>
                                            <td style="text-align: center;"><span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span></td>
                                            <td style="text-align: center;"><span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span></td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php esc_html_e('Email Address', 'khvichadev-waitlist-notify'); ?></strong></td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="field_email_enabled" value="1" <?php checked(isset($fields_config['email']['enabled']) && $fields_config['email']['enabled']); ?> />
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="field_email_required" value="1" <?php checked(isset($fields_config['email']['required']) && $fields_config['email']['required']); ?> />
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php esc_html_e('Phone Number (SMS)', 'khvichadev-waitlist-notify'); ?></strong></td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="field_phone_enabled" value="1" <?php checked(isset($fields_config['phone']['enabled']) && $fields_config['phone']['enabled']); ?> />
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="field_phone_required" value="1" <?php checked(isset($fields_config['phone']['required']) && $fields_config['phone']['required']); ?> />
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php esc_html_e('WhatsApp Number', 'khvichadev-waitlist-notify'); ?></strong></td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="field_whatsapp_enabled" value="1" <?php checked(isset($fields_config['whatsapp']['enabled']) && $fields_config['whatsapp']['enabled']); ?> />
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="field_whatsapp_required" value="1" <?php checked(isset($fields_config['whatsapp']['required']) && $fields_config['whatsapp']['required']); ?> />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p class="kd-field-desc" style="margin-top: 15px; color: #f59e0b;"><?php esc_html_e('* If multiple fields are enabled, the validation rules will enforce requirements according to these options.', 'khvichadev-waitlist-notify'); ?></p>
                                
                                <div class="kd-admin-setting-separator" style="margin: 2rem 0 1.5rem 0; border-top: 1px dashed rgba(255, 255, 255, 0.08);"></div>
                                
                                <div class="kd-admin-setting-row" style="display: flex; align-items: center; justify-content: space-between; background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 1.2rem 1.5rem; border-radius: 12px; margin-bottom: 2rem;">
                                    <div>
                                        <h4 style="margin: 0 0 6px 0; font-size: 1rem; color: #ffffff; font-weight: 600;"><?php esc_html_e('Display Subscriber Count (Social Proof)', 'khvichadev-waitlist-notify'); ?></h4>
                                        <p style="margin: 0; font-size: 0.85rem; color: #94a3b8; line-height: 1.4;"><?php esc_html_e('Show the total number of registered subscribers directly inside the signup form header to build trust.', 'khvichadev-waitlist-notify'); ?></p>
                                    </div>
                                    <div style="display: flex; align-items: center; padding-left: 1rem;">
                                        <input type="checkbox" name="show_subscriber_count" value="1" <?php checked(isset($fields_config['show_subscriber_count']) && $fields_config['show_subscriber_count']); ?> style="width: 20px; height: 20px; cursor: pointer; accent-color: #6366f1;" />
                                    </div>
                                </div>

                                <div class="kd-admin-setting-row" style="display: flex; align-items: center; justify-content: space-between; background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 1.2rem 1.5rem; border-radius: 12px; margin-bottom: 2rem;">
                                    <div>
                                        <h4 style="margin: 0 0 6px 0; font-size: 1rem; color: #ffffff; font-weight: 600;"><?php esc_html_e('Require Notification Consent Checkbox', 'khvichadev-waitlist-notify'); ?></h4>
                                        <p style="margin: 0; font-size: 0.85rem; color: #94a3b8; line-height: 1.4;"><?php esc_html_e('Add a mandatory opt-in checkbox to the registration form requesting consent to send launch notifications.', 'khvichadev-waitlist-notify'); ?></p>
                                    </div>
                                    <div style="display: flex; align-items: center; padding-left: 1rem;">
                                        <input type="checkbox" name="consent_enabled" value="1" <?php checked(isset($fields_config['consent_enabled']) && $fields_config['consent_enabled']); ?> style="width: 20px; height: 20px; cursor: pointer; accent-color: #6366f1;" />
                                    </div>
                                </div>

                                <div class="kd-admin-setting-row" style="display: flex; align-items: center; justify-content: space-between; background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 1.2rem 1.5rem; border-radius: 12px; margin-bottom: 2rem;">
                                    <div>
                                        <h4 style="margin: 0 0 6px 0; font-size: 1rem; color: #ffffff; font-weight: 600;"><?php esc_html_e('Delete Data on Plugin Deletion', 'khvichadev-waitlist-notify'); ?></h4>
                                        <p style="margin: 0; font-size: 0.85rem; color: #f87171; line-height: 1.4;"><?php esc_html_e('Caution: If enabled, all subscribers, services, and configurations will be permanently deleted from the database when you delete the plugin.', 'khvichadev-waitlist-notify'); ?></p>
                                    </div>
                                    <div style="display: flex; align-items: center; padding-left: 1rem;">
                                        <input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked((bool) get_option('kdwn_delete_data_on_uninstall', false)); ?> style="width: 20px; height: 20px; cursor: pointer; accent-color: #6366f1;" />
                                    </div>
                                </div>
                                
                                <div class="kd-admin-form-group">
                                    <label for="default_country_code" class="kd-admin-label"><?php esc_html_e('Default Country Dialing Code', 'khvichadev-waitlist-notify'); ?></label>
                                    <div style="display: flex; gap: 0.8rem; align-items: center; max-width: 450px;">
                                        <select id="default_country_code" name="default_country_code" class="kd-admin-select" style="max-width: 320px; flex: 1;">
                                            <?php 
                                            $countries = kdwn_Database::kdwn_get_countries_list();
                                            $default_code = isset($fields_config['default_country_code']) ? $fields_config['default_country_code'] : '+995';
                                            foreach ($countries as $c_key => $c_data) : ?>
                                                <option value="<?php echo esc_attr($c_data['code']); ?>" <?php selected($c_data['code'], $default_code); ?>>
                                                    <?php echo esc_html($c_data['name'] . ' (' . $c_data['code'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" id="kd-add-country-trigger-btn" class="kd-admin-btn kd-btn-outline" style="height: 50px; padding: 0 15px !important; flex-shrink: 0;" title="<?php esc_attr_e('Add Custom Country', 'khvichadev-waitlist-notify'); ?>">
                                            <span class="dashicons dashicons-plus"></span> <?php esc_html_e('Add', 'khvichadev-waitlist-notify'); ?>
                                        </button>
                                    </div>
                                    <div id="kd-add-country-box" style="display: none; margin-top: 12px; padding: 15px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; max-width: 450px;">
                                        <h4 style="margin: 0 0 10px 0; font-size: 0.95rem; color: #ffffff;"><?php esc_html_e('Add Custom Dial Code', 'khvichadev-waitlist-notify'); ?></h4>
                                        <div style="display: flex; gap: 0.6rem; margin-bottom: 12px;">
                                            <div style="flex: 1;">
                                                <label class="kd-admin-label" style="font-size: 0.8rem; margin-bottom: 4px; display: block;"><?php esc_html_e('Initials (e.g. GE, US)', 'khvichadev-waitlist-notify'); ?></label>
                                                <input type="text" id="kd-new-country-name" class="kd-admin-input" placeholder="GE" style="height: 40px !important; padding: 8px 12px !important;" maxlength="10" />
                                            </div>
                                            <div style="flex: 1;">
                                                <label class="kd-admin-label" style="font-size: 0.8rem; margin-bottom: 4px; display: block;"><?php esc_html_e('Dial Code (e.g. +995)', 'khvichadev-waitlist-notify'); ?></label>
                                                <input type="text" id="kd-new-country-code" class="kd-admin-input" placeholder="+995" style="height: 40px !important; padding: 8px 12px !important;" />
                                            </div>
                                        </div>
                                        <div id="kd-country-error-msg" style="display: none; color: #f87171; font-size: 0.85rem; margin-bottom: 10px; font-weight: 500;"></div>
                                        <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                            <button type="button" id="kd-save-country-btn" class="kd-admin-btn kd-btn-primary" style="height: 36px; padding: 0 12px !important; font-size: 0.8rem;"><?php esc_html_e('Add Code', 'khvichadev-waitlist-notify'); ?></button>
                                            <button type="button" id="kd-cancel-country-btn" class="kd-admin-btn kd-btn-outline" style="height: 36px; padding: 0 12px !important; font-size: 0.8rem;"><?php esc_html_e('Cancel', 'khvichadev-waitlist-notify'); ?></button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form customizable texts Card -->
                            <div class="kd-settings-card">
                                <h3 class="kd-card-title"><?php esc_html_e('Frontend Form Content & Texts', 'khvichadev-waitlist-notify'); ?></h3>
                                <p class="kd-card-description"><?php esc_html_e('Customize labels, placeholders, header titles, and notifications shown to visitors of the waitlist signup form.', 'khvichadev-waitlist-notify'); ?></p>

                                <div class="kd-form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
                                    <div class="kd-admin-form-group">
                                        <label for="form_title" class="kd-admin-label"><?php esc_html_e('Form Main Title', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="form_title" name="form_title" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['form_title']) ? $form_texts['form_title'] : ''); ?>" placeholder="e.g. Join the waitlist!" />
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="form_subtitle" class="kd-admin-label"><?php esc_html_e('Form Subtitle / Tagline', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="form_subtitle" name="form_subtitle" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['form_subtitle']) ? $form_texts['form_subtitle'] : ''); ?>" placeholder="e.g. Get notified immediately when we launch" />
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="name_label" class="kd-admin-label"><?php esc_html_e('Name Input Placeholder', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="name_label" name="name_label" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['name_label']) ? $form_texts['name_label'] : ''); ?>" placeholder="e.g. Enter your name" />
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="email_label" class="kd-admin-label"><?php esc_html_e('Email Input Placeholder', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="email_label" name="email_label" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['email_label']) ? $form_texts['email_label'] : ''); ?>" placeholder="e.g. Enter your email" />
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="phone_label" class="kd-admin-label"><?php esc_html_e('Phone Input Placeholder', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="phone_label" name="phone_label" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['phone_label']) ? $form_texts['phone_label'] : ''); ?>" placeholder="e.g. Enter phone number" />
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="whatsapp_label" class="kd-admin-label"><?php esc_html_e('WhatsApp Input Placeholder', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="whatsapp_label" name="whatsapp_label" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['whatsapp_label']) ? $form_texts['whatsapp_label'] : ''); ?>" placeholder="e.g. Enter WhatsApp number" />
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="submit_btn" class="kd-admin-label"><?php esc_html_e('Submit Button Text', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="submit_btn" name="submit_btn" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['submit_btn']) ? $form_texts['submit_btn'] : ''); ?>" placeholder="e.g. Notify Me" />
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="success_title" class="kd-admin-label"><?php esc_html_e('Success Title', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="success_title" name="success_title" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['success_title']) ? $form_texts['success_title'] : ''); ?>" placeholder="e.g. You are on the list!" />
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="success_msg" class="kd-admin-label"><?php esc_html_e('Success Subtitle / Description', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="success_msg" name="success_msg" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['success_msg']) ? $form_texts['success_msg'] : ''); ?>" placeholder="e.g. Thank you for signing up for updates." />
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="social_proof_text" class="kd-admin-label"><?php esc_html_e('Social Proof Counter Message', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="social_proof_text" name="social_proof_text" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['social_proof_text']) ? $form_texts['social_proof_text'] : ''); ?>" placeholder="e.g. Joined by {count} subscribers" />
                                        <p class="kd-field-desc"><?php esc_html_e('Use {count} tag to display number of subscribers dynamically.', 'khvichadev-waitlist-notify'); ?></p>
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="badge_label" class="kd-admin-label"><?php esc_html_e('Subscriber badge text label', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="badge_label" name="badge_label" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['badge_label']) ? $form_texts['badge_label'] : ''); ?>" placeholder="e.g. Subscribers Joined" />
                                    </div>

                                    <div class="kd-admin-form-group">
                                        <label for="consent_label" class="kd-admin-label"><?php esc_html_e('Opt-in Consent Checkbox Text', 'khvichadev-waitlist-notify'); ?></label>
                                        <input type="text" id="consent_label" name="consent_label" class="kd-admin-input" value="<?php echo esc_attr(isset($form_texts['consent_label']) ? $form_texts['consent_label'] : ''); ?>" placeholder="e.g. I agree to receive notification messages when this application goes live." />
                                    </div>
                                </div>
                            </div>

                            <!-- Gateways Credentials Card -->
                            <div class="kd-settings-card">
                                <h3 class="kd-card-title"><?php esc_html_e('SMS & WhatsApp API Credentials', 'khvichadev-waitlist-notify'); ?></h3>
                                <p class="kd-card-description"><?php esc_html_e('Configure SMS and WhatsApp API providers to enable automated background campaigns.', 'khvichadev-waitlist-notify'); ?></p>

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 2rem;">
                                    <!-- Twilio Settings -->
                                    <div style="background: rgba(255, 255, 255, 0.01); border: 1px solid rgba(255, 255, 255, 0.04); padding: 1.5rem; border-radius: 12px;">
                                        <h4 style="margin: 0 0 15px 0; font-size: 1.05rem; color: #ffffff; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                                            <span class="dashicons dashicons-admin-links" style="color: #6366f1;"></span> <?php esc_html_e('Twilio API configuration', 'khvichadev-waitlist-notify'); ?>
                                        </h4>

                                        <div class="kd-admin-form-group">
                                            <label for="twilio_sid" class="kd-admin-label"><?php esc_html_e('Twilio Account SID', 'khvichadev-waitlist-notify'); ?></label>
                                            <input type="text" id="twilio_sid" name="twilio_sid" class="kd-admin-input" value="<?php echo esc_attr(isset($gateway_config['twilio_sid']) ? $gateway_config['twilio_sid'] : ''); ?>" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" />
                                        </div>

                                        <div class="kd-admin-form-group">
                                            <label for="twilio_token" class="kd-admin-label"><?php esc_html_e('Twilio Auth Token', 'khvichadev-waitlist-notify'); ?></label>
                                            <?php
                                            $has_token = !empty($gateway_config['twilio_token']);
                                            $masked_token = $has_token ? str_repeat('•', 24) : '';
                                            ?>
                                            <input type="password" id="twilio_token" name="twilio_token" class="kd-admin-input" value="<?php echo esc_attr($masked_token); ?>" placeholder="<?php echo $has_token ? esc_attr__('Token saved (Type to overwrite)', 'khvichadev-waitlist-notify') : 'Enter Twilio Auth Token'; ?>" />
                                        </div>

                                        <div class="kd-admin-form-group">
                                            <label for="twilio_sms_from" class="kd-admin-label"><?php esc_html_e('Sender SMS Number / SID', 'khvichadev-waitlist-notify'); ?></label>
                                            <input type="text" id="twilio_sms_from" name="twilio_sms_from" class="kd-admin-input" value="<?php echo esc_attr(isset($gateway_config['twilio_sms_from']) ? $gateway_config['twilio_sms_from'] : ''); ?>" placeholder="e.g. +18559021200 or MyBrand" />
                                        </div>

                                        <div class="kd-admin-form-group">
                                            <label for="twilio_wa_from" class="kd-admin-label"><?php esc_html_e('Sender WhatsApp Number', 'khvichadev-waitlist-notify'); ?></label>
                                            <input type="text" id="twilio_wa_from" name="twilio_wa_from" class="kd-admin-input" value="<?php echo esc_attr(isset($gateway_config['twilio_wa_from']) ? $gateway_config['twilio_wa_from'] : ''); ?>" placeholder="e.g. +14155238886 (Twilio Sandbox)" />
                                            <p class="kd-field-desc"><?php esc_html_e('Must register sender profiles on Twilio Console before sending.', 'khvichadev-waitlist-notify'); ?></p>
                                        </div>
                                    </div>

                                    <!-- Custom HTTP Gateways Settings -->
                                    <div style="background: rgba(255, 255, 255, 0.01); border: 1px solid rgba(255, 255, 255, 0.04); padding: 1.5rem; border-radius: 12px;">
                                        <h4 style="margin: 0 0 15px 0; font-size: 1.05rem; color: #ffffff; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                                            <span class="dashicons dashicons-admin-site" style="color: #34d399;"></span> <?php esc_html_e('Custom HTTP Gateway URL', 'khvichadev-waitlist-notify'); ?>
                                        </h4>

                                        <div class="kd-admin-form-group">
                                            <label for="custom_sms_url" class="kd-admin-label"><?php esc_html_e('Custom SMS Gateway URL', 'khvichadev-waitlist-notify'); ?></label>
                                            <input type="url" id="custom_sms_url" name="custom_sms_url" class="kd-admin-input" value="<?php echo esc_url(isset($gateway_config['custom_sms_url']) ? $gateway_config['custom_sms_url'] : ''); ?>" placeholder="https://sms-api.com/send?to={to}&msg={msg}" />
                                            <p class="kd-field-desc"><?php esc_html_e('Send requests automatically using custom queries. Use {to} and {msg} placeholders.', 'khvichadev-waitlist-notify'); ?></p>
                                        </div>

                                        <div class="kd-admin-form-group">
                                            <label for="custom_wa_url" class="kd-admin-label"><?php esc_html_e('Custom WhatsApp Gateway URL', 'khvichadev-waitlist-notify'); ?></label>
                                            <input type="url" id="custom_wa_url" name="custom_wa_url" class="kd-admin-input" value="<?php echo esc_url(isset($gateway_config['custom_wa_url']) ? $gateway_config['custom_wa_url'] : ''); ?>" placeholder="https://whatsapp-api.com/send?phone={to}&text={msg}" />
                                            <p class="kd-field-desc"><?php esc_html_e('HTTP endpoints configured to broadcast custom requests using {to} and {msg} values.', 'khvichadev-waitlist-notify'); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Settings Save Panel -->
                            <div class="kd-settings-footer-card" style="margin-top: 2rem; display: flex; justify-content: flex-end; padding: 1.2rem; background: rgba(0, 0, 0, 0.15); border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.05);">
                                <button type="submit" class="kd-admin-btn kd-btn-primary kd-btn-large">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width: 18px; height: 18px;">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg> <?php esc_html_e('Save Configuration Settings', 'khvichadev-waitlist-notify'); ?>
                                </button>
                            </div>
                        </form>
                </div>
            </div>

            <!-- Campaign Progress Overlay Modal -->
            <div id="kd-campaign-modal" class="kd-modal-overlay" style="display: none;">
                <div class="kd-modal-card">
                    <!-- Automated Queue View -->
                    <div id="kd-auto-campaign-view">
                        <h3 class="kd-modal-title"><?php esc_html_e('Sending Broadcast Campaign', 'khvichadev-waitlist-notify'); ?></h3>
                        <p class="kd-modal-subtitle"><?php esc_html_e('Please keep this browser window open until the campaign is completed.', 'khvichadev-waitlist-notify'); ?></p>
                        
                        <div class="kd-progress-container">
                            <div class="kd-progress-bar-track">
                                <div id="kd-progress-bar-fill" class="kd-progress-bar-fill" style="width: 0%;"></div>
                            </div>
                            <div class="kd-progress-meta">
                                <span id="kd-progress-percentage">0%</span>
                                <span><span id="kd-progress-ratio">0 / 0</span> <?php esc_html_e('notified', 'khvichadev-waitlist-notify'); ?></span>
                            </div>
                        </div>

                        <div class="kd-log-container">
                            <div class="kd-log-header"><?php esc_html_e('Delivery Activity Log', 'khvichadev-waitlist-notify'); ?></div>
                            <div id="kd-campaign-log" class="kd-log-body">
                                <p class="kd-log-placeholder"><?php esc_html_e('Initializing campaign...', 'khvichadev-waitlist-notify'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Manual Queue View (For Free SMS/WhatsApp Sending) -->
                    <div id="kd-manual-campaign-view" style="display: none;">
                        <h3 class="kd-modal-title"><?php esc_html_e('Manual Notification Queue', 'khvichadev-waitlist-notify'); ?></h3>
                        <p class="kd-modal-subtitle"><?php esc_html_e('Follow the queue to send messages manually using your device or WhatsApp Web.', 'khvichadev-waitlist-notify'); ?></p>

                        <div class="kd-manual-queue-card">
                            <div class="kd-queue-meta">
                                <span class="kd-queue-badge"><?php esc_html_e('Remaining in Queue:', 'khvichadev-waitlist-notify'); ?> <strong id="kd-manual-progress-ratio">0 / 0</strong></span>
                            </div>
                            <div class="kd-subscriber-card-inline">
                                <div class="kd-sub-avatar"><span class="dashicons dashicons-admin-users"></span></div>
                                <div class="kd-sub-details">
                                    <h4 id="kd-manual-sub-name"><?php esc_html_e('Loading...', 'khvichadev-waitlist-notify'); ?></h4>
                                    <p id="kd-manual-sub-number"><?php esc_html_e('Loading...', 'khvichadev-waitlist-notify'); ?></p>
                                </div>
                            </div>
                            <div class="kd-message-preview-box">
                                <div class="kd-preview-header"><?php esc_html_e('Message Preview:', 'khvichadev-waitlist-notify'); ?></div>
                                <div id="kd-manual-message-preview" class="kd-preview-body"></div>
                            </div>
                            <div class="kd-manual-actions">
                                <button type="button" id="kd-manual-send-btn" class="kd-admin-btn kd-btn-primary kd-btn-large" style="width: 100%; justify-content: center; margin-bottom: 0.8rem; height: 50px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width: 18px; height: 18px;">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                    </svg> <?php esc_html_e('Open & Send Message', 'khvichadev-waitlist-notify'); ?>
                                </button>
                                <div style="display: flex; gap: 0.5rem;">
                                    <button type="button" id="kd-manual-skip-btn" class="kd-admin-btn kd-btn-outline" style="flex: 1; justify-content: center;"><?php esc_html_e('Skip User', 'khvichadev-waitlist-notify'); ?></button>
                                    <button type="button" id="kd-manual-mark-btn" class="kd-admin-btn kd-btn-warning" style="flex: 1; justify-content: center;"><?php esc_html_e('Mark as Sent', 'khvichadev-waitlist-notify'); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="kd-modal-footer">
                        <button id="kd-close-modal-btn" class="kd-admin-btn kd-btn-outline" style="display: none;"><?php esc_html_e('Close Dialog', 'khvichadev-waitlist-notify'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Add Service Modal -->
            <div id="kd-service-modal" class="kd-modal-overlay" style="display: none;">
                <div class="kd-modal-card" style="max-width: 480px;">
                    <h3 class="kd-modal-title"><?php esc_html_e('Create New Service', 'khvichadev-waitlist-notify'); ?></h3>
                    <p class="kd-modal-subtitle"><?php esc_html_e('Define a new service or product waitlist campaign.', 'khvichadev-waitlist-notify'); ?></p>
                    
                    <form id="kd-create-service-form" style="display: flex; flex-direction: column; gap: 1.2rem;">
                        <div class="kd-admin-form-group" style="margin-bottom: 0;">
                            <label for="kd-new-service-name" class="kd-admin-label"><?php esc_html_e('Service Name *', 'khvichadev-waitlist-notify'); ?></label>
                            <input type="text" id="kd-new-service-name" class="kd-admin-input" placeholder="<?php esc_attr_e('e.g. Mobile App Beta', 'khvichadev-waitlist-notify'); ?>" required />
                        </div>
                        
                        <div class="kd-admin-form-group" style="margin-bottom: 0;">
                            <label for="kd-new-service-desc" class="kd-admin-label"><?php esc_html_e('Description (Optional)', 'khvichadev-waitlist-notify'); ?></label>
                            <textarea id="kd-new-service-desc" class="kd-admin-textarea" rows="3" placeholder="<?php esc_attr_e('Brief description of the service...', 'khvichadev-waitlist-notify'); ?>" style="min-height: 80px;"></textarea>
                        </div>
                        
                        <div id="kd-service-error-msg" style="display: none; color: #f87171; font-size: 0.85rem; font-weight: 500;"></div>
                        
                        <div style="display: flex; gap: 0.6rem; justify-content: flex-end; margin-top: 0.5rem;">
                            <button type="submit" id="kd-save-service-btn" class="kd-admin-btn kd-btn-primary"><?php esc_html_e('Create Service', 'khvichadev-waitlist-notify'); ?></button>
                            <button type="button" id="kd-close-service-modal-btn" class="kd-admin-btn kd-btn-outline"><?php esc_html_e('Cancel', 'khvichadev-waitlist-notify'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}
