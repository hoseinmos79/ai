jQuery(document).ready(function($) {
    'use strict';
    
    var examState = {
        sessionId: null,
        currentQuestion: 0,
        totalQuestions: 0,
        questions: [],
        answers: {},
        timeRemaining: 0,
        timerInterval: null,
        autoSaveInterval: null
    };
    
    // Start exam
    $('.oep-start-exam, .oep-start-new-exam').on('click', function(e) {
        e.preventDefault();
        
        var examId = $('.oep-exam-container').data('exam-id');
        startExam(examId);
    });
    
    // Resume exam
    $('.oep-resume-exam').on('click', function(e) {
        e.preventDefault();
        
        var sessionId = $(this).data('session-id');
        resumeExam(sessionId);
    });
    
    // Question navigation
    $(document).on('click', '.oep-prev-question', function(e) {
        e.preventDefault();
        if (examState.currentQuestion > 0) {
            saveCurrentAnswer();
            examState.currentQuestion--;
            displayQuestion();
            updateNavigation();
        }
    });
    
    $(document).on('click', '.oep-next-question', function(e) {
        e.preventDefault();
        if (examState.currentQuestion < examState.totalQuestions - 1) {
            saveCurrentAnswer();
            examState.currentQuestion++;
            displayQuestion();
            updateNavigation();
        }
    });
    
    // Answer selection
    $(document).on('click', '.oep-options li', function() {
        $('.oep-options li').removeClass('selected');
        $(this).addClass('selected');
        
        var answer = $(this).data('option');
        examState.answers[examState.currentQuestion] = answer;
        
        // Auto-save answer
        saveAnswerToServer(examState.currentQuestion, answer);
    });
    
    // Finish exam
    $(document).on('click', '.oep-finish-exam', function(e) {
        e.preventDefault();
        
        if (confirm(oep_exam.strings.confirm_finish)) {
            finishExam();
        }
    });
    
    // Keyboard navigation
    $(document).on('keydown', function(e) {
        if ($('.oep-exam-interface').is(':visible')) {
            switch(e.keyCode) {
                case 37: // Left arrow (previous in RTL)
                    if (examState.currentQuestion < examState.totalQuestions - 1) {
                        $('.oep-next-question').click();
                    }
                    break;
                case 39: // Right arrow (next in RTL)
                    if (examState.currentQuestion > 0) {
                        $('.oep-prev-question').click();
                    }
                    break;
                case 49: case 50: case 51: case 52: // Number keys 1-4
                    var optionIndex = e.keyCode - 49;
                    $('.oep-options li').eq(optionIndex).click();
                    break;
            }
        }
    });
    
    // Prevent leaving page during exam
    $(window).on('beforeunload', function(e) {
        if (examState.sessionId && $('.oep-exam-interface').is(':visible')) {
            return oep_exam.strings.confirm_leave || 'آیا از خروج از آزمون اطمینان دارید؟';
        }
    });
    
    // Functions
    function startExam(examId) {
        $.ajax({
            url: oep_exam.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_start_exam',
                exam_id: examId,
                nonce: oep_exam.nonce
            },
            beforeSend: function() {
                showLoading('در حال شروع آزمون...');
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    examState.sessionId = response.data.session_id;
                    examState.totalQuestions = response.data.total_questions;
                    
                    if (response.data.duration) {
                        examState.timeRemaining = response.data.duration * 60; // Convert to seconds
                    }
                    
                    loadExamState();
                } else {
                    showMessage('error', response.data || 'خطا در شروع آزمون');
                }
            },
            error: function() {
                hideLoading();
                showMessage('error', oep_exam.strings.connection_error);
            }
        });
    }
    
    function resumeExam(sessionId) {
        examState.sessionId = sessionId;
        loadExamState();
    }
    
    function loadExamState() {
        $.ajax({
            url: oep_exam.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_get_exam_state',
                session_id: examState.sessionId,
                nonce: oep_exam.nonce
            },
            beforeSend: function() {
                showLoading('در حال بارگذاری آزمون...');
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    examState.questions = response.data.questions;
                    examState.answers = response.data.answers;
                    examState.totalQuestions = examState.questions.length;
                    examState.timeRemaining = response.data.remaining_time;
                    
                    startExamInterface();
                } else {
                    showMessage('error', response.data || 'خطا در بارگذاری آزمون');
                }
            },
            error: function() {
                hideLoading();
                showMessage('error', oep_exam.strings.connection_error);
            }
        });
    }
    
    function startExamInterface() {
        // Hide start section and show exam interface
        $('.oep-exam-start, .oep-exam-resume').hide();
        $('.oep-exam-interface').show();
        
        // Update progress info
        $('.oep-progress .total').text(examState.totalQuestions);
        
        // Start timer if needed
        if (examState.timeRemaining > 0) {
            $('.oep-timer').show();
            startTimer();
        }
        
        // Display first question
        displayQuestion();
        updateNavigation();
        
        // Start auto-save
        startAutoSave();
    }
    
    function displayQuestion() {
        if (!examState.questions[examState.currentQuestion]) {
            return;
        }
        
        var question = examState.questions[examState.currentQuestion];
        var selectedAnswer = examState.answers[examState.currentQuestion];
        
        var html = '<div class="oep-question">';
        html += '<h3>' + question.title + '</h3>';
        
        if (question.content && question.content !== question.title) {
            html += '<div class="oep-question-content">' + question.content + '</div>';
        }
        
        html += '<ul class="oep-options">';
        
        var options = ['a', 'b', 'c', 'd'];
        var optionLabels = ['الف', 'ب', 'ج', 'د'];
        
        options.forEach(function(option, index) {
            if (question.options[option]) {
                var isSelected = selectedAnswer === option ? ' selected' : '';
                html += '<li class="oep-option' + isSelected + '" data-option="' + option + '">';
                html += '<span class="option-label">' + optionLabels[index] + ')</span> ';
                html += question.options[option];
                html += '</li>';
            }
        });
        
        html += '</ul>';
        html += '</div>';
        
        $('.oep-question-container').html(html);
        
        // Update progress
        $('.oep-progress .current').text(examState.currentQuestion + 1);
        var progressPercent = ((examState.currentQuestion + 1) / examState.totalQuestions) * 100;
        $('.oep-progress-fill').css('width', progressPercent + '%');
    }
    
    function updateNavigation() {
        // Update previous button
        if (examState.currentQuestion === 0) {
            $('.oep-prev-question').prop('disabled', true);
        } else {
            $('.oep-prev-question').prop('disabled', false);
        }
        
        // Update next/finish button
        if (examState.currentQuestion === examState.totalQuestions - 1) {
            $('.oep-next-question').hide();
            $('.oep-finish-exam').show();
        } else {
            $('.oep-next-question').show();
            $('.oep-finish-exam').hide();
        }
    }
    
    function saveCurrentAnswer() {
        var selectedOption = $('.oep-options li.selected').data('option');
        if (selectedOption) {
            examState.answers[examState.currentQuestion] = selectedOption;
        }
    }
    
    function saveAnswerToServer(questionIndex, answer) {
        $.ajax({
            url: oep_exam.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_submit_answer',
                session_id: examState.sessionId,
                question_index: questionIndex,
                answer: answer,
                nonce: oep_exam.nonce
            },
            success: function(response) {
                if (response.success) {
                    showSaveStatus('saved');
                } else if (response.data && response.data.indexOf('زمان') !== -1) {
                    // Time expired
                    finishExam();
                }
            },
            error: function() {
                showSaveStatus('error');
            }
        });
    }
    
    function startTimer() {
        updateTimerDisplay();
        
        examState.timerInterval = setInterval(function() {
            examState.timeRemaining--;
            updateTimerDisplay();
            
            if (examState.timeRemaining <= 0) {
                clearInterval(examState.timerInterval);
                alert(oep_exam.strings.time_up);
                finishExam();
            } else if (examState.timeRemaining <= 300) { // 5 minutes warning
                $('.oep-timer').addClass('warning');
            }
        }, 1000);
    }
    
    function updateTimerDisplay() {
        var hours = Math.floor(examState.timeRemaining / 3600);
        var minutes = Math.floor((examState.timeRemaining % 3600) / 60);
        var seconds = examState.timeRemaining % 60;
        
        var display = '';
        if (hours > 0) {
            display = pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
        } else {
            display = pad(minutes) + ':' + pad(seconds);
        }
        
        $('.oep-timer-value').text(display);
    }
    
    function startAutoSave() {
        examState.autoSaveInterval = setInterval(function() {
            if (examState.sessionId) {
                // Auto-save current state
                var currentAnswer = $('.oep-options li.selected').data('option');
                if (currentAnswer) {
                    examState.answers[examState.currentQuestion] = currentAnswer;
                    saveAnswerToServer(examState.currentQuestion, currentAnswer);
                }
            }
        }, 30000); // Save every 30 seconds
    }
    
    function finishExam() {
        // Save current answer before finishing
        saveCurrentAnswer();
        
        // Clear intervals
        if (examState.timerInterval) {
            clearInterval(examState.timerInterval);
        }
        if (examState.autoSaveInterval) {
            clearInterval(examState.autoSaveInterval);
        }
        
        $.ajax({
            url: oep_exam.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_finish_exam',
                session_id: examState.sessionId,
                nonce: oep_exam.nonce
            },
            beforeSend: function() {
                showLoading('در حال محاسبه نتایج...');
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    displayResults(response.data);
                } else {
                    showMessage('error', response.data || 'خطا در پایان آزمون');
                }
            },
            error: function() {
                hideLoading();
                showMessage('error', oep_exam.strings.connection_error);
            }
        });
    }
    
    function displayResults(results) {
        $('.oep-exam-interface').hide();
        
        var resultClass = results.passed ? 'oep-result-passed' : 'oep-result-failed';
        var statusText = results.passed ? 'قبول' : 'مردود';
        
        var html = '<div class="oep-result-header">';
        html += '<h2>نتیجه آزمون</h2>';
        html += '<div class="oep-result-score ' + resultClass + '">' + Math.round(results.score) + '%</div>';
        html += '<div class="oep-result-status">' + statusText + '</div>';
        html += '</div>';
        
        html += '<div class="oep-result-details">';
        html += '<p><strong>تعداد پاسخ‌های صحیح:</strong> ' + results.correct_answers + ' از ' + results.total_questions + '</p>';
        html += '<p><strong>درصد موفقیت:</strong> ' + Math.round(results.score) + '%</p>';
        html += '</div>';
        
        html += '<div class="oep-result-actions">';
        html += '<button class="button button-primary" onclick="window.print()">چاپ نتیجه</button>';
        html += '<button class="button" onclick="window.location.reload()">بازگشت</button>';
        html += '</div>';
        
        // Show detailed answers if enabled
        if (examState.questions && examState.questions.length > 0) {
            html += '<div class="oep-detailed-results">';
            html += '<h3>بررسی پاسخ‌ها</h3>';
            html += '<div class="oep-answers-review">';
            
            examState.questions.forEach(function(question, index) {
                var userAnswer = examState.answers[index];
                var correctAnswer = question.correct_answer;
                var isCorrect = userAnswer === correctAnswer;
                
                html += '<div class="oep-answer-item ' + (isCorrect ? 'correct' : 'incorrect') + '">';
                html += '<h4>سوال ' + (index + 1) + ': ' + question.title + '</h4>';
                
                if (userAnswer) {
                    html += '<p><strong>پاسخ شما:</strong> ' + getOptionLabel(userAnswer) + ') ' + question.options[userAnswer] + '</p>';
                } else {
                    html += '<p><strong>پاسخ شما:</strong> پاسخ داده نشده</p>';
                }
                
                html += '<p><strong>پاسخ صحیح:</strong> ' + getOptionLabel(correctAnswer) + ') ' + question.options[correctAnswer] + '</p>';
                
                if (question.explanation) {
                    html += '<p class="oep-explanation"><strong>توضیح:</strong> ' + question.explanation + '</p>';
                }
                
                html += '</div>';
            });
            
            html += '</div>';
            html += '</div>';
        }
        
        $('.oep-exam-result').html(html).show();
        
        // Remove beforeunload handler
        $(window).off('beforeunload');
    }
    
    function showLoading(message) {
        var html = '<div class="oep-loading-overlay">';
        html += '<div class="oep-loading-content">';
        html += '<div class="oep-spinner"></div>';
        html += '<p>' + message + '</p>';
        html += '</div>';
        html += '</div>';
        
        $('body').append(html);
    }
    
    function hideLoading() {
        $('.oep-loading-overlay').remove();
    }
    
    function showMessage(type, message) {
        var messageClass = 'oep-message-' + type;
        var $message = $('<div class="oep-message ' + messageClass + '">' + message + '</div>');
        
        $('.oep-message').remove();
        $('.oep-exam-container').prepend($message);
        
        setTimeout(function() {
            $message.fadeOut();
        }, 5000);
    }
    
    function showSaveStatus(status) {
        var $indicator = $('.oep-save-indicator');
        if ($indicator.length === 0) {
            $indicator = $('<div class="oep-save-indicator"></div>');
            $('.oep-exam-controls').append($indicator);
        }
        
        $indicator.removeClass('saving saved error');
        
        switch(status) {
            case 'saving':
                $indicator.addClass('saving').text(oep_exam.strings.saving);
                break;
            case 'saved':
                $indicator.addClass('saved').text(oep_exam.strings.saved);
                setTimeout(function() {
                    $indicator.fadeOut();
                }, 2000);
                break;
            case 'error':
                $indicator.addClass('error').text('خطا در ذخیره');
                break;
        }
        
        $indicator.show();
    }
    
    function getOptionLabel(option) {
        var labels = {
            'a': 'الف',
            'b': 'ب', 
            'c': 'ج',
            'd': 'د'
        };
        return labels[option] || option;
    }
    
    function pad(num) {
        return num < 10 ? '0' + num : num;
    }
    
    // Add dynamic styles
    if ($('#oep-exam-styles').length === 0) {
        $('<style id="oep-exam-styles">')
            .html(`
                .oep-loading-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.7);
                    z-index: 10000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .oep-loading-content {
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    text-align: center;
                    direction: rtl;
                }
                .oep-spinner {
                    width: 40px;
                    height: 40px;
                    border: 4px solid #f3f3f3;
                    border-top: 4px solid #3498db;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                    margin: 0 auto 15px;
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .oep-timer.warning {
                    color: #dc3545;
                    animation: pulse 1s infinite;
                }
                @keyframes pulse {
                    0% { opacity: 1; }
                    50% { opacity: 0.5; }
                    100% { opacity: 1; }
                }
                .oep-save-indicator {
                    position: absolute;
                    top: 10px;
                    left: 10px;
                    font-size: 12px;
                    padding: 5px 10px;
                    border-radius: 3px;
                }
                .oep-save-indicator.saving {
                    background: #fff3cd;
                    color: #856404;
                }
                .oep-save-indicator.saved {
                    background: #d4edda;
                    color: #155724;
                }
                .oep-save-indicator.error {
                    background: #f8d7da;
                    color: #721c24;
                }
                .oep-answer-item {
                    margin-bottom: 20px;
                    padding: 15px;
                    border-radius: 5px;
                    border: 1px solid #ddd;
                }
                .oep-answer-item.correct {
                    background: #d4edda;
                    border-color: #c3e6cb;
                }
                .oep-answer-item.incorrect {
                    background: #f8d7da;
                    border-color: #f5c6cb;
                }
                .oep-explanation {
                    font-style: italic;
                    color: #666;
                    margin-top: 10px;
                }
            `)
            .appendTo('head');
    }
});