/**
 * Frontend script to handle signup form submission.
 * Dynamically gathers name, email, phone, and WhatsApp fields based on layout configurations.
 * Handles AJAX requests, local validation, and success screen animations.
 * Supports multiple instances on the same page by using context-specific class selectors.
 */
jQuery(document).ready(function($) {

    // Refresh nonce dynamically on page load to bypass caching plugins
    if (typeof kdwn_vars !== 'undefined') {
        $.ajax({
            url: kdwn_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'kdwn_refresh_signup_nonce'
            },
            success: function(response) {
                if (response && response.success && response.data.nonce) {
                    kdwn_vars.nonce = response.data.nonce;
                }
            }
        });
    }

    // Handle delegated form submission to support multiple forms on the same page
    $(document).on('submit', '.kd-signup-form', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $card = $form.closest('.kd-signup-card');
        var $submitBtn = $form.find('.kd-submit-btn');
        var $messageBox = $form.find('.kd-signup-message');
        var $formWrapper = $card.find('.kd-signup-form-wrapper');
        var $successWrapper = $card.find('.kd-signup-success-wrapper');
        var $successText = $card.find('.kd-success-message-text');

        /** Prevent runtime execution errors if the localization object is not populated on the page */
        if (typeof kdwn_vars === 'undefined') {
            kdwn_show_message($messageBox, 'System configuration error. Please refresh the page and try again.', 'error');
            return;
        }

        if ($submitBtn.hasClass('kd-loading')) {
            return;
        }

        /** Safely retrieve input values and trim whitespace after verifying DOM presence */
        var $nameField = $form.find('.kd-input-name');
        var name = $nameField.val() ? $nameField.val().trim() : '';
        
        var $hpField = $form.find('.kd-hp-email');
        var hpEmail = ($hpField.length && $hpField.val()) ? $hpField.val().trim() : '';

        var $emailField = $form.find('.kd-input-email');
        var email = ($emailField.length && $emailField.val()) ? $emailField.val().trim() : '';

        var $phoneField = $form.find('.kd-input-phone');
        var phone = ($phoneField.length && $phoneField.val()) ? $phoneField.val().trim() : '';
        if (phone) {
            phone = phone.replace(/\s+/g, '');
            // Check selected country code
            var phoneCode = $form.find('select[name="phone_country_code"]').val() || '';
            var cleanPhoneCode = phoneCode.replace(/\D/g, ''); // e.g. "995" from "+995"
            
            // Clean phone to see if it starts with the selected dialing code
            var cleanPhone = phone.replace(/\D/g, ''); // strip spaces, pluses, etc.
            
            // If the user already typed the dial code (e.g. they typed +995... or 995...),
            // we should not prefix it again.
            if (phone.indexOf('+') === 0) {
                // Keep it as-is
            } else if (cleanPhoneCode && cleanPhone.indexOf(cleanPhoneCode) === 0) {
                phone = '+' + cleanPhone;
            } else {
                phone = phone.replace(/^0+/, '');
                phone = phoneCode + phone;
            }
        }

        var $whatsappField = $form.find('.kd-input-whatsapp');
        var whatsapp = ($whatsappField.length && $whatsappField.val()) ? $whatsappField.val().trim() : '';
        if (whatsapp) {
            whatsapp = whatsapp.replace(/\s+/g, '');
            // Check selected country code
            var whatsappCode = $form.find('select[name="whatsapp_country_code"]').val() || '';
            var cleanPattern = whatsappCode.replace(/\D/g, '');
            
            var cleanWhatsapp = whatsapp.replace(/\D/g, '');
            
            // If the user already typed the dial code (e.g. they typed +995... or 995...),
            // we should not prefix it again.
            if (whatsapp.indexOf('+') === 0) {
                // Keep it as-is
            } else if (cleanPattern && cleanWhatsapp.indexOf(cleanPattern) === 0) {
                whatsapp = '+' + cleanWhatsapp;
            } else {
                whatsapp = whatsapp.replace(/^0+/, '');
                whatsapp = whatsappCode + whatsapp;
            }
        }

        // Local validation of required fields
        var validationError = '';

        if (!name) {
            validationError = 'Please enter your name.';
        }

        // Validate fields marked as required in HTML
        if (!validationError && $emailField.length && $emailField.prop('required') && !email) {
            validationError = 'Email address is required.';
        }
        if (!validationError && $phoneField.length && $phoneField.prop('required') && !phone) {
            validationError = 'Phone number is required.';
        }
        if (!validationError && $whatsappField.length && $whatsappField.prop('required') && !whatsapp) {
            validationError = 'WhatsApp number is required.';
        }

        var $consentField = $form.find('.kd-consent-checkbox');
        if (!validationError && $consentField.length && $consentField.prop('required') && !$consentField.is(':checked')) {
            validationError = 'You must agree to receive launch notifications.';
        }

        if (validationError) {
            kdwn_show_message($messageBox, validationError, 'error');
            return;
        }

        // Show spinner and disable inputs
        $messageBox.hide().removeClass('kd-success kd-error').html('');
        $submitBtn.addClass('kd-loading').prop('disabled', true);

        var $serviceField = $form.find('input[name="kdwn_service_id"]');
        var serviceId = $serviceField.length ? parseInt($serviceField.val(), 10) : 1;
        var isConsentChecked = $form.find('.kd-consent-checkbox').is(':checked') ? '1' : '0';

        // Submit registration data
        $.ajax({
            url: kdwn_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'kdwn_signup',
                nonce: kdwn_vars.nonce,
                name: name,
                email: email,
                phone: phone,
                whatsapp: whatsapp,
                service_id: serviceId,
                kdwn_hp_email: hpEmail,
                kdwn_notification_consent: isConsentChecked
            },
            success: function(response) {
                if (response && response.success) {
                    var successMsg = response.data.message || 'Thank you! You have successfully registered.';
                    $successText.text(successMsg);
                    
                    // Trigger success animation screen
                    $formWrapper.fadeOut(300, function() {
                        $successWrapper.fadeIn(350);
                    });
                } else {
                    var errorMsg = (response && response.data && response.data.message) 
                        ? response.data.message 
                        : 'An error occurred. Please try again.';
                    kdwn_show_message($messageBox, errorMsg, 'error');
                }
            },
            error: function() {
                kdwn_show_message($messageBox, 'Connection error. Please check your internet connection.', 'error');
            },
            complete: function() {
                $submitBtn.removeClass('kd-loading').prop('disabled', false);
            }
        });
    });

    /**
     * Helper to show messages inside the response box with inline SVGs (used for error alerts).
     */
    function kdwn_show_message($box, text, type) {
        var iconHtml = '';
        
        if (type === 'success') {
            iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
        } else {
            iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>';
        }
        
        $box.html(iconHtml + '<span></span>');
        $box.find('span').text(text);
        $box.addClass('kd-' + type).fadeIn(200);
    }
});
