jQuery(document).ready(function($) {
    'use strict';
    
    // Buy exam button click
    $(document).on('click', '.oep-buy-exam', function(e) {
        e.preventDefault();
        
        var examId = $(this).data('exam-id');
        
        if (!examId) {
            showMessage('error', 'شناسه آزمون معتبر نیست');
            return;
        }
        
        // Check if user is logged in
        if (typeof oep_shortcodes.payment_nonce === 'undefined') {
            showMessage('error', oep_shortcodes.strings.login_required);
            return;
        }
        
        loadPaymentForm(examId);
    });
    
    // Exam details button click
    $(document).on('click', '.oep-exam-details', function(e) {
        e.preventDefault();
        
        var examId = $(this).data('exam-id');
        loadExamDetails(examId);
    });
    
    // Payment method selection and proceed
    $(document).on('click', '.oep-proceed-payment', function(e) {
        e.preventDefault();
        
        var $form = $(this).closest('.oep-payment-form');
        var examId = $form.data('exam-id');
        var paymentMethod = $form.find('input[name="payment_method"]:checked').val();
        
        if (!paymentMethod) {
            showMessage('error', oep_shortcodes.strings.select_payment_method);
            return;
        }
        
        startPayment(examId, paymentMethod);
    });
    
    // Card transfer form submission
    $(document).on('submit', '#oep-card-transfer-form', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var formData = new FormData(this);
        formData.append('action', 'oep_submit_card_transfer');
        formData.append('nonce', oep_shortcodes.payment_nonce);
        formData.append('transaction_id', $form.data('transaction-id'));
        
        var $submitButton = $form.find('button[type="submit"]');
        $submitButton.prop('disabled', true).text(oep_shortcodes.strings.processing);
        
        $.ajax({
            url: oep_shortcodes.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    showMessage('success', response.data);
                    $('.oep-modal').hide();
                } else {
                    showMessage('error', response.data || oep_shortcodes.strings.error);
                }
            },
            error: function() {
                showMessage('error', oep_shortcodes.strings.error);
            },
            complete: function() {
                $submitButton.prop('disabled', false).text('ثبت اطلاعات واریز');
            }
        });
    });
    
    // Modal close functionality
    $(document).on('click', '.oep-modal-close', function() {
        $(this).closest('.oep-modal').hide();
    });
    
    $(document).on('click', '.oep-modal', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
    // Escape key to close modal
    $(document).on('keydown', function(e) {
        if (e.keyCode === 27) { // Escape key
            $('.oep-modal').hide();
        }
    });
    
    // Payment method selection styling
    $(document).on('change', 'input[name="payment_method"]', function() {
        $('.oep-payment-method').removeClass('selected');
        $(this).closest('.oep-payment-method').addClass('selected');
    });
    
    // Form validation
    $(document).on('submit', '.oep-payment-form', function(e) {
        var paymentMethod = $(this).find('input[name="payment_method"]:checked').val();
        if (!paymentMethod) {
            e.preventDefault();
            showMessage('error', oep_shortcodes.strings.select_payment_method);
        }
    });
    
    // File input styling and validation
    $(document).on('change', 'input[type="file"]', function() {
        var file = this.files[0];
        var $input = $(this);
        
        if (file) {
            // Check file size (max 2MB for receipts)
            if (file.size > 2 * 1024 * 1024) {
                showMessage('error', 'حجم فایل نباید بیشتر از 2 مگابایت باشد');
                this.value = '';
                return;
            }
            
            // Check file type
            var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
            if (allowedTypes.indexOf(file.type) === -1) {
                showMessage('error', 'فرمت فایل پشتیبانی نمی‌شود. فقط تصاویر و PDF مجاز هستند');
                this.value = '';
                return;
            }
            
            // Show file name
            var fileName = file.name;
            if (fileName.length > 30) {
                fileName = fileName.substring(0, 27) + '...';
            }
            $input.next('.file-name').remove();
            $input.after('<span class="file-name">' + fileName + '</span>');
        }
    });
    
    // Auto-resize textareas
    $(document).on('input', 'textarea', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
    
    // Number input formatting
    $(document).on('input', 'input[type="text"][pattern*="[0-9]"]', function() {
        var value = $(this).val().replace(/[^0-9]/g, '');
        $(this).val(value);
    });
    
    // Functions
    function loadPaymentForm(examId) {
        $.ajax({
            url: oep_shortcodes.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_get_payment_form',
                exam_id: examId,
                nonce: oep_shortcodes.payment_nonce
            },
            beforeSend: function() {
                showModal('<div class="oep-loading">در حال بارگذاری...</div>');
            },
            success: function(response) {
                if (response.success) {
                    showModal(response.data);
                } else {
                    hideModal();
                    showMessage('error', response.data || 'خطا در بارگذاری فرم پرداخت');
                }
            },
            error: function() {
                hideModal();
                showMessage('error', 'خطا در اتصال به سرور');
            }
        });
    }
    
    function loadExamDetails(examId) {
        $.ajax({
            url: oep_shortcodes.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_get_exam_details',
                exam_id: examId,
                nonce: oep_shortcodes.user_nonce
            },
            beforeSend: function() {
                showModal('<div class="oep-loading">در حال بارگذاری...</div>', 'oep-exam-details-modal');
            },
            success: function(response) {
                if (response.success) {
                    showModal(response.data, 'oep-exam-details-modal');
                } else {
                    hideModal('oep-exam-details-modal');
                    showMessage('error', response.data || 'خطا در بارگذاری جزئیات آزمون');
                }
            },
            error: function() {
                hideModal('oep-exam-details-modal');
                showMessage('error', 'خطا در اتصال به سرور');
            }
        });
    }
    
    function startPayment(examId, paymentMethod) {
        $.ajax({
            url: oep_shortcodes.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_start_payment',
                exam_id: examId,
                payment_method: paymentMethod,
                nonce: oep_shortcodes.payment_nonce
            },
            beforeSend: function() {
                $('.oep-proceed-payment').prop('disabled', true).text(oep_shortcodes.strings.processing);
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.redirect) {
                        // Redirect to payment gateway
                        window.location.href = response.data.redirect;
                    } else if (response.data.show_modal) {
                        // Show card transfer form
                        showModal(response.data.modal_content);
                    } else {
                        showMessage('success', response.data.message);
                        if (response.data.redirect) {
                            setTimeout(function() {
                                window.location.href = response.data.redirect;
                            }, 2000);
                        }
                    }
                } else {
                    showMessage('error', response.data || oep_shortcodes.strings.error);
                }
            },
            error: function() {
                showMessage('error', oep_shortcodes.strings.error);
            },
            complete: function() {
                $('.oep-proceed-payment').prop('disabled', false).text('ادامه پرداخت');
            }
        });
    }
    
    function showModal(content, modalId) {
        modalId = modalId || 'oep-payment-modal';
        var $modal = $('#' + modalId);
        
        if ($modal.length === 0) {
            $modal = $('<div id="' + modalId + '" class="oep-modal"><div class="oep-modal-content"><span class="oep-modal-close">&times;</span><div class="oep-modal-body"></div></div></div>');
            $('body').append($modal);
        }
        
        $modal.find('.oep-modal-body').html(content);
        $modal.show();
        
        // Prevent body scroll
        $('body').addClass('oep-modal-open');
    }
    
    function hideModal(modalId) {
        modalId = modalId || 'oep-payment-modal';
        $('#' + modalId).hide();
        $('body').removeClass('oep-modal-open');
    }
    
    function showMessage(type, message) {
        var messageClass = 'oep-message-' + type;
        var $message = $('<div class="oep-message ' + messageClass + '">' + message + '</div>');
        
        // Remove existing messages
        $('.oep-message').remove();
        
        // Add message to body
        $('body').prepend($message);
        
        // Position message
        $message.css({
            position: 'fixed',
            top: '20px',
            right: '20px',
            left: '20px',
            zIndex: 10001,
            padding: '15px',
            borderRadius: '5px',
            fontWeight: 'bold',
            textAlign: 'center',
            direction: 'rtl'
        });
        
        // Style based on type
        switch (type) {
            case 'success':
                $message.css({
                    backgroundColor: '#d4edda',
                    color: '#155724',
                    border: '1px solid #c3e6cb'
                });
                break;
            case 'error':
                $message.css({
                    backgroundColor: '#f8d7da',
                    color: '#721c24',
                    border: '1px solid #f5c6cb'
                });
                break;
            case 'info':
                $message.css({
                    backgroundColor: '#d1ecf1',
                    color: '#0c5460',
                    border: '1px solid #bee5eb'
                });
                break;
        }
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $message.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
        // Click to dismiss
        $message.on('click', function() {
            $(this).fadeOut(function() {
                $(this).remove();
            });
        });
    }
    
    // Utility function to format numbers
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    
    // Add modal styles if not already present
    if ($('#oep-modal-styles').length === 0) {
        $('<style id="oep-modal-styles">')
            .html(`
                .oep-modal-open { overflow: hidden; }
                .oep-payment-method.selected { 
                    background-color: #e3f2fd; 
                    border-color: #2196f3; 
                }
                .file-name {
                    display: inline-block;
                    margin-right: 10px;
                    color: #666;
                    font-size: 12px;
                }
                .oep-message {
                    animation: oep-slideIn 0.3s ease-out;
                }
                @keyframes oep-slideIn {
                    from { transform: translateY(-100%); opacity: 0; }
                    to { transform: translateY(0); opacity: 1; }
                }
                @media (max-width: 768px) {
                    .oep-message {
                        right: 10px;
                        left: 10px;
                        top: 10px;
                    }
                }
            `)
            .appendTo('head');
    }
});