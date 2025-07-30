jQuery(document).ready(function($) {
    'use strict';

    // Global variables
    var currentExamId = null;
    var paymentInProgress = false;

    // Initialize shortcode functionality
    initExamList();
    initPaymentForms();
    initModals();
    initUserPanel();

    // Exam List functionality
    function initExamList() {
        // Handle exam purchase buttons
        $(document).on('click', '.oep-buy-exam', function(e) {
            e.preventDefault();
            
            if (!oep_shortcodes_ajax.is_logged_in) {
                showMessage('error', oep_shortcodes_ajax.strings.login_required);
                return;
            }

            currentExamId = $(this).data('exam-id');
            var examTitle = $(this).data('exam-title');
            var examPrice = $(this).data('exam-price');

            // Update payment modal content
            $('#oep-payment-modal .oep-exam-title').text(examTitle);
            $('#oep-payment-modal .oep-exam-price').text(examPrice + ' ' + oep_shortcodes_ajax.strings.currency);
            $('#oep-payment-modal input[name="exam_id"]').val(currentExamId);

            // Show payment modal
            showModal('oep-payment-modal');
        });

        // Handle exam details buttons
        $(document).on('click', '.oep-exam-details', function(e) {
            e.preventDefault();
            var examId = $(this).data('exam-id');
            loadExamDetails(examId);
        });

        // Handle start exam buttons
        $(document).on('click', '.oep-start-exam', function(e) {
            e.preventDefault();
            
            if (!oep_shortcodes_ajax.is_logged_in) {
                showMessage('error', oep_shortcodes_ajax.strings.login_required);
                return;
            }

            var examId = $(this).data('exam-id');
            startExam(examId);
        });

        // Search functionality
        $('.oep-exam-search').on('input', function() {
            var searchTerm = $(this).val().toLowerCase();
            filterExams(searchTerm);
        });

        // Category filter
        $('.oep-category-filter').on('change', function() {
            var selectedCategory = $(this).val();
            filterExamsByCategory(selectedCategory);
        });
    }

    // Payment form functionality
    function initPaymentForms() {
        // Handle payment method selection
        $(document).on('change', 'input[name="payment_method"]', function() {
            var method = $(this).val();
            updatePaymentMethodUI(method);
        });

        // Handle payment form submission
        $(document).on('submit', '.oep-payment-form', function(e) {
            e.preventDefault();
            
            if (paymentInProgress) {
                return;
            }

            var formData = new FormData(this);
            formData.append('action', 'oep_start_payment');
            formData.append('nonce', oep_shortcodes_ajax.nonce);

            processPayment(formData);
        });

        // Handle card transfer form submission
        $(document).on('submit', '.oep-card-transfer-form', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            formData.append('action', 'oep_submit_card_transfer');
            formData.append('nonce', oep_shortcodes_ajax.nonce);

            submitCardTransfer(formData);
        });

        // File upload preview
        $(document).on('change', 'input[type="file"][name="receipt"]', function() {
            var file = this.files[0];
            if (file) {
                previewReceiptImage(file);
            }
        });
    }

    // Modal functionality
    function initModals() {
        // Close modal buttons
        $(document).on('click', '.oep-modal-close', function() {
            hideModal($(this).closest('.oep-modal').attr('id'));
        });

        // Close modal on backdrop click
        $(document).on('click', '.oep-modal', function(e) {
            if (e.target === this) {
                hideModal($(this).attr('id'));
            }
        });

        // Escape key to close modal
        $(document).on('keydown', function(e) {
            if (e.keyCode === 27) { // ESC key
                $('.oep-modal:visible').each(function() {
                    hideModal($(this).attr('id'));
                });
            }
        });
    }

    // User panel functionality
    function initUserPanel() {
        // Tab switching
        $(document).on('click', '.oep-tab-button', function() {
            var tabId = $(this).data('tab');
            switchTab(tabId);
        });

        // Load user exams on panel load
        if ($('#oep-user-panel').length) {
            loadUserExams();
        }
    }

    // Helper functions
    function loadExamDetails(examId) {
        $.ajax({
            url: oep_shortcodes_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_get_exam_details',
                exam_id: examId,
                nonce: oep_shortcodes_ajax.nonce
            },
            beforeSend: function() {
                showLoading('#oep-exam-details-modal .oep-modal-body');
            },
            success: function(response) {
                if (response.success) {
                    displayExamDetails(response.data);
                    showModal('oep-exam-details-modal');
                } else {
                    showMessage('error', response.data || oep_shortcodes_ajax.strings.error);
                }
            },
            error: function() {
                showMessage('error', oep_shortcodes_ajax.strings.ajax_error);
            },
            complete: function() {
                hideLoading('#oep-exam-details-modal .oep-modal-body');
            }
        });
    }

    function startExam(examId) {
        if (confirm(oep_shortcodes_ajax.strings.confirm_start_exam)) {
            window.location.href = oep_shortcodes_ajax.exam_url + '?exam_id=' + examId;
        }
    }

    function processPayment(formData) {
        paymentInProgress = true;
        
        $.ajax({
            url: oep_shortcodes_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function() {
                $('.oep-payment-submit').prop('disabled', true).text(oep_shortcodes_ajax.strings.processing);
                showLoading('.oep-payment-form');
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.redirect) {
                        window.location.href = response.data.redirect;
                    } else if (response.data.card_info) {
                        displayCardTransferInfo(response.data.card_info);
                        hideModal('oep-payment-modal');
                        showModal('oep-card-transfer-modal');
                    } else {
                        showMessage('success', response.data.message);
                        hideModal('oep-payment-modal');
                        // Refresh page to update exam access
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    }
                } else {
                    showMessage('error', response.data || oep_shortcodes_ajax.strings.payment_error);
                }
            },
            error: function() {
                showMessage('error', oep_shortcodes_ajax.strings.ajax_error);
            },
            complete: function() {
                paymentInProgress = false;
                $('.oep-payment-submit').prop('disabled', false).text(oep_shortcodes_ajax.strings.pay_now);
                hideLoading('.oep-payment-form');
            }
        });
    }

    function submitCardTransfer(formData) {
        $.ajax({
            url: oep_shortcodes_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function() {
                $('.oep-card-transfer-submit').prop('disabled', true).text(oep_shortcodes_ajax.strings.processing);
                showLoading('.oep-card-transfer-form');
            },
            success: function(response) {
                if (response.success) {
                    showMessage('success', response.data);
                    hideModal('oep-card-transfer-modal');
                    $('.oep-card-transfer-form')[0].reset();
                } else {
                    showMessage('error', response.data || oep_shortcodes_ajax.strings.error);
                }
            },
            error: function() {
                showMessage('error', oep_shortcodes_ajax.strings.ajax_error);
            },
            complete: function() {
                $('.oep-card-transfer-submit').prop('disabled', false).text(oep_shortcodes_ajax.strings.submit_transfer);
                hideLoading('.oep-card-transfer-form');
            }
        });
    }

    function loadUserExams() {
        $.ajax({
            url: oep_shortcodes_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_get_user_exams',
                nonce: oep_shortcodes_ajax.nonce
            },
            beforeSend: function() {
                showLoading('#oep-user-exams');
            },
            success: function(response) {
                if (response.success) {
                    displayUserExams(response.data);
                } else {
                    $('#oep-user-exams').html('<div class="oep-message oep-message-error">' + 
                        (response.data || oep_shortcodes_ajax.strings.error) + '</div>');
                }
            },
            error: function() {
                $('#oep-user-exams').html('<div class="oep-message oep-message-error">' + 
                    oep_shortcodes_ajax.strings.ajax_error + '</div>');
            },
            complete: function() {
                hideLoading('#oep-user-exams');
                $('#oep-user-exams').show();
                $('.oep-loading').hide();
            }
        });
    }

    function filterExams(searchTerm) {
        $('.oep-exam-card').each(function() {
            var examTitle = $(this).find('.oep-exam-title').text().toLowerCase();
            var examDescription = $(this).find('.oep-exam-description').text().toLowerCase();
            
            if (examTitle.includes(searchTerm) || examDescription.includes(searchTerm)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }

    function filterExamsByCategory(categoryId) {
        if (!categoryId) {
            $('.oep-exam-card').show();
            return;
        }

        $('.oep-exam-card').each(function() {
            var examCategories = $(this).data('categories') || [];
            if (examCategories.includes(parseInt(categoryId))) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }

    function updatePaymentMethodUI(method) {
        $('.oep-payment-method-details').hide();
        $('.oep-payment-method-details[data-method="' + method + '"]').show();
        
        // Update submit button text
        if (method === 'zarinpal') {
            $('.oep-payment-submit').text(oep_shortcodes_ajax.strings.pay_online);
        } else {
            $('.oep-payment-submit').text(oep_shortcodes_ajax.strings.continue_offline);
        }
    }

    function displayExamDetails(examData) {
        var modal = $('#oep-exam-details-modal');
        modal.find('.oep-exam-title').text(examData.title);
        modal.find('.oep-exam-description').html(examData.description);
        modal.find('.oep-exam-price').text(examData.price + ' ' + oep_shortcodes_ajax.strings.currency);
        modal.find('.oep-exam-questions-count').text(examData.questions_count);
        modal.find('.oep-exam-duration').text(examData.duration + ' ' + oep_shortcodes_ajax.strings.minutes);
        modal.find('.oep-exam-pass-score').text(examData.pass_score + '%');
        
        // Update action button
        var actionButton = modal.find('.oep-exam-action');
        if (examData.has_access) {
            actionButton.removeClass('oep-buy-exam').addClass('oep-start-exam')
                      .text(oep_shortcodes_ajax.strings.start_exam)
                      .data('exam-id', examData.id);
        } else {
            actionButton.removeClass('oep-start-exam').addClass('oep-buy-exam')
                      .text(oep_shortcodes_ajax.strings.buy_exam)
                      .data('exam-id', examData.id)
                      .data('exam-title', examData.title)
                      .data('exam-price', examData.price);
        }
    }

    function displayCardTransferInfo(cardInfo) {
        var modal = $('#oep-card-transfer-modal');
        modal.find('.oep-card-number').text(cardInfo.card_number);
        modal.find('.oep-account-holder').text(cardInfo.account_holder);
        modal.find('.oep-transfer-amount').text(cardInfo.amount + ' ' + oep_shortcodes_ajax.strings.currency);
        modal.find('input[name="transaction_id"]').val(cardInfo.transaction_id);
    }

    function displayUserExams(exams) {
        var html = '';
        
        if (exams.length === 0) {
            html = '<div class="oep-message oep-message-info">' + 
                   oep_shortcodes_ajax.strings.no_exams + '</div>';
        } else {
            html = '<div class="oep-user-exams-grid">';
            
            exams.forEach(function(exam) {
                html += '<div class="oep-user-exam-card">';
                html += '<div class="oep-exam-header">';
                html += '<h3 class="oep-exam-title">' + exam.title + '</h3>';
                if (exam.categories.length > 0) {
                    html += '<div class="oep-exam-categories">';
                    exam.categories.forEach(function(category) {
                        html += '<span class="oep-category-tag">' + category + '</span>';
                    });
                    html += '</div>';
                }
                html += '</div>';
                
                html += '<div class="oep-exam-meta">';
                html += '<span class="oep-exam-questions">' + exam.questions_count + ' ' + oep_shortcodes_ajax.strings.questions + '</span>';
                html += '<span class="oep-exam-duration">' + exam.duration + ' ' + oep_shortcodes_ajax.strings.minutes + '</span>';
                html += '</div>';
                
                if (exam.last_result) {
                    html += '<div class="oep-exam-result">';
                    html += '<span class="oep-result-score">' + oep_shortcodes_ajax.strings.last_score + ': ' + exam.last_result.score + '%</span>';
                    html += '<span class="oep-result-status oep-status-' + (exam.last_result.passed ? 'passed' : 'failed') + '">';
                    html += exam.last_result.passed ? oep_shortcodes_ajax.strings.passed : oep_shortcodes_ajax.strings.failed;
                    html += '</span>';
                    html += '</div>';
                }
                
                html += '<div class="oep-exam-actions">';
                if (exam.has_active_session) {
                    html += '<a href="' + oep_shortcodes_ajax.exam_url + '?exam_id=' + exam.id + '" class="oep-btn oep-btn-warning">' + oep_shortcodes_ajax.strings.continue_exam + '</a>';
                } else {
                    html += '<a href="' + oep_shortcodes_ajax.exam_url + '?exam_id=' + exam.id + '" class="oep-btn oep-btn-primary">' + oep_shortcodes_ajax.strings.start_exam + '</a>';
                }
                html += '<button class="oep-btn oep-btn-secondary oep-view-results" data-exam-id="' + exam.id + '">' + oep_shortcodes_ajax.strings.view_results + '</button>';
                html += '</div>';
                
                html += '</div>';
            });
            
            html += '</div>';
        }
        
        $('#oep-user-exams').html(html);
    }

    function previewReceiptImage(file) {
        if (file.type.startsWith('image/')) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var preview = '<div class="oep-receipt-preview">' +
                             '<img src="' + e.target.result + '" alt="Receipt Preview" style="max-width: 200px; max-height: 200px;">' +
                             '</div>';
                $('.oep-receipt-preview').remove();
                $('input[name="receipt"]').after(preview);
            };
            reader.readAsDataURL(file);
        }
    }

    function switchTab(tabId) {
        // Update tab buttons
        $('.oep-tab-button').removeClass('active');
        $('.oep-tab-button[data-tab="' + tabId + '"]').addClass('active');
        
        // Update tab content
        $('.oep-tab-panel').removeClass('active');
        $('#oep-tab-' + tabId).addClass('active');
        
        // Load content if needed
        if (tabId === 'results') {
            // Results will be loaded when user clicks on an exam
        }
    }

    // View exam results
    $(document).on('click', '.oep-view-results', function() {
        var examId = $(this).data('exam-id');
        loadExamResults(examId);
        switchTab('results');
    });

    function loadExamResults(examId) {
        $.ajax({
            url: oep_shortcodes_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_get_exam_results',
                exam_id: examId,
                nonce: oep_shortcodes_ajax.nonce
            },
            beforeSend: function() {
                showLoading('#oep-exam-results');
            },
            success: function(response) {
                if (response.success) {
                    displayExamResults(response.data);
                } else {
                    $('#oep-exam-results').html('<div class="oep-message oep-message-error">' + 
                        (response.data || oep_shortcodes_ajax.strings.error) + '</div>');
                }
            },
            error: function() {
                $('#oep-exam-results').html('<div class="oep-message oep-message-error">' + 
                    oep_shortcodes_ajax.strings.ajax_error + '</div>');
            },
            complete: function() {
                hideLoading('#oep-exam-results');
                $('#oep-exam-results').show();
            }
        });
    }

    function displayExamResults(results) {
        var html = '<div class="oep-exam-results-container">';
        html += '<h3>' + oep_shortcodes_ajax.strings.exam_results + '</h3>';
        
        if (results.length === 0) {
            html += '<div class="oep-message oep-message-info">' + oep_shortcodes_ajax.strings.no_results + '</div>';
        } else {
            html += '<div class="oep-results-list">';
            
            results.forEach(function(result, index) {
                html += '<div class="oep-result-item">';
                html += '<div class="oep-result-header">';
                html += '<h4>' + oep_shortcodes_ajax.strings.attempt + ' ' + (index + 1) + '</h4>';
                html += '<span class="oep-result-date">' + result.completed_at + '</span>';
                html += '</div>';
                
                html += '<div class="oep-result-summary">';
                html += '<div class="oep-result-score">' + oep_shortcodes_ajax.strings.score + ': ' + result.score + '%</div>';
                html += '<div class="oep-result-status oep-status-' + (result.passed ? 'passed' : 'failed') + '">';
                html += result.passed ? oep_shortcodes_ajax.strings.passed : oep_shortcodes_ajax.strings.failed;
                html += '</div>';
                html += '<div class="oep-result-time">' + oep_shortcodes_ajax.strings.time_spent + ': ' + formatTime(result.time_spent) + '</div>';
                html += '</div>';
                
                if (result.answers && result.answers.length > 0) {
                    html += '<button class="oep-btn oep-btn-secondary oep-toggle-answers" data-target="answers-' + result.id + '">' + 
                           oep_shortcodes_ajax.strings.view_answers + '</button>';
                    
                    html += '<div class="oep-answers-details" id="answers-' + result.id + '" style="display: none;">';
                    result.answers.forEach(function(answer, qIndex) {
                        html += '<div class="oep-answer-item ' + (answer.is_correct ? 'correct' : 'incorrect') + '">';
                        html += '<div class="oep-question-text">' + (qIndex + 1) + '. ' + answer.question + '</div>';
                        html += '<div class="oep-answer-options">';
                        html += '<div class="oep-user-answer">پاسخ شما: ' + answer.user_answer + '</div>';
                        html += '<div class="oep-correct-answer">پاسخ صحیح: ' + answer.correct_answer + '</div>';
                        if (answer.explanation) {
                            html += '<div class="oep-answer-explanation">' + answer.explanation + '</div>';
                        }
                        html += '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                }
                
                html += '</div>';
            });
            
            html += '</div>';
        }
        
        html += '</div>';
        $('#oep-exam-results').html(html);
    }

    // Toggle answers visibility
    $(document).on('click', '.oep-toggle-answers', function() {
        var target = $(this).data('target');
        var answersDiv = $('#' + target);
        
        if (answersDiv.is(':visible')) {
            answersDiv.slideUp();
            $(this).text(oep_shortcodes_ajax.strings.view_answers);
        } else {
            answersDiv.slideDown();
            $(this).text(oep_shortcodes_ajax.strings.hide_answers);
        }
    });

    // Utility functions
    function showModal(modalId) {
        $('#' + modalId).fadeIn(300);
        $('body').addClass('oep-modal-open');
    }

    function hideModal(modalId) {
        $('#' + modalId).fadeOut(300);
        $('body').removeClass('oep-modal-open');
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
        if (type === 'success') {
            setTimeout(function() {
                $('.oep-message-success').fadeOut();
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

    function formatTime(seconds) {
        var hours = Math.floor(seconds / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var secs = seconds % 60;
        
        if (hours > 0) {
            return hours + ':' + (minutes < 10 ? '0' : '') + minutes + ':' + (secs < 10 ? '0' : '') + secs;
        } else {
            return minutes + ':' + (secs < 10 ? '0' : '') + secs;
        }
    }

    // Form validation
    function validateForm(form) {
        var isValid = true;
        
        // Check required fields
        form.find('[required]').each(function() {
            if (!$(this).val().trim()) {
                $(this).addClass('oep-field-error');
                isValid = false;
            } else {
                $(this).removeClass('oep-field-error');
            }
        });
        
        // Check email format
        form.find('input[type="email"]').each(function() {
            var email = $(this).val().trim();
            if (email && !isValidEmail(email)) {
                $(this).addClass('oep-field-error');
                isValid = false;
            } else {
                $(this).removeClass('oep-field-error');
            }
        });
        
        return isValid;
    }

    function isValidEmail(email) {
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Form submission validation
    $(document).on('submit', 'form', function(e) {
        if (!validateForm($(this))) {
            e.preventDefault();
            showMessage('error', oep_shortcodes_ajax.strings.form_validation_error);
        }
    });

    // Remove error styling on input
    $(document).on('input', '.oep-field-error', function() {
        $(this).removeClass('oep-field-error');
    });
});