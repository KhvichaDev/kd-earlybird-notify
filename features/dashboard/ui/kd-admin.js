/**
 * Admin dashboard script to control tab switching, subscriber deletions,
 * settings management, and the AJAX-driven recursive batch campaigns.
 * Controls both automated sends (Email/Twilio) and manual device queue sends (SMS/WhatsApp Web).
 */
jQuery(document).ready(function($) {
    
    var activeServiceId = $('.kd-admin-wrap').data('service-id') || 1;
    
    // ==========================================
    // 1. TABS NAVIGATION CONTROL
    // ==========================================
    $('.kd-nav-tab-wrapper .nav-tab').on('click', function(e) {
        e.preventDefault();
        
        var targetTabId = $(this).attr('href');
        
        // Toggle tab highlights
        $('.kd-nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Toggle content visibility
        $('.kd-tab-content').removeClass('kd-tab-content-active');
        $(targetTabId).addClass('kd-tab-content-active');
    });

    // Hash change routing
    var hash = window.location.hash;
    if (hash && $(hash).length) {
        $('.kd-nav-tab-wrapper .nav-tab[href="' + hash + '"]').trigger('click');
    }

    // ==========================================
    // 2. DELETE SUBSCRIBER
    // ==========================================
    $('.kd-delete-btn').on('click', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        
        if (!id) return;
        
        KDNotification.show({
            type: "warning",
            title: "Delete Subscriber?",
            message: "Are you sure you want to delete this subscriber? This action cannot be undone.",
            position: "center",
            isModal: true,
            buttons: [
                { text: "Yes, Delete", className: "kd-btn-danger", value: "confirm" },
                { text: "Cancel", value: "cancel" }
            ]
        }).then(function(result) {
            if (result !== "confirm") return;

            $btn.prop('disabled', true).text('Deleting...');

            $.ajax({
                url: kdwn_admin_vars.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'kdwn_delete_subscriber',
                    nonce: kdwn_admin_vars.nonce,
                    id: id
                },
                success: function(response) {
                    if (response && response.success) {
                        var $row = $('#kd-subscriber-row-' + id);
                        var status = 'subscribed';
                        if ($row.find('.kd-status-badge').hasClass('kd-badge-notified')) {
                            status = 'notified';
                        } else if ($row.find('.kd-status-badge').hasClass('kd-badge-failed')) {
                            status = 'failed';
                        }

                        $row.fadeOut(300, function() {
                            $(this).remove();
                            if ($('.kd-data-table tbody tr').length === 0) {
                                $('.kd-data-table tbody').html('<tr><td colspan="7" style="text-align: center; padding: 2rem;">No subscribers found.</td></tr>');
                                $('.kd-pagination').remove();
                            }
                        });
                        
                        kdwn_update_stats_display(-1, status);
                    } else {
                        KDNotification.show({
                            type: "error",
                            message: response.data.message || 'Could not delete subscriber.',
                            position: "center"
                        });
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Delete');
                    }
                },
                error: function() {
                    KDNotification.show({
                        type: "error",
                        message: "Connection error. Please try again.",
                        position: "center"
                    });
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Delete');
                }
            });
        });
    });

    // ==========================================
    // 3. RESET STATUS (NOTIFIED TO SUBSCRIBED)
    // ==========================================
    $('#kd-reset-status-btn').on('click', function() {
        var $btn = $(this);
        
        KDNotification.show({
            type: "warning",
            title: "Reset Status?",
            message: "This will change all 'Notified' subscribers back to 'Subscribed' so you can send them notifications again. Proceed?",
            position: "center",
            isModal: true,
            buttons: [
                { text: "Yes, Reset", className: "kd-btn-warning", value: "confirm" },
                { text: "Cancel", value: "cancel" }
            ]
        }).then(function(result) {
            if (result !== "confirm") return;

            $btn.prop('disabled', true).text('Resetting...');

            $.ajax({
                url: kdwn_admin_vars.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'kdwn_reset_subscribers_status',
                    nonce: kdwn_admin_vars.nonce,
                    service_id: activeServiceId
                },
                success: function(response) {
                    if (response && response.success) {
                        KDNotification.show({
                            type: "success",
                            message: response.data.message || 'Subscribers reset successfully.',
                            position: "center",
                            duration: 1500
                        }).then(function() {
                            // Update all notified and failed status badges to subscribed dynamically
                            $('.kd-status-badge.kd-badge-notified, .kd-status-badge.kd-badge-failed')
                                .removeClass('kd-badge-notified kd-badge-failed')
                                .addClass('kd-badge-subscribed')
                                .text('Subscribed');

                            // Recalculate stats panel numbers
                            var resetCount = parseInt(response.data.count, 10) || 0;
                            var $subscribedEl = $('#kd-count-subscribed');
                            var $notifiedEl = $('#kd-count-notified');
                            var $failedEl = $('#kd-count-failed');
                            if ($subscribedEl.length) {
                                var currentSub = parseInt($subscribedEl.text(), 10) || 0;
                                $subscribedEl.text(currentSub + resetCount);
                            }
                            if ($notifiedEl.length) {
                                $notifiedEl.text(0);
                            }
                            if ($failedEl.length) {
                                $failedEl.text(0);
                            }

                            // Fade out the reset button as no notified subscribers remain
                            $('#kd-reset-status-btn').fadeOut(200);
                        });
                    } else {
                        KDNotification.show({
                            type: "error",
                            message: response.data.message || 'Failed to reset subscriber status.',
                            position: "center"
                        });
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-image-rotate"></span> Reset Notified to Subscribed');
                    }
                },
                error: function() {
                    KDNotification.show({
                        type: "error",
                        message: "Connection error. Please try again.",
                        position: "center"
                    });
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-image-rotate"></span> Reset Notified to Subscribed');
                }
            });
        });
    });

    // ==========================================
    // 4. CAMPAIGN CHANNEL DYNAMIC SWITCHING
    // ==========================================
    var $channelSelect = $('#kd-campaign-channel');
    var $subjectGroup = $('#kd-subject-group');
    var $batchGroup = $('#kd-batch-size-group');
    var $messageLabel = $('#kd-message-label');
    var $disclaimer = $('#kd-channel-disclaimer');

    if ($channelSelect.length) {
        $channelSelect.on('change', function() {
            var channel = $(this).val();
            var $subjectInput = $('#kd-campaign-subject');
            
            if (channel === 'email') {
                $subjectGroup.slideDown(200);
                $subjectInput.prop('required', true);
                $batchGroup.slideDown(200);
                $messageLabel.text('Email Body (HTML supported)');
                $disclaimer.html('Email campaigns send using standard WordPress mailers. Configure an SMTP plugin to improve inbox delivery rates.');
            } else {
                $subjectGroup.slideUp(200);
                $subjectInput.prop('required', false).val(''); // Clear and remove HTML5 validation requirement
                
                if (channel === 'sms') {
                    $batchGroup.slideDown(200);
                    $messageLabel.text('SMS Message (Plain text only, tags supported)');
                    $disclaimer.html('SMS campaigns send using Twilio SMS gateway. Make sure optional credentials and Sender Number are configured in <b>Form & Gateway Settings</b>.');
                } else if (channel === 'whatsapp') {
                    $batchGroup.slideDown(200);
                    $messageLabel.text('WhatsApp Message (Plain text only, tags supported)');
                    $disclaimer.html('WhatsApp campaigns send using Twilio WhatsApp API. Make sure optional credentials and Sender WhatsApp Number are configured in <b>Form & Gateway Settings</b>.');
                } else if (channel === 'custom_sms') {
                    $batchGroup.slideDown(200);
                    $messageLabel.text('SMS Message (Plain text only, tags supported)');
                    $disclaimer.html('SMS campaigns send automatically using your Custom HTTP Gateway URL. Make sure the gateway is configured in <b>Form & Gateway Settings</b>.');
                } else if (channel === 'custom_whatsapp') {
                    $batchGroup.slideDown(200);
                    $messageLabel.text('WhatsApp Message (Plain text only, tags supported)');
                    $disclaimer.html('WhatsApp campaigns send automatically using your Custom WhatsApp Gateway URL. Make sure the gateway is configured in <b>Form & Gateway Settings</b>.');
                } else if (channel === 'manual_sms') {
                    $batchGroup.slideUp(200);
                    $messageLabel.text('SMS Message (Plain text only, tags supported)');
                    $disclaimer.html('SMS campaigns send using your device\'s native app. Clicking <b>Send</b> opens a local SMS trigger with pre-filled content. Completely free!');
                } else if (channel === 'manual_whatsapp') {
                    $batchGroup.slideUp(200);
                    $messageLabel.text('WhatsApp Message (Plain text only, tags supported)');
                    $disclaimer.html('WhatsApp campaigns send using WhatsApp Web. Clicking <b>Send</b> opens a WhatsApp Web chat popup. Completely free!');
                } else if (channel === 'manual_whatsapp_app') {
                    $batchGroup.slideUp(200);
                    $messageLabel.text('WhatsApp Message (Plain text only, tags supported)');
                    $disclaimer.html('WhatsApp campaigns send using the WhatsApp Desktop App. Clicking <b>Send</b> triggers the desktop application with pre-filled chat content without page reload latency. Completely free and extremely fast!');
                }
            }
        }).trigger('change');
    }

    // ==========================================
    // 5. BATCH CAMPAIGN DELIVERY (AJAX FLOW)
    // ==========================================
    var $campaignForm = $('#kd-campaign-form');
    var $modal = $('#kd-campaign-modal');
    var $progressBar = $('#kd-progress-bar-fill');
    var $progressPct = $('#kd-progress-percentage');
    var $progressRatio = $('#kd-progress-ratio');
    var $logBody = $('#kd-campaign-log');
    
    var $autoView = $('#kd-auto-campaign-view');
    var $manualView = $('#kd-manual-campaign-view');
    var $closeModalBtn = $('#kd-close-modal-btn');

    var channel = 'email';
    var totalToNotify = 0;
    var totalNotifiedSoFar = 0;
    var totalProcessedSoFar = 0;
    var subject = '';
    var message = '';
    var batchSize = 15;

    // Manual queue state variables
    var currentSubId = 0;
    var currentSubNumber = '';
    var currentSubMessage = '';
    var kdwn_skipped_sub_ids = [];

    $campaignForm.on('submit', function(e) {
        e.preventDefault();

        channel = $channelSelect.val();
        subject = $('#kd-campaign-subject').val().trim();
        message = $('#kd-campaign-message').val().trim();
        batchSize = parseInt($('#kd-campaign-batch-size').val(), 10);

        if (!message || (channel === 'email' && !subject)) {
            KDNotification.show({
                type: "warning",
                message: "Please fill out all required fields.",
                position: "center",
                duration: 3000
            });
            return;
        }

        var channelName = channel.includes('manual') ? 'Manual' : 'Automated';
        KDNotification.show({
            type: "warning",
            title: "Start Broadcast Campaign?",
            message: "Start campaign using " + $channelSelect.find('option:selected').text() + "? Please keep this window open until finished.",
            position: "center",
            isModal: true,
            buttons: [
                { text: "Start Campaign", className: "kd-btn-primary", value: "confirm" },
                { text: "Cancel", value: "cancel" }
            ]
        }).then(function(result) {
            if (result !== "confirm") return;

            // Reset overlays and toggle layouts
            $closeModalBtn.hide();
            
            if (channel.includes('manual')) {
                $autoView.hide();
                $manualView.show();
                kdwn_skipped_sub_ids = []; // Reset skipped tracker
                kdwn_load_manual_stats();
            } else {
                $manualView.hide();
                $autoView.show();
                kdwn_load_auto_stats();
            }

            $modal.fadeIn(200);
        });
    });

    /**
     * Stats load for automated campaign
     */
    function kdwn_load_auto_stats() {
        $logBody.html('<p class="kd-log-info">Querying subscribers database...</p>');
        $progressBar.css('width', '0%');
        $progressPct.text('0%');
        $progressRatio.text('0 / 0');
        totalNotifiedSoFar = 0;
        totalProcessedSoFar = 0;

        $.ajax({
            url: kdwn_admin_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'kdwn_get_campaign_stats',
                nonce: kdwn_admin_vars.nonce,
                channel: channel,
                service_id: activeServiceId
            },
            success: function(response) {
                if (response && response.success) {
                    totalToNotify = parseInt(response.data.total_to_notify, 10);
                    
                    if (totalToNotify <= 0) {
                        $logBody.append('<p class="kd-log-error">No users with registered contacts were found for this channel.</p>');
                        $closeModalBtn.show();
                        return;
                    }

                    $logBody.append('<p class="kd-log-info">Found ' + totalToNotify + ' subscribers to notify. Starting batch queue...</p>');
                    $progressRatio.text('0 / ' + totalToNotify);

                    kdwn_send_next_batch();
                } else {
                    $logBody.append('<p class="kd-log-error">Could not retrieve stats: ' + (response.data.message || 'Unknown error') + '</p>');
                    $closeModalBtn.show();
                }
            },
            error: function() {
                $logBody.append('<p class="kd-log-error">Connection failed while retrieving statistics.</p>');
                $closeModalBtn.show();
            }
        });
    }

    /**
     * Stats load for manual campaign queue
     */
    function kdwn_load_manual_stats() {
        $('#kd-manual-sub-name').text('Loading next subscriber...');
        $('#kd-manual-sub-number').text('—');
        $('#kd-manual-message-preview').text('—');
        $('#kd-manual-progress-ratio').text('0 / 0');
        
        totalNotifiedSoFar = 0;

        $.ajax({
            url: kdwn_admin_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'kdwn_get_campaign_stats',
                nonce: kdwn_admin_vars.nonce,
                channel: channel,
                service_id: activeServiceId
            },
            success: function(response) {
                if (response && response.success) {
                    totalToNotify = parseInt(response.data.total_to_notify, 10);
                    
                    if (totalToNotify <= 0) {
                        $('#kd-manual-sub-name').text('Queue Completed!');
                        $('#kd-manual-sub-number').text('No pending subscribers found.');
                        $('#kd-manual-message-preview').text('');
                        $closeModalBtn.show();
                        return;
                    }

                    $('#kd-manual-progress-ratio').text(totalToNotify + ' remaining');
                    kdwn_load_next_manual_subscriber();
                } else {
                    KDNotification.show({
                        type: "error",
                        message: "Could not retrieve statistics: " + (response.data.message || "Unknown error"),
                        position: "center",
                        duration: 4000
                    });
                    $closeModalBtn.show();
                }
            },
            error: function() {
                KDNotification.show({
                    type: "error",
                    message: "Connection failed while retrieving campaign statistics.",
                    position: "center",
                    duration: 4000
                });
                $closeModalBtn.show();
            }
        });
    }

    /**
     * Load the next pending subscriber details in manual queue
     */
    function kdwn_load_next_manual_subscriber() {
        $('#kd-manual-send-btn, #kd-manual-skip-btn, #kd-manual-mark-btn').prop('disabled', true);
        
        $.ajax({
            url: kdwn_admin_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'kdwn_get_next_pending_subscriber',
                nonce: kdwn_admin_vars.nonce,
                channel: channel,
                service_id: activeServiceId,
                exclude_ids: kdwn_skipped_sub_ids
            },
            success: function(response) {
                if (response && response.success) {
                    var data = response.data;
                    
                    if (data.finished) {
                        $('#kd-manual-sub-name').text('Queue Completed!');
                        $('#kd-manual-sub-number').text('All subscribers have been notified.');
                        $('#kd-manual-message-preview').html('<i style="color: #64748b;">No more messages to preview.</i>');
                        $('#kd-manual-progress-ratio').text('0 remaining');
                        
                        $closeModalBtn.show();
                        kdwn_refresh_ui_counters(totalNotifiedSoFar, totalNotifiedSoFar, 0);
                    } else {
                        currentSubId = data.id;
                        currentSubNumber = (channel === 'manual_whatsapp' || channel === 'manual_whatsapp_app') ? data.whatsapp : data.phone;
                        
                        // Personalize placeholders in text
                        currentSubMessage = message.replace(/{name}/g, data.name)
                                                   .replace(/{email}/g, data.email);

                        $('#kd-manual-sub-name').text(data.name);
                        $('#kd-manual-sub-number').text(currentSubNumber);
                        $('#kd-manual-message-preview').text(currentSubMessage);
                        
                        // Count remaining items in queue
                        var remaining = totalToNotify - totalNotifiedSoFar;
                        $('#kd-manual-progress-ratio').text(remaining + ' remaining');
                        
                        $('#kd-manual-send-btn, #kd-manual-skip-btn, #kd-manual-mark-btn').prop('disabled', false);
                    }
                } else {
                    KDNotification.show({
                        type: "error",
                        message: "Queue error: " + (response.data.message || "Could not fetch next subscriber."),
                        position: "center",
                        duration: 4000
                    });
                    $closeModalBtn.show();
                }
            },
            error: function() {
                KDNotification.show({
                    type: "error",
                    message: "Connection timeout. Failed to fetch next subscriber.",
                    position: "center",
                    duration: 4000
                });
                $closeModalBtn.show();
            }
        });
    }

    /**
     * Recursively sends campaign notifications in chunks.
     */
    function kdwn_send_next_batch() {
        $.ajax({
            url: kdwn_admin_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'kdwn_send_batch_emails',
                nonce: kdwn_admin_vars.nonce,
                channel: channel,
                subject: subject,
                message: message,
                batch_size: batchSize,
                service_id: activeServiceId
            },
            success: function(response) {
                if (response && response.success) {
                    var data = response.data;
                    
                    if (data.processed > 0) {
                        totalNotifiedSoFar += data.sent;
                        totalProcessedSoFar += data.processed;
                        
                        // Output logs to console
                        if (data.emails && data.emails.length) {
                            $.each(data.emails, function(index, email) {
                                $logBody.append('<p class="kd-log-success">Notified contact: ' + email + '</p>');
                            });
                        }
                        
                        if (data.failed_emails && data.failed_emails.length) {
                            $.each(data.failed_emails, function(index, item) {
                                var errText = (item.error) ? ' (' + item.error + ')' : '';
                                $logBody.append('<p class="kd-log-error">Delivery failed: ' + item.target + errText + '</p>');
                            });
                        }

                        // Update status badges of notified/failed subscribers dynamically in the background table
                        if (data.notified_ids) {
                            $.each(data.notified_ids, function(id, status) {
                                var $row = $('#kd-subscriber-row-' + id);
                                if ($row.length) {
                                    var badgeClass = (status === 'failed') ? 'kd-badge-failed' : 'kd-badge-notified';
                                    var badgeText = (status === 'failed') ? 'Failed' : 'Notified';
                                    $row.find('.kd-status-badge')
                                        .removeClass('kd-badge-subscribed kd-badge-notified kd-badge-failed')
                                        .addClass(badgeClass)
                                        .text(badgeText);
                                }
                            });
                        }

                        $logBody.scrollTop($logBody[0].scrollHeight);

                        // Update progress bar
                        var pct = Math.min(100, Math.round((totalProcessedSoFar / totalToNotify) * 100));
                        $progressBar.css('width', pct + '%');
                        $progressPct.text(pct + '%');
                        $progressRatio.text(totalProcessedSoFar + ' / ' + totalToNotify);
                    }

                    if (data.finished || totalProcessedSoFar >= totalToNotify) {
                        $logBody.append('<p class="kd-log-info"><b>Campaign complete! Successfully notified all eligible subscribers.</b></p>');
                        $logBody.scrollTop($logBody[0].scrollHeight);
                        $closeModalBtn.show();

                        // Refresh local stats indicators on screen
                        kdwn_refresh_ui_counters(totalProcessedSoFar, totalNotifiedSoFar, totalProcessedSoFar - totalNotifiedSoFar);
                    } else {
                        kdwn_send_next_batch();
                    }
                } else {
                    $logBody.append('<p class="kd-log-error">Batch error: ' + (response.data.message || 'Unknown error') + '. Retrying in 2 seconds...</p>');
                    $logBody.scrollTop($logBody[0].scrollHeight);
                    setTimeout(kdwn_send_next_batch, 2000);
                }
            },
            error: function() {
                $logBody.append('<p class="kd-log-error">Connection timeout. Retrying batch in 3 seconds...</p>');
                $logBody.scrollTop($logBody[0].scrollHeight);
                setTimeout(kdwn_send_next_batch, 3000);
            }
        });
    }

    // Modal closer
    $closeModalBtn.on('click', function() {
        $modal.fadeOut(200);
    });

    // ==========================================
    // 6. BIND MANUAL ACTIONS (FREE CHANNELS)
    // ==========================================
    
    // Open application and send message link
    $('#kd-manual-send-btn').on('click', function() {
        if (!currentSubNumber) {
            KDNotification.show({
                type: "warning",
                message: "This subscriber does not have a contact number registered.",
                position: "center"
            });
            return;
        }

        var link = '';
        var windowName = '_blank';
        var windowFeatures = '';

        if (channel === 'manual_sms') {
            /** Use location.href to launch native SMS application directly without opening blank tabs and check user agent to select correct parameter separator for iOS */
            var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            var separator = isIOS ? '&' : '?';
            link = "sms:" + currentSubNumber + separator + "body=" + encodeURIComponent(currentSubMessage);
            window.location.href = link;
        } else if (channel === 'manual_whatsapp') {
            /** Strip formatting characters to comply with WhatsApp Web API rules */
            var cleanNumber = currentSubNumber.replace(/\D/g, '');
            link = "https://web.whatsapp.com/send?phone=" + cleanNumber + "&text=" + encodeURIComponent(currentSubMessage);
            windowName = 'kdwn_whatsapp_send_popup';
            windowFeatures = 'width=950,height=750,status=no,titlebar=no,menubar=no,resizable=yes,scrollbars=yes';
            window.open(link, windowName, windowFeatures);
        } else if (channel === 'manual_whatsapp_app') {
            /** Launch native desktop app protocol to avoid reloading WhatsApp Web client */
            var cleanNumber = currentSubNumber.replace(/\D/g, '');
            link = "whatsapp://send?phone=" + cleanNumber + "&text=" + encodeURIComponent(currentSubMessage);
            window.location.href = link;
        }
    });

    // Mark as notified without opening app
    $('#kd-manual-mark-btn').on('click', function() {
        kdwn_mark_current_subscriber_notified();
    });

    // Skip current subscriber
    $('#kd-manual-skip-btn').on('click', function() {
        // Exclude this subscriber ID from the next query
        if (currentSubId) {
            kdwn_skipped_sub_ids.push(currentSubId);
        }
        kdwn_load_next_manual_subscriber();
    });

    /**
     * AJAX utility to mark active manual subscriber notified.
     */
    function kdwn_mark_current_subscriber_notified() {
        $('#kd-manual-send-btn, #kd-manual-skip-btn, #kd-manual-mark-btn').prop('disabled', true);

        $.ajax({
            url: kdwn_admin_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'kdwn_mark_subscriber_notified',
                nonce: kdwn_admin_vars.nonce,
                id: currentSubId
            },
            success: function(response) {
                if (response && response.success) {
                    // Update status badge of manual notified subscriber dynamically in the list table
                    var $row = $('#kd-subscriber-row-' + currentSubId);
                    if ($row.length) {
                        $row.find('.kd-status-badge')
                            .removeClass('kd-badge-subscribed')
                            .addClass('kd-badge-notified')
                            .text('Notified');
                    }

                    totalNotifiedSoFar++;
                    kdwn_load_next_manual_subscriber();
                } else {
                    KDNotification.show({
                        type: "error",
                        message: 'Error marking subscriber notified: ' + (response.data.message || 'Unknown error'),
                        position: "center"
                    });
                    $('#kd-manual-send-btn, #kd-manual-skip-btn, #kd-manual-mark-btn').prop('disabled', false);
                }
            },
            error: function() {
                KDNotification.show({
                    type: "error",
                    message: "Connection timeout. Failed to update status in database.",
                    position: "center"
                });
                $('#kd-manual-send-btn, #kd-manual-skip-btn, #kd-manual-mark-btn').prop('disabled', false);
            }
        });
    }

    // ==========================================
    // 7. CLIPBOARD COPIER CONTROLLER
    // ==========================================
    var $copyStatus = $('#kd-copy-status-msg');

    $('#kd-copy-phones-btn').on('click', function() {
        kdwn_copy_channel_contacts('phone');
    });

    $('#kd-copy-was-btn').on('click', function() {
        kdwn_copy_channel_contacts('whatsapp');
    });

    function kdwn_copy_channel_contacts(channelKey) {
        var $btn = channelKey === 'whatsapp' ? $('#kd-copy-was-btn') : $('#kd-copy-phones-btn');
        var originalHtml = $btn.html();

        $btn.prop('disabled', true).text('Fetching numbers...');

        $.ajax({
            url: kdwn_admin_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'kdwn_get_active_contacts',
                nonce: kdwn_admin_vars.nonce,
                channel: channelKey,
                service_id: activeServiceId
            },
            success: function(response) {
                if (response && response.success) {
                    // Write to system clipboard
                    navigator.clipboard.writeText(response.data.numbers).then(function() {
                        $copyStatus.text('Copied ' + response.data.count + ' numbers to clipboard!').fadeIn(150);
                        setTimeout(function() {
                            $copyStatus.fadeOut(250);
                        }, 2500);
                    }, function() {
                        KDNotification.show({
                            type: "error",
                            message: "Could not copy to clipboard. Please copy manually from the logs.",
                            position: "center"
                        });
                    });
                } else {
                    KDNotification.show({
                        type: "warning",
                        message: response.data.message || 'No numbers found to copy.',
                        position: "center"
                    });
                }
            },
            error: function() {
                KDNotification.show({
                    type: "error",
                    message: "Connection failed. Could not fetch contacts.",
                    position: "center"
                });
            },
            complete: function() {
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    }

    function kdwn_toggle_reset_button_visibility() {
        var totalNotified = parseInt($('#kd-count-notified').text(), 10) || 0;
        var totalFailed = parseInt($('#kd-count-failed').text(), 10) || 0;
        if ($('#kd-reset-status-btn').length) {
            if (totalNotified > 0 || totalFailed > 0) {
                $('#kd-reset-status-btn').fadeIn(200);
            } else {
                $('#kd-reset-status-btn').fadeOut(200);
            }
        }
    }

    function kdwn_update_stats_display(totalOffset, status) {
        var $allEl = $('.kd-stat-total .kd-stat-number');
        if ($allEl.length) {
            var currentAll = parseInt($allEl.first().text(), 10) || 0;
            $allEl.text(Math.max(0, currentAll + totalOffset));
        }

        var $statusEl = null;
        if (status === 'notified') {
            $statusEl = $('#kd-count-notified');
        } else if (status === 'failed') {
            $statusEl = $('#kd-count-failed');
        } else if (status === 'subscribed') {
            $statusEl = $('#kd-count-subscribed');
        }

        if ($statusEl && $statusEl.length) {
            var currentVal = parseInt($statusEl.text(), 10) || 0;
            $statusEl.text(Math.max(0, currentVal - 1));
        }

        kdwn_toggle_reset_button_visibility();
    }

    /**
     * Recalculates stats panel after a notification run.
     */
    function kdwn_refresh_ui_counters(processedCount, sentCount, failedCount) {
        var $subscribedEl = $('#kd-count-subscribed');
        var $notifiedEl = $('#kd-count-notified');
        var $failedEl = $('#kd-count-failed');

        if ($subscribedEl.length) {
            var currentSub = parseInt($subscribedEl.text(), 10) || 0;
            $subscribedEl.text(Math.max(0, currentSub - processedCount));
        }

        if ($notifiedEl.length) {
            var currentNot = parseInt($notifiedEl.text(), 10) || 0;
            $notifiedEl.text(currentNot + sentCount);
        }

        if ($failedEl.length && failedCount) {
            var currentFail = parseInt($failedEl.text(), 10) || 0;
            $failedEl.text(currentFail + failedCount);
        }

        kdwn_toggle_reset_button_visibility();
    }

    // ==========================================
    // 8. CUSTOM COUNTRY CODE REGISTRATION
    // ==========================================
    var $addCountryBox = $('#kd-add-country-box');
    var $triggerBtn = $('#kd-add-country-trigger-btn');
    var $cancelBtn = $('#kd-cancel-country-btn');
    var $saveBtn = $('#kd-save-country-btn');
    var $countryError = $('#kd-country-error-msg');
    
    $triggerBtn.on('click', function(e) {
        e.preventDefault();
        $addCountryBox.slideToggle(200);
        $countryError.hide().text('');
    });

    $cancelBtn.on('click', function(e) {
        e.preventDefault();
        $addCountryBox.slideUp(200);
        $('#kd-new-country-name').val('');
        $('#kd-new-country-code').val('');
        $countryError.hide().text('');
    });

    $saveBtn.on('click', function(e) {
        e.preventDefault();
        
        var name = $('#kd-new-country-name').val().trim();
        var code = $('#kd-new-country-code').val().trim();
        
        if (!name) {
            $countryError.text('Country initials cannot be empty.').fadeIn(150);
            return;
        }
        if (!code) {
            $countryError.text('Dialing prefix cannot be empty.').fadeIn(150);
            return;
        }

        $saveBtn.prop('disabled', true).text('Adding...');
        $countryError.hide().text('');

        $.ajax({
            url: kdwn_admin_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'kdwn_add_custom_country',
                nonce: kdwn_admin_vars.nonce,
                country_name: name,
                country_code: code,
                service_id: activeServiceId
            },
            success: function(response) {
                if (response && response.success) {
                    var data = response.data;
                    
                    // Check if option already exists to avoid duplicates
                    var $existingOpt = $('#default_country_code option[value="' + data.code + '"]');
                    if ($existingOpt.length === 0) {
                        var newOption = $('<option>', {
                            value: data.code,
                            text: data.name + ' (' + data.code + ')'
                        });
                        $('#default_country_code').append(newOption);
                    }
                    
                    // Set as selected default
                    $('#default_country_code').val(data.code).trigger('change');
                    
                    // Reset and close
                    $addCountryBox.slideUp(200);
                    $('#kd-new-country-name').val('');
                    $('#kd-new-country-code').val('');
                } else {
                    var msg = response.data.message || 'Could not add country dialing code.';
                    $countryError.text(msg).fadeIn(150);
                }
            },
            error: function() {
                $countryError.text('Connection error. Please try again.').fadeIn(150);
            },
            complete: function() {
                $saveBtn.prop('disabled', false).text('Add Code');
            }
        });
    });

    // ==========================================
    // 9. SERVICE SWITCHER & CREATION
    // ==========================================
    $('#kd-service-selector').on('change', function() {
        var serviceId = $(this).val();
        window.location.href = 'admin.php?page=khvichadev-waitlist-notify&service_id=' + serviceId;
    });

    $('#kd-add-service-trigger-btn').on('click', function(e) {
        e.preventDefault();
        $('#kd-service-modal').fadeIn(200);
        $('#kd-service-error-msg').hide().text('');
    });

    $('#kd-close-service-modal-btn').on('click', function(e) {
        e.preventDefault();
        $('#kd-service-modal').fadeOut(200);
        $('#kd-new-service-name').val('');
        $('#kd-new-service-desc').val('');
        $('#kd-service-error-msg').hide().text('');
    });

    $('#kd-create-service-form').on('submit', function(e) {
        e.preventDefault();
        
        var name = $('#kd-new-service-name').val().trim();
        var desc = $('#kd-new-service-desc').val().trim();
        var $btn = $('#kd-save-service-btn');
        var $error = $('#kd-service-error-msg');
        
        if (!name) {
            $error.text('Service name is required.').fadeIn(150);
            return;
        }
        
        $btn.prop('disabled', true).text('Creating...');
        $error.hide().text('');
        
        $.ajax({
            url: kdwn_admin_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'kdwn_add_custom_service',
                nonce: kdwn_admin_vars.nonce,
                service_name: name,
                service_desc: desc
            },
            success: function(response) {
                if (response && response.success) {
                    window.location.href = 'admin.php?page=khvichadev-waitlist-notify&service_id=' + response.data.id;
                } else {
                    var msg = response.data.message || 'Could not create service.';
                    $error.text(msg).fadeIn(150);
                    $btn.prop('disabled', false).text('Create Service');
                }
            },
            error: function() {
                $error.text('Connection error. Please try again.').fadeIn(150);
                $btn.prop('disabled', false).text('Create Service');
            }
        });
    });

    // ==========================================
    // 10. DELETE CUSTOM SERVICE
    // ==========================================
    $('#kd-delete-service-btn').on('click', function(e) {
        e.preventDefault();

        if (activeServiceId <= 1) {
            KDNotification.show({
                type: "warning",
                message: "Cannot delete the default service.",
                position: "center"
            });
            return;
        }

        KDNotification.show({
            type: "warning",
            title: "Delete Service?",
            message: "Are you sure you want to delete this service? This will delete all settings and ALL subscribers associated with it. This action cannot be undone!",
            position: "center",
            isModal: true,
            buttons: [
                { text: "Yes, Delete", className: "kd-btn-danger", value: "confirm" },
                { text: "Cancel", value: "cancel" }
            ]
        }).then(function(result) {
            if (result !== "confirm") return;

            var $btn = $('#kd-delete-service-btn');
            var originalHtml = $btn.html();
            $btn.prop('disabled', true).text('Deleting...');

            $.ajax({
                url: kdwn_admin_vars.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'kdwn_delete_custom_service',
                    nonce: kdwn_admin_vars.nonce,
                    service_id: activeServiceId
                },
                success: function(response) {
                    if (response && response.success) {
                        window.location.href = 'admin.php?page=khvichadev-waitlist-notify&service_id=1';
                    } else {
                        KDNotification.show({
                            type: "error",
                            message: response.data.message || 'Could not delete service.',
                            position: "center"
                        });
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function() {
                    KDNotification.show({
                        type: "error",
                        message: "Connection error. Please try again.",
                        position: "center"
                    });
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        });
    });

    // ==========================================
    // 11. COPY SHORTCODE UTILITY
    // ==========================================
    $('.kd-copy-shortcode-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var targetId = $btn.data('target');
        var codeText = $('#' + targetId).text().trim();

        navigator.clipboard.writeText(codeText).then(function() {
            var originalText = $btn.text();
            $btn.text('Copied!').css('color', '#34d399');
            setTimeout(function() {
                $btn.text(originalText).css('color', '');
            }, 2000);
        }, function() {
            KDNotification.show({
                type: "error",
                message: "Failed to copy to clipboard.",
                position: "center"
            });
        });
    });

    // ==========================================
    // 12. AJAX SETTINGS FORM SUBMISSION
    // ==========================================
    /**
     * Intercept settings form submit to save settings instantly via AJAX without page reloads.
     */
    $('.kd-settings-layout').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        var originalBtnHtml = $submitBtn.html();

        // Disable button to prevent double submission
        $submitBtn.prop('disabled', true).html('Saving...');

        // Serialize all form fields
        var kdwn_formData = $form.serializeArray();

        // Add additional variables for AJAX call
        kdwn_formData.push({ name: 'action', value: 'kdwn_save_settings' });
        kdwn_formData.push({ name: 'nonce', value: kdwn_admin_vars.nonce });
        kdwn_formData.push({ name: 'service_id', value: activeServiceId });

        $.ajax({
            url: kdwn_admin_vars.ajax_url,
            type: 'POST',
            data: kdwn_formData,
            dataType: 'json',
            success: function(response) {
                if (response && response.success) {
                    KDNotification.show({
                        type: "success",
                        message: response.data.message || "Settings saved successfully.",
                        position: "center",
                        duration: 3000
                    });
                } else {
                    KDNotification.show({
                        type: "error",
                        message: (response && response.data && response.data.message) ? response.data.message : "Failed to save settings.",
                        position: "center",
                        duration: 3000
                    });
                }
            },
            error: function() {
                KDNotification.show({
                    type: "error",
                    message: "Connection error. Failed to save settings.",
                    position: "center",
                    duration: 3000
                });
            },
            complete: function() {
                // Re-enable the submit button
                $submitBtn.prop('disabled', false).html(originalBtnHtml);
            }
        });
    });

    // ==========================================
    // 13. DELETE ALL SUBSCRIBERS
    // ==========================================
    $('#kd-delete-all-btn').on('click', function(e) {
        e.preventDefault();

        KDNotification.show({
            type: "warning",
            title: "Delete All Subscribers?",
            message: "Are you sure you want to delete ALL subscribers associated with this service? This action is permanent and cannot be undone!",
            position: "center",
            isModal: true,
            buttons: [
                { text: "Yes, Delete All", className: "kd-btn-danger", value: "confirm" },
                { text: "Cancel", value: "cancel" }
            ]
        }).then(function(result) {
            if (result !== "confirm") return;

            var $btn = $('#kd-delete-all-btn');
            var originalHtml = $btn.html();
            $btn.prop('disabled', true).text('Deleting All...');

            $.ajax({
                url: kdwn_admin_vars.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'kdwn_delete_all_subscribers',
                    nonce: kdwn_admin_vars.nonce,
                    service_id: activeServiceId
                },
                success: function(response) {
                    if (response && response.success) {
                        KDNotification.show({
                            type: "success",
                            message: response.data.message || "All subscribers successfully deleted.",
                            position: "center",
                            duration: 2000
                        }).then(function() {
                            // Fade out the list rows and inject empty placeholder
                            $('.kd-data-table tbody tr').fadeOut(300, function() {
                                $(this).remove();
                                if ($('.kd-data-table tbody tr').length === 0) {
                                    $('.kd-data-table tbody').html('<tr><td colspan="7" style="text-align: center; padding: 2rem;">No subscribers found.</td></tr>');
                                }
                            });

                            // Remove pagination links
                            $('.kd-pagination').fadeOut(200, function() {
                                $(this).remove();
                            });

                            // Reset stats counters in UI
                            $('.kd-stat-total .kd-stat-number').text(0);
                            $('#kd-count-subscribed').text(0);
                            $('#kd-count-notified').text(0);
                            $('#kd-count-failed').text(0);

                            // Hide bulk buttons
                            $('#kd-reset-status-btn').fadeOut(200);
                            $btn.fadeOut(200);
                        });
                    } else {
                        KDNotification.show({
                            type: "error",
                            message: response.data.message || "Could not delete subscribers.",
                            position: "center",
                            duration: 3000
                        });
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function() {
                    KDNotification.show({
                        type: "error",
                        message: "Connection error. Please try again.",
                        position: "center",
                        duration: 3000
                    });
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        });
    });
});
