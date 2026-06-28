<?php
/**
 * Database handler class for waitlist subscribers.
 * This class handles database table creation, schema upgrades, and subscriber queries.
 */

if (!defined('ABSPATH')) {
    exit;
}

class kdwn_Database {
    /**
     * Get the name of the subscribers table with the WordPress prefix.
     */
    public static function kdwn_get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'kdwn_subscribers';
    }

    public static function kdwn_create_table() {
        global $wpdb;
        $table_name = self::kdwn_get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $table_exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name )
        ) === $table_name;

        if ($table_exists) {
            $column_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SHOW COLUMNS FROM {$wpdb->prefix}kdwn_subscribers LIKE %s",
                    'phone'
                )
            );
            if (empty($column_exists)) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers DROP INDEX email" );
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD COLUMN phone varchar(50) DEFAULT '' NOT NULL AFTER email" );
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD COLUMN whatsapp varchar(50) DEFAULT '' NOT NULL AFTER phone" );
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD INDEX (email)" );
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD INDEX (phone)" );
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD INDEX (whatsapp)" );
                // phpcs:enable
            }
            $service_column_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SHOW COLUMNS FROM {$wpdb->prefix}kdwn_subscribers LIKE %s",
                    'service_id'
                )
            );
            if (empty($service_column_exists)) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD COLUMN service_id bigint(20) DEFAULT 1 NOT NULL AFTER id" );
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD INDEX (service_id)" );
                // phpcs:enable
            }

            // Check and add composite indexes if they do not exist
            $index_email_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SHOW INDEX FROM {$wpdb->prefix}kdwn_subscribers WHERE Key_name = %s",
                    'service_email'
                )
            );
            if (empty($index_email_exists)) {
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD INDEX service_email (email, service_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            }
            $index_phone_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SHOW INDEX FROM {$wpdb->prefix}kdwn_subscribers WHERE Key_name = %s",
                    'service_phone'
                )
            );
            if (empty($index_phone_exists)) {
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD INDEX service_phone (phone, service_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            }
            $index_whatsapp_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SHOW INDEX FROM {$wpdb->prefix}kdwn_subscribers WHERE Key_name = %s",
                    'service_whatsapp'
                )
            );
            if (empty($index_whatsapp_exists)) {
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD INDEX service_whatsapp (whatsapp, service_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            }
            $index_status_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SHOW INDEX FROM {$wpdb->prefix}kdwn_subscribers WHERE Key_name = %s",
                    'service_status'
                )
            );
            if (empty($index_status_exists)) {
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}kdwn_subscribers ADD INDEX service_status (status, service_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            }
        } else {
            $sql = "CREATE TABLE {$wpdb->prefix}kdwn_subscribers (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                service_id bigint(20) DEFAULT 1 NOT NULL,
                name varchar(100) NOT NULL,
                email varchar(100) DEFAULT '' NOT NULL,
                phone varchar(50) DEFAULT '' NOT NULL,
                whatsapp varchar(50) DEFAULT '' NOT NULL,
                status varchar(20) DEFAULT 'subscribed' NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY (id),
                KEY service_id (service_id),
                KEY email (email),
                KEY phone (phone),
                KEY whatsapp (whatsapp),
                KEY service_email (email, service_id),
                KEY service_phone (phone, service_id),
                KEY service_whatsapp (whatsapp, service_id),
                KEY service_status (status, service_id)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }

    /**
     * Get the enabled/required configuration settings for form fields.
     */
    public static function kdwn_get_fields_config($service_id = 1) {
        $default = array(
            'email'                 => array('enabled' => true, 'required' => true),
            'phone'                 => array('enabled' => false, 'required' => false),
            'whatsapp'              => array('enabled' => false, 'required' => false),
            'default_country_code'  => '+995',
            'show_subscriber_count' => false,
            'consent_enabled'       => false
        );

        if ($service_id > 1) {
            $option_name = 'kdwn_fields_config_' . $service_id;
            $value = get_option($option_name, null);
            if ($value !== null && is_array($value)) {
                $saved = $value;
            } else {
                $saved = get_option('kdwn_fields_config', array());
            }
        } else {
            $saved = get_option('kdwn_fields_config', array());
        }

        if (!is_array($saved)) {
            $saved = array();
        }

        // Deep merge nested field settings to avoid Undefined array key warnings
        $result = $default;
        foreach (array('email', 'phone', 'whatsapp') as $key) {
            if (isset($saved[$key]) && is_array($saved[$key])) {
                $result[$key] = array_merge($default[$key], $saved[$key]);
            }
        }
        foreach (array('default_country_code', 'show_subscriber_count', 'consent_enabled') as $key) {
            if (isset($saved[$key])) {
                $result[$key] = $saved[$key];
            }
        }

        return $result;
    }

    /**
     * Get credentials and settings for SMS/WhatsApp API gateways.
     */
    public static function kdwn_get_gateway_config($service_id = 1) {
        $default = array(
            'twilio_sid'      => '',
            'twilio_token'    => '',
            'twilio_sms_from' => '',
            'twilio_wa_from'  => '',
            'custom_sms_url'  => '',
            'custom_wa_url'   => ''
        );

        if ($service_id > 1) {
            $option_name = 'kdwn_gateway_config_' . $service_id;
            $value = get_option($option_name, null);
            if ($value !== null && is_array($value)) {
                $saved = $value;
            } else {
                $saved = get_option('kdwn_gateway_config', array());
            }
        } else {
            $saved = get_option('kdwn_gateway_config', array());
        }

        if (!is_array($saved)) {
            $saved = array();
        }

        return array_merge($default, $saved);
    }

    /**
     * Add a new subscriber to the database.
     */
    public static function kdwn_add_subscriber($name, $email = '', $phone = '', $whatsapp = '', $service_id = 1) {
        global $wpdb;
        $table_name = self::kdwn_get_table_name();

        return $wpdb->insert(
            $table_name,
            array(
                'name'       => sanitize_text_field($name),
                'email'      => sanitize_email($email),
                'phone'      => sanitize_text_field($phone),
                'whatsapp'   => sanitize_text_field($whatsapp),
                'service_id' => (int) $service_id
            ),
            array('%s', '%s', '%s', '%s', '%d')
        );
    }

    /**
     * Check if a subscriber already exists by email.
     */
    public static function kdwn_subscriber_exists($email, $service_id = 1) {
        if (empty($email)) {
            return false;
        }

        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}kdwn_subscribers WHERE email = %s AND service_id = %d",
                sanitize_email($email),
                (int) $service_id
            )
        ) > 0;
    }

    /**
     * Check if a subscriber already exists by phone number.
     */
    public static function kdwn_subscriber_exists_by_phone($phone, $service_id = 1) {
        if (empty($phone)) {
            return false;
        }

        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}kdwn_subscribers WHERE phone = %s AND service_id = %d",
                sanitize_text_field($phone),
                (int) $service_id
            )
        ) > 0;
    }

    /**
     * Check if a subscriber already exists by WhatsApp number.
     */
    public static function kdwn_subscriber_exists_by_whatsapp($whatsapp, $service_id = 1) {
        if (empty($whatsapp)) {
            return false;
        }

        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}kdwn_subscribers WHERE whatsapp = %s AND service_id = %d",
                sanitize_text_field($whatsapp),
                (int) $service_id
            )
        ) > 0;
    }

    /**
     * Get subscribers with filters and offset for pagination/batch operations.
     */
    public static function kdwn_get_subscribers($limit = 100, $offset = 0, $search = '', $channel_filter = '', $service_id = 1) {
        global $wpdb;

        $search_val = !empty($search) ? $search : '';
        $search_wildcard = '%' . $wpdb->esc_like($search_val) . '%';
        $channel_val = !empty($channel_filter) ? $channel_filter : '';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}kdwn_subscribers 
                WHERE service_id = %d 
                  AND (%s = '' OR (
                      (%s = 'email' AND email != '') OR 
                      (%s = 'phone' AND phone != '') OR 
                      (%s = 'whatsapp' AND whatsapp != '')
                  ))
                  AND (%s = '' OR (
                      name LIKE %s OR email LIKE %s OR phone LIKE %s OR whatsapp LIKE %s
                  ))
                ORDER BY created_at DESC LIMIT %d OFFSET %d",
                (int) $service_id,
                $channel_val,
                $channel_val,
                $channel_val,
                $channel_val,
                $search_val,
                $search_wildcard,
                $search_wildcard,
                $search_wildcard,
                $search_wildcard,
                (int) $limit,
                (int) $offset
            ),
            ARRAY_A
        );
    }

    /**
     * Get total count of subscribers, matching filter criteria.
     */
    public static function kdwn_get_subscribers_count($search = '', $channel_filter = '', $service_id = 1) {
        global $wpdb;

        $search_val = !empty($search) ? $search : '';
        $search_wildcard = '%' . $wpdb->esc_like($search_val) . '%';
        $channel_val = !empty($channel_filter) ? $channel_filter : '';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}kdwn_subscribers 
                WHERE service_id = %d 
                  AND (%s = '' OR (
                      (%s = 'email' AND email != '') OR 
                      (%s = 'phone' AND phone != '') OR 
                      (%s = 'whatsapp' AND whatsapp != '')
                  ))
                  AND (%s = '' OR (
                      name LIKE %s OR email LIKE %s OR phone LIKE %s OR whatsapp LIKE %s
                  ))",
                (int) $service_id,
                $channel_val,
                $channel_val,
                $channel_val,
                $channel_val,
                $search_val,
                $search_wildcard,
                $search_wildcard,
                $search_wildcard,
                $search_wildcard
            )
        );
    }

    /**
     * Delete a subscriber by ID.
     */
    public static function kdwn_delete_subscriber($id) {
        global $wpdb;
        $table_name = self::kdwn_get_table_name();

        return $wpdb->delete(
            $table_name,
            array('id' => (int) $id),
            array('%d')
        );
    }

    /**
     * Delete all subscribers for a specific service.
     */
    public static function kdwn_delete_all_subscribers($service_id) {
        global $wpdb;
        $table_name = self::kdwn_get_table_name();

        return $wpdb->delete(
            $table_name,
            array('service_id' => (int) $service_id),
            array('%d')
        );
    }

    /**
     * Update subscriber status.
     */
    public static function kdwn_update_subscriber_status($id, $status) {
        global $wpdb;
        $table_name = self::kdwn_get_table_name();

        return $wpdb->update(
            $table_name,
            array('status' => sanitize_text_field($status)),
            array('id' => (int) $id),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Get a list of popular countries and their dialing codes, merged with custom entries.
     */
    public static function kdwn_get_countries_list() {
        $default_countries = array(
            'GE' => array('name' => 'Georgia', 'code' => '+995'),
            'US' => array('name' => 'United States', 'code' => '+1'),
            'CA' => array('name' => 'Canada', 'code' => '+1'),
            'GB' => array('name' => 'United Kingdom', 'code' => '+44'),
            'DE' => array('name' => 'Germany', 'code' => '+49'),
            'FR' => array('name' => 'France', 'code' => '+33'),
            'TR' => array('name' => 'Turkey', 'code' => '+90'),
            'UA' => array('name' => 'Ukraine', 'code' => '+380'),
            'AZ' => array('name' => 'Azerbaijan', 'code' => '+994'),
            'AM' => array('name' => 'Armenia', 'code' => '+374'),
            'IT' => array('name' => 'Italy', 'code' => '+39'),
            'ES' => array('name' => 'Spain', 'code' => '+34'),
            'PL' => array('name' => 'Poland', 'code' => '+48'),
            'NL' => array('name' => 'Netherlands', 'code' => '+31'),
            'GR' => array('name' => 'Greece', 'code' => '+30'),
            'IL' => array('name' => 'Israel', 'code' => '+972'),
            'IN' => array('name' => 'India', 'code' => '+91'),
            'CN' => array('name' => 'China', 'code' => '+86'),
            'AU' => array('name' => 'Australia', 'code' => '+61'),
            'AE' => array('name' => 'United Arab Emirates', 'code' => '+971'),
            'BR' => array('name' => 'Brazil', 'code' => '+55'),
            'KZ' => array('name' => 'Kazakhstan', 'code' => '+7'),
            'LT' => array('name' => 'Lithuania', 'code' => '+370'),
            'LV' => array('name' => 'Latvia', 'code' => '+371'),
            'EE' => array('name' => 'Estonia', 'code' => '+372'),
            'BE' => array('name' => 'Belgium', 'code' => '+32'),
            'CH' => array('name' => 'Switzerland', 'code' => '+41'),
            'SE' => array('name' => 'Sweden', 'code' => '+46'),
            'NO' => array('name' => 'Norway', 'code' => '+47'),
            'FI' => array('name' => 'Finland', 'code' => '+358'),
            'DK' => array('name' => 'Denmark', 'code' => '+45'),
            'AT' => array('name' => 'Austria', 'code' => '+43'),
            'PT' => array('name' => 'Portugal', 'code' => '+351'),
            'CZ' => array('name' => 'Czech Republic', 'code' => '+420'),
            'HU' => array('name' => 'Hungary', 'code' => '+36'),
            'RO' => array('name' => 'Romania', 'code' => '+40'),
            'BG' => array('name' => 'Bulgaria', 'code' => '+359'),
            'IE' => array('name' => 'Ireland', 'code' => '+353'),
            'NZ' => array('name' => 'New Zealand', 'code' => '+64'),
            'JP' => array('name' => 'Japan', 'code' => '+81'),
            'KR' => array('name' => 'South Korea', 'code' => '+82'),
            'SG' => array('name' => 'Singapore', 'code' => '+65'),
            'ZA' => array('name' => 'South Africa', 'code' => '+27'),
            'MX' => array('name' => 'Mexico', 'code' => '+52'),
            'AR' => array('name' => 'Argentina', 'code' => '+54'),
        );

        $custom_countries = get_option('kdwn_custom_countries', array());
        if (is_array($custom_countries) && !empty($custom_countries)) {
            foreach ($custom_countries as $key => $country) {
                if (isset($country['name']) && isset($country['code'])) {
                    $default_countries[$key] = array(
                        'name' => sanitize_text_field($country['name']),
                        'code' => sanitize_text_field($country['code'])
                    );
                }
            }
        }

        return $default_countries;
    }

    /**
     * Get customizable form labels and content texts or their defaults.
     */
    public static function kdwn_get_form_texts($service_id = 1) {
        $defaults = array(
            'form_title'        => __('Get Early Access', 'khvichadev-waitlist-notify'),
            'form_subtitle'     => __('Pre-register now to secure your spot and receive exclusive updates when we launch.', 'khvichadev-waitlist-notify'),
            'name_label'        => __('Full Name', 'khvichadev-waitlist-notify'),
            'email_label'       => __('Email Address', 'khvichadev-waitlist-notify'),
            'phone_label'       => __('Phone Number', 'khvichadev-waitlist-notify'),
            'whatsapp_label'    => __('WhatsApp Number', 'khvichadev-waitlist-notify'),
            'submit_btn'        => __('Join Waitlist', 'khvichadev-waitlist-notify'),
            'success_title'     => __("You're on the list!", 'khvichadev-waitlist-notify'),
            'success_msg'       => __('Thank you! You have successfully signed up.', 'khvichadev-waitlist-notify'),
            'social_proof_text' => __('Joined by {count} subscribers', 'khvichadev-waitlist-notify'),
            'badge_label'       => __('Subscribers Joined', 'khvichadev-waitlist-notify'),
            'consent_label'     => __('I agree to receive launch notifications and updates.', 'khvichadev-waitlist-notify')
        );

        $saved = null;
        if ($service_id > 1) {
            $option_name = 'kdwn_form_texts_' . $service_id;
            $saved = get_option($option_name, null);
            if ($saved === null) {
                $saved = get_option('kdwn_form_texts', array());
            }
        } else {
            $saved = get_option('kdwn_form_texts', array());
        }

        if (is_array($saved)) {
            return array_merge($defaults, $saved);
        }

        return $defaults;
    }

    /**
     * Retrieve the list of registered services from options.
     */
    public static function kdwn_get_services() {
        $default_item = array(
            'id'          => 1,
            'name'        => __('Default Service', 'khvichadev-waitlist-notify'),
            'description' => __('Default subscriber list.', 'khvichadev-waitlist-notify')
        );

        $services = get_option('kdwn_services', array());
        if (!is_array($services)) {
            $services = array();
        }

        // If service 1 is missing, restore it automatically (self-healing)
        if (!isset($services[1])) {
            $services[1] = $default_item;
            ksort($services);
            update_option('kdwn_services', $services);
        }

        return $services;
    }

    /**
     * Register a new service.
     */
    public static function kdwn_add_service($name, $description = '') {
        $services = self::kdwn_get_services();
        $new_id = 1;
        if (!empty($services)) {
            $new_id = max(array_keys($services)) + 1;
        }
        $services[$new_id] = array(
            'id'          => $new_id,
            'name'        => sanitize_text_field($name),
            'description' => sanitize_text_field($description)
        );
        update_option('kdwn_services', $services);

        // Copy settings from service 1 (default service) to the new service
        $fields_config_1 = self::kdwn_get_fields_config(1);
        update_option('kdwn_fields_config_' . $new_id, $fields_config_1);

        $gateway_config_1 = self::kdwn_get_gateway_config(1);
        update_option('kdwn_gateway_config_' . $new_id, $gateway_config_1);

        $form_texts_1 = self::kdwn_get_form_texts(1);
        update_option('kdwn_form_texts_' . $new_id, $form_texts_1);

        return $services[$new_id];
    }

    /**
     * Delete a service, its configurations and all associated subscribers.
     */
    public static function kdwn_delete_service($service_id) {
        $service_id = (int) $service_id;

        // Prevent deleting the default service (ID 1)
        if ($service_id <= 1) {
            return false;
        }

        $services = self::kdwn_get_services();
        if (isset($services[$service_id])) {
            unset($services[$service_id]);
            update_option('kdwn_services', $services);

            // Delete service-specific settings options
            delete_option('kdwn_fields_config_' . $service_id);
            delete_option('kdwn_gateway_config_' . $service_id);
            delete_option('kdwn_form_texts_' . $service_id);

            // Delete associated subscribers from database
            global $wpdb;
            $wpdb->delete(
                "{$wpdb->prefix}kdwn_subscribers",
                array('service_id' => $service_id),
                array('%d')
            );

            return true;
        }
        return false;
    }
}
