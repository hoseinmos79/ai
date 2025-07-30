jQuery(document).ready(function($) {
    'use strict';
    
    // Transaction approval/rejection
    $('.oep-approve-transaction').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(oep_ajax.strings.confirm_approve)) {
            return;
        }
        
        var $button = $(this);
        var transactionId = $button.data('transaction-id');
        
        $button.prop('disabled', true).text('در حال پردازش...');
        
        $.ajax({
            url: oep_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_approve_transaction',
                transaction_id: transactionId,
                nonce: oep_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $button.closest('tr').find('.oep-status-pending').removeClass('oep-status-pending').addClass('oep-status-completed').text('تکمیل شده');
                    $button.closest('td').html('<span class="button disabled">تایید شده</span>');
                    showNotice('success', response.data);
                } else {
                    showNotice('error', response.data || 'خطا در تایید تراکنش');
                    $button.prop('disabled', false).text('تایید');
                }
            },
            error: function() {
                showNotice('error', 'خطا در اتصال به سرور');
                $button.prop('disabled', false).text('تایید');
            }
        });
    });
    
    $('.oep-reject-transaction').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(oep_ajax.strings.confirm_reject)) {
            return;
        }
        
        var $button = $(this);
        var transactionId = $button.data('transaction-id');
        
        $button.prop('disabled', true).text('در حال پردازش...');
        
        $.ajax({
            url: oep_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_reject_transaction',
                transaction_id: transactionId,
                nonce: oep_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $button.closest('tr').find('.oep-status-pending').removeClass('oep-status-pending').addClass('oep-status-failed').text('ناموفق');
                    $button.closest('td').html('<span class="button disabled">رد شده</span>');
                    showNotice('success', response.data);
                } else {
                    showNotice('error', response.data || 'خطا در رد تراکنش');
                    $button.prop('disabled', false).text('رد');
                }
            },
            error: function() {
                showNotice('error', 'خطا در اتصال به سرور');
                $button.prop('disabled', false).text('رد');
            }
        });
    });
    
    // View transfer info modal
    $('.oep-view-transfer-info').on('click', function(e) {
        e.preventDefault();
        
        var transferInfo = $(this).data('info');
        try {
            var info = JSON.parse(transferInfo);
            var content = '<h3>اطلاعات واریز کارت به کارت</h3>';
            content += '<table class="form-table">';
            content += '<tr><th>چهار رقم آخر کارت:</th><td>' + (info.sender_card || '-') + '</td></tr>';
            content += '<tr><th>کد پیگیری:</th><td>' + (info.tracking_code || '-') + '</td></tr>';
            content += '<tr><th>تاریخ واریز:</th><td>' + (info.transfer_date || '-') + '</td></tr>';
            content += '<tr><th>زمان ثبت:</th><td>' + (info.submitted_at || '-') + '</td></tr>';
            if (info.receipt_url) {
                content += '<tr><th>رسید:</th><td><a href="' + info.receipt_url + '" target="_blank">مشاهده رسید</a></td></tr>';
            }
            content += '</table>';
            
            $('#oep-transfer-info-content').html(content);
            $('#oep-transfer-info-modal').show();
        } catch (e) {
            showNotice('error', 'خطا در نمایش اطلاعات');
        }
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
    
    // View exam answers
    $('.oep-view-answers').on('click', function(e) {
        e.preventDefault();
        
        var resultId = $(this).data('result-id');
        // This would load and display the detailed answers
        // Implementation depends on specific requirements
        showNotice('info', 'قابلیت مشاهده جزئیات پاسخ‌ها در نسخه بعدی اضافه خواهد شد');
    });
    
    // File upload validation
    $('input[type="file"]').on('change', function() {
        var file = this.files[0];
        if (file) {
            var allowedTypes = ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
                               'application/msword', 'text/plain'];
            var maxSize = 5 * 1024 * 1024; // 5MB
            
            if (allowedTypes.indexOf(file.type) === -1 && !file.name.match(/\.(docx|doc|txt)$/i)) {
                showNotice('error', 'فرمت فایل پشتیبانی نمی‌شود. فقط فایل‌های Word و متنی مجاز هستند.');
                this.value = '';
                return;
            }
            
            if (file.size > maxSize) {
                showNotice('error', 'حجم فایل نباید بیشتر از 5 مگابایت باشد.');
                this.value = '';
                return;
            }
        }
    });
    
    // Form submission with progress
    $('.oep-import-form').on('submit', function(e) {
        var $form = $(this);
        var $submitButton = $form.find('input[type="submit"]');
        
        $submitButton.prop('disabled', true).val('در حال پردازش...');
        
        // Show progress (if needed)
        $('.oep-import-progress').show();
        
        // The form will submit normally, but we provide visual feedback
        setTimeout(function() {
            if ($submitButton.prop('disabled')) {
                $submitButton.prop('disabled', false).val('وارد کردن سوالات');
                $('.oep-import-progress').hide();
            }
        }, 30000); // Reset after 30 seconds if no response
    });
    
    // Auto-refresh for pending transactions
    if ($('.oep-status-pending').length > 0) {
        setTimeout(function() {
            if (window.location.href.indexOf('oep-transactions') !== -1) {
                window.location.reload();
            }
        }, 60000); // Refresh every minute if there are pending transactions
    }
    
    // Statistics update (if on dashboard)
    function updateStatistics() {
        // This would fetch updated statistics via AJAX
        // Implementation depends on specific requirements
    }
    
    // Utility function to show notices
    function showNotice(type, message) {
        var noticeClass = 'notice-' + type;
        var notice = '<div class="notice ' + noticeClass + ' is-dismissible oep-admin-notice"><p>' + message + '</p></div>';
        
        // Remove existing notices
        $('.oep-admin-notice').remove();
        
        // Add new notice
        $('.oep-admin-wrap h1').after(notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $('.oep-admin-notice').fadeOut();
        }, 5000);
    }
    
    // Table row highlighting
    $('.wp-list-table tbody tr').hover(
        function() {
            $(this).addClass('hover');
        },
        function() {
            $(this).removeClass('hover');
        }
    );
    
    // Bulk actions (if needed in future)
    $('#doaction, #doaction2').on('click', function(e) {
        var action = $(this).siblings('select').val();
        if (action === '-1') {
            e.preventDefault();
            showNotice('error', 'لطفاً یک عملیات انتخاب کنید');
        }
    });
    
    // Search functionality enhancement
    $('.search-box input[type="search"]').on('keyup', function(e) {
        if (e.keyCode === 13) { // Enter key
            $(this).closest('form').submit();
        }
    });
    
    // Confirm delete actions
    $('a[href*="action=delete"], a[href*="action=trash"]').on('click', function(e) {
        if (!confirm('آیا از حذف این مورد اطمینان دارید؟')) {
            e.preventDefault();
        }
    });
    
    // Enhanced form validation
    $('form').on('submit', function(e) {
        var $form = $(this);
        var hasErrors = false;
        
        // Check required fields
        $form.find('[required]').each(function() {
            if (!$(this).val().trim()) {
                $(this).addClass('error');
                hasErrors = true;
            } else {
                $(this).removeClass('error');
            }
        });
        
        if (hasErrors) {
            e.preventDefault();
            showNotice('error', 'لطفاً تمام فیلدهای الزامی را پر کنید');
        }
    });
    
    // Add error styling
    $('<style>')
        .prop('type', 'text/css')
        .html('.error { border-color: #dc3232 !important; box-shadow: 0 0 2px rgba(220, 50, 50, 0.8); }')
        .appendTo('head');
});