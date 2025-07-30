jQuery(document).ready(function($) {
    'use strict';

    // Global variables
    var paymentInProgress = false;
    var currentExamId = null;

    // Initialize payment functionality
    initPaymentHandlers();

    function initPaymentHandlers() {
        // Payment method selection
        $(document).on('change', 'input[name="payment_method"]', function() {
            var method = $(this).val();
            updatePaymentMethodUI(method);
        });

        // Payment form submission
        $(document).on('submit', '.oep-payment-form', function(e) {
            e.preventDefault();
            
            if (paymentInProgress) {
                return;
            }

            if (!validatePaymentForm($(this))) {
                return;
            }

            var formData = new FormData(this);
            formData.append('action', 'oep_start_payment');
            formData.append('nonce', oep_payment_ajax.nonce);

            processPayment(formData);
        });

        // Card transfer form submission
        $(document).on('submit', '.oep-card-transfer-form', function(e) {
            e.preventDefault();
            
            if (!validateCardTransferForm($(this))) {
                return;
            }

            var formData = new FormData(this);
            formData.append('action', 'oep_submit_card_transfer');
            formData.append('nonce', oep_payment_ajax.nonce);

            submitCardTransfer(formData);
        });

        // File upload handling
        $(document).on('change', 'input[type="file"][name="receipt"]', function() {
            handleReceiptUpload(this);
        });

        // Payment retry button
        $(document).on('click', '.oep-retry-payment', function(e) {
            e.preventDefault();
            var examId = $(this).data('exam-id');
            retryPayment(examId);
        });

        // Check payment status button
        $(document).on('click', '.oep-check-payment-status', function(e) {
            e.preventDefault();
            var transactionId = $(this).data('transaction-id');
            checkPaymentStatus(transactionId);
        });

        // Payment method info toggles
        $(document).on('click', '.oep-payment-method-info', function(e) {
            e.preventDefault();
            var method = $(this).data('method');
            showPaymentMethodInfo(method);
        });

        // Close payment info modal
        $(document).on('click', '.oep-close-payment-info', function() {
            hidePaymentInfoModal();
        });

        // Payment history
        $(document).on('click', '.oep-view-payment-history', function(e) {
            e.preventDefault();
            loadPaymentHistory();
        });
    }

    function updatePaymentMethodUI(method) {
        // Hide all method-specific content
        $('.oep-payment-method-details').hide();
        
        // Show selected method content
        $('.oep-payment-method-details[data-method="' + method + '"]').show();
        
        // Update visual selection
        $('.oep-payment-method-option').removeClass('selected');
        $('input[name="payment_method"][value="' + method + '"]').closest('.oep-payment-method-option').addClass('selected');
        
        // Update submit button text and style
        var $submitBtn = $('.oep-payment-submit');
        
        switch(method) {
            case 'zarinpal':
                $submitBtn.text(oep_payment_ajax.strings.pay_with_zarinpal)
                         .removeClass('oep-btn-secondary')
                         .addClass('oep-btn-primary');
                break;
            case 'card_transfer':
                $submitBtn.text(oep_payment_ajax.strings.continue_card_transfer)
                         .removeClass('oep-btn-primary')
                         .addClass('oep-btn-secondary');
                break;
            default:
                $submitBtn.text(oep_payment_ajax.strings.continue_payment);
        }
    }

    function validatePaymentForm(form) {
        var isValid = true;
        var errors = [];

        // Check if payment method is selected
        var paymentMethod = form.find('input[name="payment_method"]:checked').val();
        if (!paymentMethod) {
            errors.push(oep_payment_ajax.strings.select_payment_method);
            isValid = false;
        }

        // Check exam ID
        var examId = form.find('input[name="exam_id"]').val();
        if (!examId) {
            errors.push(oep_payment_ajax.strings.invalid_exam);
            isValid = false;
        }

        // Check terms acceptance if required
        var termsCheckbox = form.find('input[name="accept_terms"]');
        if (termsCheckbox.length && !termsCheckbox.is(':checked')) {
            errors.push(oep_payment_ajax.strings.accept_terms);
            isValid = false;
        }

        if (!isValid) {
            showValidationErrors(errors);
        }

        return isValid;
    }

    function validateCardTransferForm(form) {
        var isValid = true;
        var errors = [];

        // Required fields
        var requiredFields = [
            { name: 'card_last_digits', label: oep_payment_ajax.strings.card_last_digits },
            { name: 'tracking_code', label: oep_payment_ajax.strings.tracking_code },
            { name: 'transfer_date', label: oep_payment_ajax.strings.transfer_date }
        ];

        requiredFields.forEach(function(field) {
            var value = form.find('input[name="' + field.name + '"]').val();
            if (!value || value.trim() === '') {
                errors.push(field.label + ' ' + oep_payment_ajax.strings.is_required);
                form.find('input[name="' + field.name + '"]').addClass('oep-field-error');
                isValid = false;
            } else {
                form.find('input[name="' + field.name + '"]').removeClass('oep-field-error');
            }
        });

        // Validate card last digits (should be 4 digits)
        var cardDigits = form.find('input[name="card_last_digits"]').val();
        if (cardDigits && !/^\d{4}$/.test(cardDigits)) {
            errors.push(oep_payment_ajax.strings.invalid_card_digits);
            form.find('input[name="card_last_digits"]').addClass('oep-field-error');
            isValid = false;
        }

        // Validate tracking code (should be numeric and reasonable length)
        var trackingCode = form.find('input[name="tracking_code"]').val();
        if (trackingCode && (!/^\d+$/.test(trackingCode) || trackingCode.length < 6)) {
            errors.push(oep_payment_ajax.strings.invalid_tracking_code);
            form.find('input[name="tracking_code"]').addClass('oep-field-error');
            isValid = false;
        }

        // Validate date format
        var transferDate = form.find('input[name="transfer_date"]').val();
        if (transferDate && !isValidDate(transferDate)) {
            errors.push(oep_payment_ajax.strings.invalid_date_format);
            form.find('input[name="transfer_date"]').addClass('oep-field-error');
            isValid = false;
        }

        if (!isValid) {
            showValidationErrors(errors);
        }

        return isValid;
    }

    function processPayment(formData) {
        paymentInProgress = true;
        
        $.ajax({
            url: oep_payment_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function() {
                showPaymentProgress();
                $('.oep-payment-submit').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    handlePaymentSuccess(response.data);
                } else {
                    handlePaymentError(response.data);
                }
            },
            error: function(xhr, status, error) {
                handlePaymentError(oep_payment_ajax.strings.connection_error);
            },
            complete: function() {
                paymentInProgress = false;
                hidePaymentProgress();
                $('.oep-payment-submit').prop('disabled', false);
            }
        });
    }

    function handlePaymentSuccess(data) {
        if (data.redirect) {
            // Redirect to payment gateway
            showMessage('info', oep_payment_ajax.strings.redirecting_to_gateway);
            setTimeout(function() {
                window.location.href = data.redirect;
            }, 1500);
        } else if (data.card_info) {
            // Show card transfer information
            displayCardTransferInfo(data.card_info);
        } else if (data.message) {
            // Free exam or other success message
            showMessage('success', data.message);
            if (data.exam_url) {
                setTimeout(function() {
                    window.location.href = data.exam_url;
                }, 2000);
            }
        }
    }

    function handlePaymentError(error) {
        var errorMessage = error || oep_payment_ajax.strings.payment_failed;
        showMessage('error', errorMessage);
        
        // Re-enable form
        $('.oep-payment-form input, .oep-payment-form button').prop('disabled', false);
    }

    function submitCardTransfer(formData) {
        $.ajax({
            url: oep_payment_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function() {
                showTransferProgress();
                $('.oep-card-transfer-submit').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    showMessage('success', response.data);
                    hideCardTransferModal();
                    
                    // Show success info
                    showTransferSuccessInfo();
                } else {
                    showMessage('error', response.data || oep_payment_ajax.strings.transfer_failed);
                }
            },
            error: function() {
                showMessage('error', oep_payment_ajax.strings.connection_error);
            },
            complete: function() {
                hideTransferProgress();
                $('.oep-card-transfer-submit').prop('disabled', false);
            }
        });
    }

    function displayCardTransferInfo(cardInfo) {
        var html = '<div class="oep-card-transfer-info">';
        html += '<div class="oep-transfer-header">';
        html += '<h3>' + oep_payment_ajax.strings.card_transfer_info + '</h3>';
        html += '<p class="oep-transfer-note">' + oep_payment_ajax.strings.transfer_instructions + '</p>';
        html += '</div>';
        
        html += '<div class="oep-bank-details">';
        html += '<div class="oep-detail-item">';
        html += '<span class="oep-detail-label">' + oep_payment_ajax.strings.card_number + ':</span>';
        html += '<span class="oep-detail-value oep-copyable" data-copy="' + cardInfo.card_number + '">';
        html += cardInfo.card_number + ' <button class="oep-copy-btn" title="' + oep_payment_ajax.strings.copy + '">📋</button>';
        html += '</span>';
        html += '</div>';
        
        html += '<div class="oep-detail-item">';
        html += '<span class="oep-detail-label">' + oep_payment_ajax.strings.account_holder + ':</span>';
        html += '<span class="oep-detail-value">' + cardInfo.account_holder + '</span>';
        html += '</div>';
        
        html += '<div class="oep-detail-item">';
        html += '<span class="oep-detail-label">' + oep_payment_ajax.strings.amount + ':</span>';
        html += '<span class="oep-detail-value oep-amount">' + formatAmount(cardInfo.amount) + ' ' + oep_payment_ajax.strings.currency + '</span>';
        html += '</div>';
        
        if (cardInfo.reference_code) {
            html += '<div class="oep-detail-item">';
            html += '<span class="oep-detail-label">' + oep_payment_ajax.strings.reference_code + ':</span>';
            html += '<span class="oep-detail-value oep-copyable" data-copy="' + cardInfo.reference_code + '">';
            html += cardInfo.reference_code + ' <button class="oep-copy-btn" title="' + oep_payment_ajax.strings.copy + '">📋</button>';
            html += '</span>';
            html += '</div>';
        }
        html += '</div>';
        
        // Transfer form
        html += '<div class="oep-transfer-form-container">';
        html += '<h4>' + oep_payment_ajax.strings.submit_transfer_info + '</h4>';
        html += '<form class="oep-card-transfer-form">';
        html += '<input type="hidden" name="transaction_id" value="' + cardInfo.transaction_id + '">';
        
        html += '<div class="oep-form-group">';
        html += '<label for="card_last_digits">' + oep_payment_ajax.strings.card_last_digits + ' *</label>';
        html += '<input type="text" name="card_last_digits" id="card_last_digits" maxlength="4" pattern="[0-9]{4}" required>';
        html += '<small class="oep-field-help">' + oep_payment_ajax.strings.card_digits_help + '</small>';
        html += '</div>';
        
        html += '<div class="oep-form-group">';
        html += '<label for="tracking_code">' + oep_payment_ajax.strings.tracking_code + ' *</label>';
        html += '<input type="text" name="tracking_code" id="tracking_code" required>';
        html += '<small class="oep-field-help">' + oep_payment_ajax.strings.tracking_code_help + '</small>';
        html += '</div>';
        
        html += '<div class="oep-form-group">';
        html += '<label for="transfer_date">' + oep_payment_ajax.strings.transfer_date + ' *</label>';
        html += '<input type="date" name="transfer_date" id="transfer_date" max="' + getCurrentDate() + '" required>';
        html += '</div>';
        
        html += '<div class="oep-form-group">';
        html += '<label for="additional_info">' + oep_payment_ajax.strings.additional_info + '</label>';
        html += '<textarea name="additional_info" id="additional_info" rows="3" placeholder="' + oep_payment_ajax.strings.additional_info_placeholder + '"></textarea>';
        html += '</div>';
        
        html += '<div class="oep-form-group">';
        html += '<label for="receipt">' + oep_payment_ajax.strings.receipt_image + '</label>';
        html += '<input type="file" name="receipt" id="receipt" accept="image/*">';
        html += '<small class="oep-field-help">' + oep_payment_ajax.strings.receipt_help + '</small>';
        html += '<div class="oep-receipt-preview"></div>';
        html += '</div>';
        
        html += '<div class="oep-form-actions">';
        html += '<button type="submit" class="oep-btn oep-btn-primary oep-card-transfer-submit">' + 
                oep_payment_ajax.strings.submit_transfer_info + '</button>';
        html += '<button type="button" class="oep-btn oep-btn-secondary oep-cancel-transfer">' + 
                oep_payment_ajax.strings.cancel + '</button>';
        html += '</div>';
        
        html += '</form>';
        html += '</div>';
        html += '</div>';
        
        // Show in modal
        showCardTransferModal(html);
        
        // Initialize copy functionality
        initCopyButtons();
    }

    function handleReceiptUpload(input) {
        var file = input.files[0];
        if (!file) return;
        
        // Validate file
        if (!file.type.startsWith('image/')) {
            showMessage('error', oep_payment_ajax.strings.invalid_file_type);
            input.value = '';
            return;
        }
        
        if (file.size > 5 * 1024 * 1024) { // 5MB limit
            showMessage('error', oep_payment_ajax.strings.file_too_large);
            input.value = '';
            return;
        }
        
        // Show preview
        var reader = new FileReader();
        reader.onload = function(e) {
            var preview = '<div class="oep-image-preview">';
            preview += '<img src="' + e.target.result + '" alt="Receipt Preview">';
            preview += '<button type="button" class="oep-remove-image" title="' + oep_payment_ajax.strings.remove_image + '">×</button>';
            preview += '</div>';
            
            $('.oep-receipt-preview').html(preview);
        };
        reader.readAsDataURL(file);
    }

    // Remove uploaded image
    $(document).on('click', '.oep-remove-image', function() {
        $('input[name="receipt"]').val('');
        $('.oep-receipt-preview').empty();
    });

    function initCopyButtons() {
        $(document).on('click', '.oep-copy-btn', function(e) {
            e.preventDefault();
            var textToCopy = $(this).parent().data('copy');
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(textToCopy).then(function() {
                    showCopySuccess();
                });
            } else {
                // Fallback for older browsers
                var textArea = document.createElement('textarea');
                textArea.value = textToCopy;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showCopySuccess();
            }
        });
    }

    function retryPayment(examId) {
        currentExamId = examId;
        // Reload payment form or redirect to payment page
        window.location.reload();
    }

    function checkPaymentStatus(transactionId) {
        $.ajax({
            url: oep_payment_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_check_payment_status',
                transaction_id: transactionId,
                nonce: oep_payment_ajax.nonce
            },
            beforeSend: function() {
                $('.oep-check-payment-status').prop('disabled', true).text(oep_payment_ajax.strings.checking);
            },
            success: function(response) {
                if (response.success) {
                    showMessage('success', response.data.message);
                    if (response.data.status === 'completed') {
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    }
                } else {
                    showMessage('info', response.data || oep_payment_ajax.strings.payment_pending);
                }
            },
            error: function() {
                showMessage('error', oep_payment_ajax.strings.check_status_error);
            },
            complete: function() {
                $('.oep-check-payment-status').prop('disabled', false).text(oep_payment_ajax.strings.check_status);
            }
        });
    }

    function showPaymentMethodInfo(method) {
        var content = '';
        
        switch(method) {
            case 'zarinpal':
                content = '<div class="oep-payment-info">';
                content += '<h3>' + oep_payment_ajax.strings.zarinpal_info_title + '</h3>';
                content += '<ul>';
                content += '<li>' + oep_payment_ajax.strings.zarinpal_info_1 + '</li>';
                content += '<li>' + oep_payment_ajax.strings.zarinpal_info_2 + '</li>';
                content += '<li>' + oep_payment_ajax.strings.zarinpal_info_3 + '</li>';
                content += '<li>' + oep_payment_ajax.strings.zarinpal_info_4 + '</li>';
                content += '</ul>';
                content += '</div>';
                break;
                
            case 'card_transfer':
                content = '<div class="oep-payment-info">';
                content += '<h3>' + oep_payment_ajax.strings.card_transfer_info_title + '</h3>';
                content += '<ul>';
                content += '<li>' + oep_payment_ajax.strings.card_transfer_info_1 + '</li>';
                content += '<li>' + oep_payment_ajax.strings.card_transfer_info_2 + '</li>';
                content += '<li>' + oep_payment_ajax.strings.card_transfer_info_3 + '</li>';
                content += '<li>' + oep_payment_ajax.strings.card_transfer_info_4 + '</li>';
                content += '</ul>';
                content += '</div>';
                break;
        }
        
        showPaymentInfoModal(content);
    }

    function loadPaymentHistory() {
        $.ajax({
            url: oep_payment_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_get_payment_history',
                nonce: oep_payment_ajax.nonce
            },
            beforeSend: function() {
                showLoading('.oep-payment-history-container');
            },
            success: function(response) {
                if (response.success) {
                    displayPaymentHistory(response.data);
                } else {
                    showMessage('error', response.data || oep_payment_ajax.strings.load_history_error);
                }
            },
            error: function() {
                showMessage('error', oep_payment_ajax.strings.connection_error);
            },
            complete: function() {
                hideLoading('.oep-payment-history-container');
            }
        });
    }

    function displayPaymentHistory(payments) {
        var html = '<div class="oep-payment-history">';
        html += '<h3>' + oep_payment_ajax.strings.payment_history + '</h3>';
        
        if (payments.length === 0) {
            html += '<div class="oep-no-payments">' + oep_payment_ajax.strings.no_payments + '</div>';
        } else {
            html += '<div class="oep-payments-list">';
            
            payments.forEach(function(payment) {
                html += '<div class="oep-payment-item oep-status-' + payment.status + '">';
                html += '<div class="oep-payment-header">';
                html += '<h4>' + payment.exam_title + '</h4>';
                html += '<span class="oep-payment-status">' + getStatusText(payment.status) + '</span>';
                html += '</div>';
                
                html += '<div class="oep-payment-details">';
                html += '<div class="oep-payment-amount">' + formatAmount(payment.amount) + ' ' + oep_payment_ajax.strings.currency + '</div>';
                html += '<div class="oep-payment-method">' + getMethodText(payment.payment_method) + '</div>';
                html += '<div class="oep-payment-date">' + formatDate(payment.created_at) + '</div>';
                html += '</div>';
                
                if (payment.status === 'pending' && payment.payment_method === 'card_transfer') {
                    html += '<div class="oep-payment-actions">';
                    html += '<button class="oep-btn oep-btn-sm oep-check-payment-status" data-transaction-id="' + payment.id + '">';
                    html += oep_payment_ajax.strings.check_status + '</button>';
                    html += '</div>';
                }
                
                html += '</div>';
            });
            
            html += '</div>';
        }
        
        html += '</div>';
        
        $('.oep-payment-history-container').html(html);
    }

    // Utility functions
    function showValidationErrors(errors) {
        var errorHtml = '<div class="oep-validation-errors">';
        errors.forEach(function(error) {
            errorHtml += '<div class="oep-error-item">' + error + '</div>';
        });
        errorHtml += '</div>';
        
        showMessage('error', errorHtml);
    }

    function showPaymentProgress() {
        var html = '<div class="oep-payment-progress">';
        html += '<div class="oep-progress-spinner"></div>';
        html += '<div class="oep-progress-text">' + oep_payment_ajax.strings.processing_payment + '</div>';
        html += '</div>';
        
        $('.oep-payment-form').append(html);
    }

    function hidePaymentProgress() {
        $('.oep-payment-progress').remove();
    }

    function showTransferProgress() {
        var html = '<div class="oep-transfer-progress">';
        html += '<div class="oep-progress-spinner"></div>';
        html += '<div class="oep-progress-text">' + oep_payment_ajax.strings.submitting_transfer + '</div>';
        html += '</div>';
        
        $('.oep-card-transfer-form').append(html);
    }

    function hideTransferProgress() {
        $('.oep-transfer-progress').remove();
    }

    function showCardTransferModal(content) {
        var modal = '<div class="oep-modal oep-card-transfer-modal" id="oep-card-transfer-modal">';
        modal += '<div class="oep-modal-content">';
        modal += '<div class="oep-modal-header">';
        modal += '<span class="oep-modal-close">&times;</span>';
        modal += '</div>';
        modal += '<div class="oep-modal-body">' + content + '</div>';
        modal += '</div>';
        modal += '</div>';
        
        $('body').append(modal);
        $('#oep-card-transfer-modal').fadeIn(300);
    }

    function hideCardTransferModal() {
        $('#oep-card-transfer-modal').fadeOut(300, function() {
            $(this).remove();
        });
    }

    function showPaymentInfoModal(content) {
        var modal = '<div class="oep-modal oep-payment-info-modal" id="oep-payment-info-modal">';
        modal += '<div class="oep-modal-content">';
        modal += '<div class="oep-modal-header">';
        modal += '<span class="oep-modal-close oep-close-payment-info">&times;</span>';
        modal += '</div>';
        modal += '<div class="oep-modal-body">' + content + '</div>';
        modal += '</div>';
        modal += '</div>';
        
        $('body').append(modal);
        $('#oep-payment-info-modal').fadeIn(300);
    }

    function hidePaymentInfoModal() {
        $('#oep-payment-info-modal').fadeOut(300, function() {
            $(this).remove();
        });
    }

    function showTransferSuccessInfo() {
        var html = '<div class="oep-transfer-success-info">';
        html += '<div class="oep-success-icon">✓</div>';
        html += '<h3>' + oep_payment_ajax.strings.transfer_submitted + '</h3>';
        html += '<p>' + oep_payment_ajax.strings.transfer_review_message + '</p>';
        html += '<div class="oep-success-actions">';
        html += '<button class="oep-btn oep-btn-primary" onclick="location.reload()">' + 
                oep_payment_ajax.strings.continue + '</button>';
        html += '</div>';
        html += '</div>';
        
        $('body').append('<div class="oep-overlay">' + html + '</div>');
        
        setTimeout(function() {
            $('.oep-overlay').fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    function showCopySuccess() {
        var notification = '<div class="oep-copy-notification">' + oep_payment_ajax.strings.copied + '</div>';
        $('body').append(notification);
        
        setTimeout(function() {
            $('.oep-copy-notification').fadeOut(function() {
                $(this).remove();
            });
        }, 2000);
    }

    function showMessage(type, message) {
        var messageHtml = '<div class="oep-message oep-message-' + type + '">' + message + '</div>';
        
        // Remove existing messages
        $('.oep-message').remove();
        
        // Add new message
        if ($('.oep-messages-container').length) {
            $('.oep-messages-container').html(messageHtml);
        } else {
            $('body').prepend('<div class="oep-messages-container">' + messageHtml + '</div>');
        }
        
        // Auto-hide success messages
        if (type === 'success' || type === 'info') {
            setTimeout(function() {
                $('.oep-message').fadeOut();
            }, 5000);
        }
        
        // Scroll to top to show message
        $('html, body').animate({scrollTop: 0}, 300);
    }

    function showLoading(selector) {
        $(selector).append('<div class="oep-loading-overlay"><div class="oep-spinner"></div></div>');
    }

    function hideLoading(selector) {
        $(selector).find('.oep-loading-overlay').remove();
    }

    // Utility functions for formatting
    function formatAmount(amount) {
        return parseInt(amount).toLocaleString('fa-IR');
    }

    function formatDate(dateString) {
        var date = new Date(dateString);
        return date.toLocaleDateString('fa-IR');
    }

    function getCurrentDate() {
        var today = new Date();
        return today.toISOString().split('T')[0];
    }

    function isValidDate(dateString) {
        var regex = /^\d{4}-\d{2}-\d{2}$/;
        if (!regex.test(dateString)) return false;
        
        var date = new Date(dateString);
        return date instanceof Date && !isNaN(date);
    }

    function getStatusText(status) {
        var statusTexts = {
            'pending': oep_payment_ajax.strings.status_pending,
            'completed': oep_payment_ajax.strings.status_completed,
            'failed': oep_payment_ajax.strings.status_failed,
            'rejected': oep_payment_ajax.strings.status_rejected
        };
        
        return statusTexts[status] || status;
    }

    function getMethodText(method) {
        var methodTexts = {
            'zarinpal': oep_payment_ajax.strings.method_zarinpal,
            'card_transfer': oep_payment_ajax.strings.method_card_transfer
        };
        
        return methodTexts[method] || method;
    }

    // Cancel transfer button
    $(document).on('click', '.oep-cancel-transfer', function() {
        hideCardTransferModal();
    });

    // Modal close functionality
    $(document).on('click', '.oep-modal-close', function() {
        $(this).closest('.oep-modal').fadeOut(300, function() {
            $(this).remove();
        });
    });

    // Close modal on backdrop click
    $(document).on('click', '.oep-modal', function(e) {
        if (e.target === this) {
            $(this).fadeOut(300, function() {
                $(this).remove();
            });
        }
    });

    // Escape key to close modal
    $(document).on('keydown', function(e) {
        if (e.keyCode === 27) { // ESC key
            $('.oep-modal:visible').fadeOut(300, function() {
                $(this).remove();
            });
        }
    });

    // Form field error handling
    $(document).on('input', '.oep-field-error', function() {
        $(this).removeClass('oep-field-error');
    });

    // Numeric input formatting
    $(document).on('input', 'input[name="card_last_digits"]', function() {
        var value = $(this).val().replace(/\D/g, '');
        $(this).val(value);
    });

    $(document).on('input', 'input[name="tracking_code"]', function() {
        var value = $(this).val().replace(/\D/g, '');
        $(this).val(value);
    });
});