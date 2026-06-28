<?php
/**
 * Backend AJAX handler for subscriber signup.
 * Validates requests, verifies nonces, and inserts subscriber records with custom fields.
 */

if (!defined('ABSPATH')) {
    exit;
}

class kdwn_Signup_Handler {
    /**
     * Set up hooks for processing AJAX requests.
     */
    public function __construct() {
        add_action('wp_ajax_nopriv_kdwn_signup', array($this, 'kdwn_handle_signup_request'));
        add_action('wp_ajax_kdwn_signup', array($this, 'kdwn_handle_signup_request'));
        add_action('wp_ajax_nopriv_kdwn_refresh_signup_nonce', array($this, 'kdwn_refresh_signup_nonce'));
        add_action('wp_ajax_kdwn_refresh_signup_nonce', array($this, 'kdwn_refresh_signup_nonce'));
    }

    /**
     * Process the frontend AJAX signup request.
     */
    public function kdwn_handle_signup_request() {
        // Verify security nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'kdwn_signup_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed. Please refresh the page.', 'khvichadev-waitlist-notify')
            ));
        }

        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 1;

        // Honeypot spam bot check
        if (!empty($_POST['kdwn_hp_email'])) {
            $form_texts = kdwn_Database::kdwn_get_form_texts($service_id);
            wp_send_json_success(array(
                'message' => $form_texts['success_msg']
            ));
        }

        // Get fields configurations
        $fields_config = kdwn_Database::kdwn_get_fields_config($service_id);
        $email_enabled   = isset($fields_config['email']['enabled']) ? (bool) $fields_config['email']['enabled'] : true;
        $email_required  = isset($fields_config['email']['required']) ? (bool) $fields_config['email']['required'] : true;

        $phone_enabled   = isset($fields_config['phone']['enabled']) ? (bool) $fields_config['phone']['enabled'] : false;
        $phone_required  = isset($fields_config['phone']['required']) ? (bool) $fields_config['phone']['required'] : false;

        $whatsapp_enabled  = isset($fields_config['whatsapp']['enabled']) ? (bool) $fields_config['whatsapp']['enabled'] : false;
        $whatsapp_required = isset($fields_config['whatsapp']['required']) ? (bool) $fields_config['whatsapp']['required'] : false;
        $consent_enabled   = isset($fields_config['consent_enabled']) ? (bool) $fields_config['consent_enabled'] : false;

        // Validate Consent Checkbox if enabled
        if ($consent_enabled && (!isset($_POST['kdwn_notification_consent']) || $_POST['kdwn_notification_consent'] !== '1')) {
            wp_send_json_error(array('message' => __('You must agree to receive launch notifications.', 'khvichadev-waitlist-notify')));
        }

        // Validate and sanitize Name (always required)
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if (empty($name)) {
            wp_send_json_error(array('message' => __('Please enter your name.', 'khvichadev-waitlist-notify')));
        }
        if (mb_strlen($name) > 100) {
            wp_send_json_error(array('message' => __('Name cannot exceed 100 characters.', 'khvichadev-waitlist-notify')));
        }

        // Validate and sanitize Email
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if ($email_enabled) {
            if ($email_required && empty($email)) {
                wp_send_json_error(array('message' => __('Email address is required.', 'khvichadev-waitlist-notify')));
            }
            if (!empty($email) && !is_email($email)) {
                wp_send_json_error(array('message' => __('Please enter a valid email address.', 'khvichadev-waitlist-notify')));
            }
            if (mb_strlen($email) > 100) {
                wp_send_json_error(array('message' => __('Email address cannot exceed 100 characters.', 'khvichadev-waitlist-notify')));
            }
            if (!empty($email) && kdwn_Database::kdwn_subscriber_exists($email, $service_id)) {
                wp_send_json_error(array('message' => __('This email address is already registered.', 'khvichadev-waitlist-notify')));
            }
        } else {
            $email = ''; // Reset if not enabled
        }

        // Validate and sanitize Phone
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        if ($phone_enabled) {
            if ($phone_required && empty($phone)) {
                wp_send_json_error(array('message' => __('Phone number is required.', 'khvichadev-waitlist-notify')));
            }
            if (!empty($phone)) {
                $default_code = isset($fields_config['default_country_code']) ? $fields_config['default_country_code'] : '+995';
                $normalized_phone = $this->kdwn_normalize_phone($phone, $default_code);
                if ($normalized_phone === false) {
                    wp_send_json_error(array('message' => __('Please enter a valid phone number (e.g. +995599123456).', 'khvichadev-waitlist-notify')));
                }
                if (mb_strlen($normalized_phone) > 50) {
                    wp_send_json_error(array('message' => __('Phone number cannot exceed 50 characters.', 'khvichadev-waitlist-notify')));
                }
                $phone = $normalized_phone;
            }
            if (!empty($phone) && kdwn_Database::kdwn_subscriber_exists_by_phone($phone, $service_id)) {
                wp_send_json_error(array('message' => __('This phone number is already registered.', 'khvichadev-waitlist-notify')));
            }
        } else {
            $phone = ''; // Reset if not enabled
        }

        // Validate and sanitize WhatsApp
        $whatsapp = isset($_POST['whatsapp']) ? sanitize_text_field(wp_unslash($_POST['whatsapp'])) : '';
        if ($whatsapp_enabled) {
            if ($whatsapp_required && empty($whatsapp)) {
                wp_send_json_error(array('message' => __('WhatsApp number is required.', 'khvichadev-waitlist-notify')));
            }
            if (!empty($whatsapp)) {
                $default_code = isset($fields_config['default_country_code']) ? $fields_config['default_country_code'] : '+995';
                $normalized_whatsapp = $this->kdwn_normalize_phone($whatsapp, $default_code);
                if ($normalized_whatsapp === false) {
                    wp_send_json_error(array('message' => __('Please enter a valid WhatsApp number (e.g. +995599123456).', 'khvichadev-waitlist-notify')));
                }
                if (mb_strlen($normalized_whatsapp) > 50) {
                    wp_send_json_error(array('message' => __('WhatsApp number cannot exceed 50 characters.', 'khvichadev-waitlist-notify')));
                }
                $whatsapp = $normalized_whatsapp;
            }
            if (!empty($whatsapp) && kdwn_Database::kdwn_subscriber_exists_by_whatsapp($whatsapp, $service_id)) {
                wp_send_json_error(array('message' => __('This WhatsApp number is already registered.', 'khvichadev-waitlist-notify')));
            }
        } else {
            $whatsapp = ''; // Reset if not enabled
        }

        // Save new subscriber to the database
        $inserted = kdwn_Database::kdwn_add_subscriber($name, $email, $phone, $whatsapp, $service_id);

        if ($inserted) {
            $form_texts = kdwn_Database::kdwn_get_form_texts($service_id);
            wp_send_json_success(array(
                'message' => $form_texts['success_msg']
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to register. Please try again later.', 'khvichadev-waitlist-notify')
            ));
        }
    }

    /**
     * Refresh signup nonce dynamically to bypass page caching plugins.
     */
    public function kdwn_refresh_signup_nonce() {
        wp_send_json_success(array(
            'nonce' => wp_create_nonce('kdwn_signup_nonce')
        ));
    }

    /**
     * Normalize and validate a phone number according to E.164 format rules.
     *
     * @param string $phone Raw phone number.
     * @param string $default_code Default country code (e.g. '+995').
     * @return string|false Normalized phone number, or false if invalid.
     */
    private function kdwn_normalize_phone($phone, $default_code = '+995') {
        // Remove all characters except digits and plus sign
        $phone = preg_replace('/[^\d+]/', '', $phone);

        if (empty($phone)) {
            return '';
        }

        // If it doesn't start with '+', process default country code prefixing
        if (strpos($phone, '+') !== 0) {
            // Strip leading zeros
            $phone = ltrim($phone, '0');
            
            // Ensure default code starts with '+'
            if (strpos($default_code, '+') !== 0) {
                $default_code = '+' . $default_code;
            }
            $phone = $default_code . $phone;
        }

        // Validate E.164 format (plus followed by 7 to 15 digits)
        if (!preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
            return false;
        }

        return $phone;
    }
}
