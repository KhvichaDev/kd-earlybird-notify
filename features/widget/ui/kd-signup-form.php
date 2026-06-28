<?php
/**
 * Frontend signup form class.
 * Registers shortcode, enqueues resources, and renders HTML for the registration form.
 * Supports split views and dynamic fields configuration from admin options.
 */

if (!defined('ABSPATH')) {
    exit;
}

class kdwn_Signup_Form {
    /**
     * Set up hooks for shortcode registration and script enqueuing.
     */
    public function __construct() {
        add_shortcode('kdwn_signup', array($this, 'kdwn_render_signup_form'));
        add_shortcode('kdwn_subscriber_count', array($this, 'kdwn_render_subscriber_count'));
        add_action('wp_enqueue_scripts', array($this, 'kdwn_enqueue_signup_assets'));
    }

    /**
     * Enqueue CSS and JS assets for the signup form.
     */
    public function kdwn_enqueue_signup_assets() {
        // Enqueue Google Font - Outfit
        wp_enqueue_style(
            'kdwn-outfit-font',
            'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
            array(),
            KDWN_VERSION
        );

        // Enqueue local signup form styles
        wp_enqueue_style(
            'kdwn-signup-styles',
            KDWN_URL . 'features/widget/ui/kd-signup-form.css',
            array('kdwn-outfit-font'),
            KDWN_VERSION
        );

        // Enqueue local signup form behavior script
        wp_enqueue_script(
            'kdwn-signup-script',
            KDWN_URL . 'features/widget/ui/kd-signup-form.js',
            array('jquery'),
            KDWN_VERSION,
            true
        );

        // Localize AJAX parameters for the script
        wp_localize_script('kdwn-signup-script', 'kdwn_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('kdwn_signup_nonce')
        ));
    }

    /**
     * Render the signup form based on active fields configurations.
     */
    public function kdwn_render_signup_form($atts = array()) {
        // Parse attributes
        $atts = shortcode_atts(array(
            'service' => '1',
        ), $atts, 'kdwn_signup');

        $services = kdwn_Database::kdwn_get_services();
        $service_id = 1;

        if (is_numeric($atts['service'])) {
            $check_id = (int) $atts['service'];
            if (isset($services[$check_id])) {
                $service_id = $check_id;
            }
        } else {
            $search_name = trim(strtolower($atts['service']));
            foreach ($services as $s_id => $s_data) {
                if (strtolower($s_data['name']) === $search_name) {
                    $service_id = $s_id;
                    break;
                }
            }
        }

        // Retrieve custom form field configuration
        $fields_config = kdwn_Database::kdwn_get_fields_config($service_id);
        $form_texts    = kdwn_Database::kdwn_get_form_texts($service_id);

        $email_enabled   = isset($fields_config['email']['enabled']) ? (bool) $fields_config['email']['enabled'] : true;
        $email_required  = isset($fields_config['email']['required']) ? (bool) $fields_config['email']['required'] : true;

        $phone_enabled   = isset($fields_config['phone']['enabled']) ? (bool) $fields_config['phone']['enabled'] : false;
        $phone_required  = isset($fields_config['phone']['required']) ? (bool) $fields_config['phone']['required'] : false;

        $whatsapp_enabled  = isset($fields_config['whatsapp']['enabled']) ? (bool) $fields_config['whatsapp']['enabled'] : false;
        $whatsapp_required = isset($fields_config['whatsapp']['required']) ? (bool) $fields_config['whatsapp']['required'] : false;

        $show_subscriber_count = isset($fields_config['show_subscriber_count']) ? (bool) $fields_config['show_subscriber_count'] : false;
        $consent_enabled       = isset($fields_config['consent_enabled']) ? (bool) $fields_config['consent_enabled'] : false;

        // Count how many fields are enabled to make sure we render something
        $any_enabled = $email_enabled || $phone_enabled || $whatsapp_enabled;

        $countries = kdwn_Database::kdwn_get_countries_list();
        $default_code = isset($fields_config['default_country_code']) ? $fields_config['default_country_code'] : '+995';

        ob_start();
        ?>
        <div class="kd-signup-container">
            <div class="kd-signup-card">
                <!-- Initial Signup Form View -->
                <div class="kd-signup-form-wrapper">
                    <div class="kd-signup-header">
                        <h3 class="kd-signup-title"><?php echo esc_html($form_texts['form_title']); ?></h3>
                        <p class="kd-signup-subtitle"><?php echo esc_html($form_texts['form_subtitle']); ?></p>
                        <?php if ($show_subscriber_count) : 
                            $subscriber_count = (int) kdwn_Database::kdwn_get_subscribers_count('', '', $service_id);
                            $social_proof_raw = isset($form_texts['social_proof_text']) ? $form_texts['social_proof_text'] : __('Joined by {count} subscribers', 'khvichadev-waitlist-notify');
                            $count_html = '<strong class="kd-social-count">' . number_format($subscriber_count) . '</strong>';
                            $social_proof_html = str_replace('{count}', $count_html, esc_html($social_proof_raw));
                            ?>
                            <div class="kd-form-social-proof">
                                <div class="kd-avatar-stack">
                                    <div class="kd-avatar" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM3.751 20.105a8.25 8.25 0 0116.498 0 .75.75 0 01-.437.695A18.683 18.683 0 0112 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 01-.437-.695z" clip-rule="evenodd" /></svg>
                                    </div>
                                    <div class="kd-avatar" style="background: linear-gradient(135deg, #10b981, #059669);">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.007 5.404.433c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.433 2.082-5.006z" clip-rule="evenodd" /></svg>
                                    </div>
                                    <div class="kd-avatar" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M11.645 20.91l-.007-.003-.022-.012a15.247 15.247 0 01-.383-.218 25.18 25.18 0 01-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0112 5.052 5.5 5.5 0 0116.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 01-4.244 3.17 15.247 15.247 0 01-.383.219l-.022.012-.007.004-.003.001a.752.752 0 01-.704 0l-.003-.001z" /></svg>
                                    </div>
                                </div>
                                <span class="kd-social-text">
                                    <?php
                                    echo wp_kses(
                                        $social_proof_html,
                                        array(
                                            'strong' => array(
                                                'class' => array(),
                                              ),
                                        )
                                    );
                                    ?>
                                </span>
                             </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!$any_enabled) : ?>
                        <div class="kd-message kd-error">
                            <span><?php esc_html_e('Warning: No signup fields have been configured by the admin yet.', 'khvichadev-waitlist-notify'); ?></span>
                        </div>
                    <?php else : ?>
                        <form class="kd-signup-form">
                            <input type="hidden" name="kdwn_service_id" value="<?php echo (int) $service_id; ?>" />
                            
                            <!-- Honeypot field for spam bot protection -->
                            <div class="kd-hp-field" style="display:none !important; visibility:hidden !important; width:0 !important; height:0 !important; overflow:hidden !important;" aria-hidden="true">
                                <label><?php esc_html_e('Confirm Email Address', 'khvichadev-waitlist-notify'); ?></label>
                                <input type="text" name="kdwn_hp_email" class="kd-hp-email" autocomplete="off" tabindex="-1" />
                            </div>

                            <!-- Name is always required -->
                            <div class="kd-form-group">
                                <input type="text" name="subscriber_name" class="kd-input kd-input-name" placeholder=" " required autocomplete="name" />
                                <label class="kd-label"><?php echo esc_html($form_texts['name_label']); ?></label>
                            </div>

                            <!-- Email Field -->
                            <?php if ($email_enabled) : ?>
                                <div class="kd-form-group">
                                    <input type="email" name="subscriber_email" class="kd-input kd-input-email" placeholder=" " <?php echo $email_required ? 'required' : ''; ?> autocomplete="email" />
                                    <label class="kd-label"><?php echo esc_html($form_texts['email_label'] . ($email_required ? ' *' : '')); ?></label>
                                </div>
                            <?php endif; ?>

                            <!-- Phone Field with Country Code Selection -->
                            <?php if ($phone_enabled) : ?>
                                <div class="kd-phone-input-group">
                                    <select name="phone_country_code" class="kd-country-select">
                                        <?php foreach ($countries as $c_key => $c_data) : ?>
                                            <option value="<?php echo esc_attr($c_data['code']); ?>" <?php selected($c_data['code'], $default_code); ?>>
                                                <?php echo esc_html($c_key . ' (' . $c_data['code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="tel" name="subscriber_phone" class="kd-input kd-phone-field kd-input-phone" placeholder=" " <?php echo $phone_required ? 'required' : ''; ?> autocomplete="tel" />
                                    <label class="kd-label kd-phone-label"><?php echo esc_html($form_texts['phone_label'] . ($phone_required ? ' *' : '')); ?></label>
                                </div>
                            <?php endif; ?>

                            <!-- WhatsApp Field with Country Code Selection -->
                            <?php if ($whatsapp_enabled) : ?>
                                <div class="kd-phone-input-group">
                                    <select name="whatsapp_country_code" class="kd-country-select">
                                        <?php foreach ($countries as $c_key => $c_data) : ?>
                                            <option value="<?php echo esc_attr($c_data['code']); ?>" <?php selected($c_data['code'], $default_code); ?>>
                                                <?php echo esc_html($c_key . ' (' . $c_data['code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="tel" name="subscriber_whatsapp" class="kd-input kd-phone-field kd-input-whatsapp" placeholder=" " <?php echo $whatsapp_required ? 'required' : ''; ?> />
                                    <label class="kd-label kd-phone-label"><?php echo esc_html($form_texts['whatsapp_label'] . ($whatsapp_required ? ' *' : '')); ?></label>
                                </div>
                            <?php endif; ?>

                            <?php if ($consent_enabled) : ?>
                                <div class="kd-consent-group">
                                    <input type="checkbox" name="kdwn_notification_consent" id="kdwn_notification_consent" class="kd-consent-checkbox" value="1" required />
                                    <label for="kdwn_notification_consent" class="kd-consent-label">
                                        <?php echo esc_html($form_texts['consent_label']); ?> <span class="kd-consent-required">*</span>
                                    </label>
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="kd-submit-btn">
                                <span class="kd-btn-text"><?php echo esc_html($form_texts['submit_btn']); ?></span>
                                <span class="kd-spinner"></span>
                            </button>

                            <div class="kd-message kd-signup-message" style="display: none;"></div>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Success Screen View (Hidden by default) -->
                <div class="kd-signup-success-wrapper" style="display: none;">
                    <div class="kd-success-icon-box">
                        <svg class="kd-checkmark-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
                            <circle class="kd-checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
                            <path class="kd-checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
                        </svg>
                    </div>
                    <h3 class="kd-success-title"><?php echo esc_html($form_texts['success_title']); ?></h3>
                    <p class="kd-success-message kd-success-message-text"><?php echo esc_html($form_texts['success_msg']); ?></p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the subscriber count for a specific service.
     */
    public function kdwn_render_subscriber_count($atts = array()) {
        // Parse attributes
        $atts = shortcode_atts(array(
            'service' => '1',
            'format'  => 'badge',
            'label'   => '',
        ), $atts, 'kdwn_subscriber_count');

        $services = kdwn_Database::kdwn_get_services();
        $service_id = 1;

        if (is_numeric($atts['service'])) {
            $check_id = (int) $atts['service'];
            if (isset($services[$check_id])) {
                $service_id = $check_id;
            }
        } else {
            $search_name = trim(strtolower($atts['service']));
            foreach ($services as $s_id => $s_data) {
                if (strtolower($s_data['name']) === $search_name) {
                    $service_id = $s_id;
                    break;
                }
            }
        }

        $subscriber_count = (int) kdwn_Database::kdwn_get_subscribers_count('', '', $service_id);

        if ($atts['format'] === 'raw') {
            return absint($subscriber_count);
        }

        $form_texts = kdwn_Database::kdwn_get_form_texts($service_id);
        $label = !empty($atts['label']) ? $atts['label'] : (isset($form_texts['badge_label']) ? $form_texts['badge_label'] : __('Subscribers Joined', 'khvichadev-waitlist-notify'));

        ob_start();
        ?>
        <div class="kd-subscriber-count-badge">
            <div class="kd-badge-glow"></div>
            <div class="kd-badge-pulse-container">
                <span class="kd-badge-pulse-ring"></span>
                <span class="kd-badge-pulse-dot"></span>
            </div>
            <div class="kd-badge-text-container">
                <span class="kd-badge-number"><?php echo esc_html(number_format($subscriber_count)); ?></span>
                <span class="kd-badge-label"><?php echo esc_html($label); ?></span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
