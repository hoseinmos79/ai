jQuery(document).ready(function($) {
    'use strict';
    
    // Transaction approval/rejection AJAX
    $('.oep-approve-transaction').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(oep_admin_ajax.strings.confirm_approve)) {
            return;
        }
        
        var $button = $(this);
        var transactionId = $button.data('transaction-id');
        
        $button.prop('disabled', true).text(oep_admin_ajax.strings.processing);
        
        $.ajax({
            url: oep_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_approve_transaction',
                transaction_id: transactionId,
                nonce: oep_admin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data);
                    $button.closest('tr').find('.oep-status-badge')
                        .removeClass('oep-status-pending')
                        .addClass('oep-status-completed')
                        .text('تکمیل شده');
                    $button.closest('td').html('<span class="oep-status-badge oep-status-completed">تایید شده</span>');
                } else {
                    showNotice('error', response.data || oep_admin_ajax.strings.error);
                    $button.prop('disabled', false).text('تایید');
                }
            },
            error: function() {
                showNotice('error', oep_admin_ajax.strings.error);
                $button.prop('disabled', false).text('تایید');
            }
        });
    });
    
    $('.oep-reject-transaction').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(oep_admin_ajax.strings.confirm_reject)) {
            return;
        }
        
        var $button = $(this);
        var transactionId = $button.data('transaction-id');
        
        $button.prop('disabled', true).text(oep_admin_ajax.strings.processing);
        
        $.ajax({
            url: oep_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_reject_transaction',
                transaction_id: transactionId,
                nonce: oep_admin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data);
                    $button.closest('tr').find('.oep-status-badge')
                        .removeClass('oep-status-pending')
                        .addClass('oep-status-rejected')
                        .text('رد شده');
                    $button.closest('td').html('<span class="oep-status-badge oep-status-rejected">رد شده</span>');
                } else {
                    showNotice('error', response.data || oep_admin_ajax.strings.error);
                    $button.prop('disabled', false).text('رد');
                }
            },
            error: function() {
                showNotice('error', oep_admin_ajax.strings.error);
                $button.prop('disabled', false).text('رد');
            }
        });
    });
    
    // View transfer info modal
    $('.oep-view-transfer-info').on('click', function(e) {
        e.preventDefault();
        
        var transferInfo = $(this).data('transfer-info');
        
        if (typeof transferInfo === 'string') {
            try {
                transferInfo = JSON.parse(transferInfo);
            } catch (e) {
                showNotice('error', 'خطا در نمایش اطلاعات انتقال');
                return;
            }
        }
        
        var modalContent = '<div class="oep-transfer-info">';
        modalContent += '<h4>جزئیات انتقال کارت به کارت</h4>';
        
        if (transferInfo.card_last_digits) {
            modalContent += '<div class="oep-info-row">';
            modalContent += '<span class="oep-info-label">چهار رقم آخر کارت:</span>';
            modalContent += '<span class="oep-info-value">' + transferInfo.card_last_digits + '</span>';
            modalContent += '</div>';
        }
        
        if (transferInfo.tracking_code) {
            modalContent += '<div class="oep-info-row">';
            modalContent += '<span class="oep-info-label">کد پیگیری:</span>';
            modalContent += '<span class="oep-info-value">' + transferInfo.tracking_code + '</span>';
            modalContent += '</div>';
        }
        
        if (transferInfo.transfer_date) {
            modalContent += '<div class="oep-info-row">';
            modalContent += '<span class="oep-info-label">تاریخ انتقال:</span>';
            modalContent += '<span class="oep-info-value">' + transferInfo.transfer_date + '</span>';
            modalContent += '</div>';
        }
        
        if (transferInfo.additional_info) {
            modalContent += '<div class="oep-info-row">';
            modalContent += '<span class="oep-info-label">توضیحات اضافی:</span>';
            modalContent += '<span class="oep-info-value">' + transferInfo.additional_info + '</span>';
            modalContent += '</div>';
        }
        
        if (transferInfo.receipt_url) {
            modalContent += '<div class="oep-info-row">';
            modalContent += '<span class="oep-info-label">رسید:</span>';
            modalContent += '<div class="oep-info-value">';
            modalContent += '<img src="' + transferInfo.receipt_url + '" alt="رسید انتقال" class="oep-receipt-image" />';
            modalContent += '</div>';
            modalContent += '</div>';
        }
        
        if (transferInfo.submitted_at) {
            modalContent += '<div class="oep-info-row">';
            modalContent += '<span class="oep-info-label">زمان ثبت:</span>';
            modalContent += '<span class="oep-info-value">' + transferInfo.submitted_at + '</span>';
            modalContent += '</div>';
        }
        
        modalContent += '</div>';
        
        showModal(modalContent, 'oep-transfer-modal');
    });
    
    // Close modals
    $(document).on('click', '.oep-modal-close', function() {
        $(this).closest('.oep-modal').hide();
    });
    
    $(document).on('click', '.oep-modal', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
    // File upload validation
    $('input[type="file"]').on('change', function() {
        var file = this.files[0];
        var $input = $(this);
        var $error = $input.siblings('.oep-field-error');
        
        // Remove existing error
        $error.remove();
        
        if (file) {
            var allowedTypes = ['text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            var allowedExtensions = ['txt', 'doc', 'docx'];
            var maxSize = 5 * 1024 * 1024; // 5MB
            
            var fileName = file.name.toLowerCase();
            var fileExtension = fileName.split('.').pop();
            
            // Check file type
            if (!allowedTypes.includes(file.type) && !allowedExtensions.includes(fileExtension)) {
                $input.after('<div class="oep-field-error">فرمت فایل پشتیبانی نمی‌شود. فرمت‌های مجاز: .txt, .doc, .docx</div>');
                $input.val('');
                return;
            }
            
            // Check file size
            if (file.size > maxSize) {
                $input.after('<div class="oep-field-error">حجم فایل نباید بیشتر از 5 مگابایت باشد.</div>');
                $input.val('');
                return;
            }
            
            // Show success message
            $input.after('<div class="oep-field-success">فایل معتبر است</div>');
        }
    });
    
    // Form submission with progress
    $('.oep-import-form').on('submit', function(e) {
        var $form = $(this);
        var $submitButton = $form.find('input[type="submit"]');
        var originalText = $submitButton.val();
        
        $submitButton.prop('disabled', true).val('در حال پردازش...');
        
        // Show progress indicator
        if ($('.oep-progress').length === 0) {
            $form.after('<div class="oep-progress"><div class="spinner is-active"></div><p>در حال پردازش فایل...</p></div>');
        }
        $('.oep-progress').show();
        
        // Re-enable form after 30 seconds as fallback
        setTimeout(function() {
            $submitButton.prop('disabled', false).val(originalText);
            $('.oep-progress').hide();
        }, 30000);
    });
    
    // Auto-hide notices
    setTimeout(function() {
        $('.notice.is-dismissible').fadeOut();
    }, 5000);
    
    // Confirm delete actions
    $('a[href*="action=trash"], a[href*="action=delete"]').on('click', function(e) {
        if (!confirm('آیا از حذف این مورد اطمینان دارید؟')) {
            e.preventDefault();
        }
    });
    
    // Enhanced table interactions
    $('.wp-list-table tbody tr').hover(
        function() {
            $(this).addClass('hover');
        },
        function() {
            $(this).removeClass('hover');
        }
    );
    
    // Search functionality for tables
    if ($('#exam-search').length) {
        $('#exam-search').on('keyup', function() {
            var value = $(this).val().toLowerCase();
            $('.wp-list-table tbody tr').filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
            });
        });
    }
    
    // Bulk actions confirmation
    $('#doaction, #doaction2').on('click', function(e) {
        var action = $(this).siblings('select').val();
        var checkedItems = $('input[name="post[]"]:checked, input[name="transaction[]"]:checked').length;
        
        if (action === 'trash' || action === 'delete') {
            if (checkedItems === 0) {
                alert('لطفاً حداقل یک مورد را انتخاب کنید.');
                e.preventDefault();
                return;
            }
            
            if (!confirm('آیا از حذف ' + checkedItems + ' مورد انتخاب شده اطمینان دارید؟')) {
                e.preventDefault();
            }
        }
    });
    
    // Dynamic form validation
    $('form').on('submit', function(e) {
        var $form = $(this);
        var hasErrors = false;
        
        // Check required fields
        $form.find('[required]').each(function() {
            var $field = $(this);
            var value = $field.val().trim();
            
            $field.siblings('.oep-field-error').remove();
            
            if (!value) {
                $field.after('<div class="oep-field-error">این فیلد الزامی است</div>');
                hasErrors = true;
            }
        });
        
        // Check email fields
        $form.find('input[type="email"]').each(function() {
            var $field = $(this);
            var value = $field.val().trim();
            
            if (value && !isValidEmail(value)) {
                $field.siblings('.oep-field-error').remove();
                $field.after('<div class="oep-field-error">آدرس ایمیل معتبر نیست</div>');
                hasErrors = true;
            }
        });
        
        if (hasErrors) {
            e.preventDefault();
            $('html, body').animate({
                scrollTop: $('.oep-field-error').first().offset().top - 100
            }, 500);
        }
    });
    
    // Settings form enhancements
    $('#zarinpal_sandbox').on('change', function() {
        var $notice = $('#sandbox-notice');
        if ($(this).is(':checked')) {
            if ($notice.length === 0) {
                $(this).closest('tr').after('<tr id="sandbox-notice"><td colspan="2"><div class="oep-notice warning">حالت آزمایشی فعال است. برای استفاده واقعی این گزینه را غیرفعال کنید.</div></td></tr>');
            }
        } else {
            $notice.remove();
        }
    });
    
    // Currency change handler
    $('#currency').on('change', function() {
        var currency = $(this).val();
        var $notice = $('#currency-notice');
        
        $notice.remove();
        
        if (currency === 'rial') {
            $(this).closest('tr').after('<tr id="currency-notice"><td colspan="2"><div class="oep-notice info">توجه: قیمت‌های آزمون‌ها باید به ریال وارد شوند.</div></td></tr>');
        }
    });
    
    // Initialize tooltips
    $('[title]').each(function() {
        var $element = $(this);
        var title = $element.attr('title');
        
        $element.removeAttr('title').on('mouseenter', function() {
            var tooltip = $('<div class="oep-tooltip">' + title + '</div>');
            $('body').append(tooltip);
            
            var offset = $element.offset();
            tooltip.css({
                position: 'absolute',
                top: offset.top - tooltip.outerHeight() - 5,
                left: offset.left + ($element.outerWidth() / 2) - (tooltip.outerWidth() / 2),
                zIndex: 100000
            });
        }).on('mouseleave', function() {
            $('.oep-tooltip').remove();
        });
    });
    
    // Utility functions
    function showNotice(type, message) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after($notice);
        
        setTimeout(function() {
            $notice.fadeOut();
        }, 5000);
    }
    
    function showModal(content, modalId) {
        var $modal = $('#' + modalId);
        if ($modal.length === 0) {
            $modal = $('<div id="' + modalId + '" class="oep-modal"><div class="oep-modal-content"><span class="oep-modal-close">&times;</span><div class="oep-modal-body"></div></div></div>');
            $('body').append($modal);
        }
        
        $modal.find('.oep-modal-body').html(content);
        $modal.show();
    }
    
    function isValidEmail(email) {
        var regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    }
    
    // Initialize on page load
    function initializePage() {
        // Trigger change events for settings
        $('#zarinpal_sandbox').trigger('change');
        $('#currency').trigger('change');
        
        // Focus first input field in forms
        $('form').each(function() {
            var $firstInput = $(this).find('input, select, textarea').not('[type="hidden"]').first();
            if ($firstInput.length) {
                $firstInput.focus();
            }
        });
    }
    
    // Run initialization
    initializePage();
    
    // Handle AJAX errors globally
    $(document).ajaxError(function(event, xhr, settings, error) {
        if (xhr.status === 0) {
            showNotice('error', 'خطا در اتصال به سرور. لطفاً اتصال اینترنت خود را بررسی کنید.');
        } else if (xhr.status === 403) {
            showNotice('error', 'دسترسی غیرمجاز. لطفاً دوباره وارد شوید.');
        } else if (xhr.status === 500) {
            showNotice('error', 'خطای داخلی سرور. لطفاً با مدیر سایت تماس بگیرید.');
        }
    });
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl/Cmd + S to save forms
        if ((e.ctrlKey || e.metaKey) && e.which === 83) {
            e.preventDefault();
            var $form = $('form').first();
            if ($form.length) {
                $form.submit();
            }
        }
        
        // Escape to close modals
        if (e.which === 27) {
            $('.oep-modal:visible').hide();
        }
    });
});