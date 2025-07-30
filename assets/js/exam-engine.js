jQuery(document).ready(function($) {
    'use strict';

    // Global variables
    var examTimer = null;
    var examSessionId = null;
    var currentQuestion = 0;
    var totalQuestions = 0;
    var timeRemaining = 0;
    var examStartTime = null;
    var autoSaveInterval = null;
    var examData = {};
    var userAnswers = {};
    var isExamFinished = false;

    // Initialize exam engine
    initExamEngine();

    function initExamEngine() {
        // Check if we're on the exam page
        if (!$('#oep-exam-container').length) {
            return;
        }

        // Load exam state
        loadExamState();

        // Initialize event handlers
        initEventHandlers();

        // Initialize auto-save
        initAutoSave();

        // Prevent page refresh/close during exam
        initPageProtection();
    }

    function initEventHandlers() {
        // Start exam button
        $(document).on('click', '.oep-start-exam-btn', function(e) {
            e.preventDefault();
            var examId = $(this).data('exam-id');
            startExam(examId);
        });

        // Continue exam button
        $(document).on('click', '.oep-continue-exam-btn', function(e) {
            e.preventDefault();
            var sessionId = $(this).data('session-id');
            continueExam(sessionId);
        });

        // Navigation buttons
        $(document).on('click', '.oep-prev-question', function(e) {
            e.preventDefault();
            navigateToQuestion(currentQuestion - 1);
        });

        $(document).on('click', '.oep-next-question', function(e) {
            e.preventDefault();
            navigateToQuestion(currentQuestion + 1);
        });

        // Question navigation from sidebar
        $(document).on('click', '.oep-question-nav-item', function(e) {
            e.preventDefault();
            var questionIndex = $(this).data('question-index');
            navigateToQuestion(questionIndex);
        });

        // Answer selection
        $(document).on('change', 'input[name="question_answer"]', function() {
            var questionId = $(this).data('question-id');
            var answer = $(this).val();
            
            // Save answer locally
            userAnswers[questionId] = answer;
            
            // Update UI
            updateQuestionStatus(currentQuestion, 'answered');
            
            // Auto-save answer
            saveAnswer(questionId, answer);
        });

        // Finish exam button
        $(document).on('click', '.oep-finish-exam-btn', function(e) {
            e.preventDefault();
            confirmFinishExam();
        });

        // Timer warning acknowledgment
        $(document).on('click', '.oep-timer-warning-ok', function() {
            hideTimerWarning();
        });

        // Exam results modal
        $(document).on('click', '.oep-view-detailed-results', function(e) {
            e.preventDefault();
            showDetailedResults();
        });

        $(document).on('click', '.oep-close-results', function() {
            window.location.href = oep_exam_ajax.user_panel_url;
        });
    }

    function initAutoSave() {
        // Auto-save every 30 seconds
        autoSaveInterval = setInterval(function() {
            if (examSessionId && !isExamFinished) {
                autoSaveProgress();
            }
        }, 30000);
    }

    function initPageProtection() {
        // Warn before leaving page during exam
        $(window).on('beforeunload', function(e) {
            if (examSessionId && !isExamFinished) {
                var message = oep_exam_ajax.strings.leave_exam_warning;
                e.returnValue = message;
                return message;
            }
        });

        // Handle visibility change (tab switching)
        $(document).on('visibilitychange', function() {
            if (document.hidden && examSessionId && !isExamFinished) {
                // User switched tabs - could implement warnings here
                console.log('User switched away from exam tab');
            }
        });
    }

    function loadExamState() {
        var urlParams = new URLSearchParams(window.location.search);
        var examId = urlParams.get('exam_id');
        var sessionId = urlParams.get('session_id');

        if (sessionId) {
            // Continue existing exam
            continueExam(sessionId);
        } else if (examId) {
            // Show exam info and start button
            showExamInfo(examId);
        }
    }

    function showExamInfo(examId) {
        $.ajax({
            url: oep_exam_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_get_exam_info',
                exam_id: examId,
                nonce: oep_exam_ajax.nonce
            },
            beforeSend: function() {
                showLoading('#oep-exam-container');
            },
            success: function(response) {
                if (response.success) {
                    displayExamInfo(response.data);
                } else {
                    displayError(response.data || oep_exam_ajax.strings.error);
                }
            },
            error: function() {
                displayError(oep_exam_ajax.strings.ajax_error);
            },
            complete: function() {
                hideLoading('#oep-exam-container');
            }
        });
    }

    function startExam(examId) {
        $.ajax({
            url: oep_exam_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_start_exam',
                exam_id: examId,
                nonce: oep_exam_ajax.nonce
            },
            beforeSend: function() {
                $('.oep-start-exam-btn').prop('disabled', true).text(oep_exam_ajax.strings.starting_exam);
                showLoading('#oep-exam-container');
            },
            success: function(response) {
                if (response.success) {
                    examSessionId = response.data.session_id;
                    loadExamSession();
                } else {
                    displayError(response.data || oep_exam_ajax.strings.start_exam_error);
                }
            },
            error: function() {
                displayError(oep_exam_ajax.strings.ajax_error);
            },
            complete: function() {
                $('.oep-start-exam-btn').prop('disabled', false).text(oep_exam_ajax.strings.start_exam);
                hideLoading('#oep-exam-container');
            }
        });
    }

    function continueExam(sessionId) {
        examSessionId = sessionId;
        loadExamSession();
    }

    function loadExamSession() {
        $.ajax({
            url: oep_exam_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_get_exam_state',
                session_id: examSessionId,
                nonce: oep_exam_ajax.nonce
            },
            beforeSend: function() {
                showLoading('#oep-exam-container');
            },
            success: function(response) {
                if (response.success) {
                    examData = response.data;
                    initializeExam();
                } else {
                    displayError(response.data || oep_exam_ajax.strings.load_exam_error);
                }
            },
            error: function() {
                displayError(oep_exam_ajax.strings.ajax_error);
            },
            complete: function() {
                hideLoading('#oep-exam-container');
            }
        });
    }

    function initializeExam() {
        // Set exam variables
        totalQuestions = examData.questions.length;
        currentQuestion = examData.current_question || 0;
        userAnswers = examData.answers || {};
        timeRemaining = examData.time_remaining;
        examStartTime = new Date();

        // Display exam interface
        displayExamInterface();

        // Start timer if time limit is set
        if (timeRemaining > 0) {
            startTimer();
        }

        // Navigate to current question
        navigateToQuestion(currentQuestion);

        // Update question navigation
        updateQuestionNavigation();
    }

    function displayExamInterface() {
        var html = '<div class="oep-exam-interface">';
        
        // Header
        html += '<div class="oep-exam-header">';
        html += '<div class="oep-exam-title">' + examData.exam_title + '</div>';
        html += '<div class="oep-exam-meta">';
        html += '<span class="oep-question-counter">' + 
                '<span class="current-question">' + (currentQuestion + 1) + '</span> / ' + 
                '<span class="total-questions">' + totalQuestions + '</span></span>';
        if (timeRemaining > 0) {
            html += '<div class="oep-timer-container">';
            html += '<span class="oep-timer-label">' + oep_exam_ajax.strings.time_remaining + ':</span>';
            html += '<span class="oep-timer" id="oep-exam-timer">00:00</span>';
            html += '</div>';
        }
        html += '</div>';
        html += '</div>';

        // Main content
        html += '<div class="oep-exam-content">';
        
        // Question area
        html += '<div class="oep-question-area">';
        html += '<div class="oep-question-container" id="oep-current-question"></div>';
        html += '<div class="oep-question-navigation">';
        html += '<button class="oep-btn oep-btn-secondary oep-prev-question" disabled>' + 
                oep_exam_ajax.strings.previous_question + '</button>';
        html += '<button class="oep-btn oep-btn-primary oep-next-question">' + 
                oep_exam_ajax.strings.next_question + '</button>';
        html += '<button class="oep-btn oep-btn-success oep-finish-exam-btn" style="display: none;">' + 
                oep_exam_ajax.strings.finish_exam + '</button>';
        html += '</div>';
        html += '</div>';

        // Sidebar
        html += '<div class="oep-exam-sidebar">';
        html += '<div class="oep-progress-info">';
        html += '<h4>' + oep_exam_ajax.strings.exam_progress + '</h4>';
        html += '<div class="oep-progress-bar">';
        html += '<div class="oep-progress-fill" style="width: 0%"></div>';
        html += '</div>';
        html += '<div class="oep-progress-text">0 / ' + totalQuestions + ' ' + oep_exam_ajax.strings.answered + '</div>';
        html += '</div>';
        html += '<div class="oep-question-navigation-panel">';
        html += '<h4>' + oep_exam_ajax.strings.questions + '</h4>';
        html += '<div class="oep-question-nav-grid" id="oep-question-nav"></div>';
        html += '</div>';
        html += '</div>';

        html += '</div>';
        html += '</div>';

        // Timer warning modal
        html += '<div class="oep-modal" id="oep-timer-warning-modal" style="display: none;">';
        html += '<div class="oep-modal-content">';
        html += '<div class="oep-modal-header">';
        html += '<h3>' + oep_exam_ajax.strings.time_warning + '</h3>';
        html += '</div>';
        html += '<div class="oep-modal-body">';
        html += '<p id="oep-timer-warning-message"></p>';
        html += '</div>';
        html += '<div class="oep-modal-footer">';
        html += '<button class="oep-btn oep-btn-primary oep-timer-warning-ok">' + 
                oep_exam_ajax.strings.understood + '</button>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        $('#oep-exam-container').html(html);
    }

    function displayExamInfo(examInfo) {
        var html = '<div class="oep-exam-info">';
        html += '<div class="oep-exam-info-header">';
        html += '<h2>' + examInfo.title + '</h2>';
        if (examInfo.description) {
            html += '<div class="oep-exam-description">' + examInfo.description + '</div>';
        }
        html += '</div>';
        
        html += '<div class="oep-exam-specs">';
        html += '<div class="oep-spec-item">';
        html += '<span class="oep-spec-label">' + oep_exam_ajax.strings.questions_count + ':</span>';
        html += '<span class="oep-spec-value">' + examInfo.questions_count + '</span>';
        html += '</div>';
        
        if (examInfo.duration > 0) {
            html += '<div class="oep-spec-item">';
            html += '<span class="oep-spec-label">' + oep_exam_ajax.strings.duration + ':</span>';
            html += '<span class="oep-spec-value">' + examInfo.duration + ' ' + oep_exam_ajax.strings.minutes + '</span>';
            html += '</div>';
        }
        
        html += '<div class="oep-spec-item">';
        html += '<span class="oep-spec-label">' + oep_exam_ajax.strings.pass_score + ':</span>';
        html += '<span class="oep-spec-value">' + examInfo.pass_score + '%</span>';
        html += '</div>';
        
        if (examInfo.max_attempts > 0) {
            html += '<div class="oep-spec-item">';
            html += '<span class="oep-spec-label">' + oep_exam_ajax.strings.max_attempts + ':</span>';
            html += '<span class="oep-spec-value">' + examInfo.max_attempts + '</span>';
            html += '</div>';
        }
        html += '</div>';

        if (examInfo.has_active_session) {
            html += '<div class="oep-exam-actions">';
            html += '<button class="oep-btn oep-btn-warning oep-continue-exam-btn" data-session-id="' + 
                    examInfo.session_id + '">' + oep_exam_ajax.strings.continue_exam + '</button>';
            html += '<p class="oep-continue-note">' + oep_exam_ajax.strings.continue_exam_note + '</p>';
            html += '</div>';
        } else {
            html += '<div class="oep-exam-actions">';
            html += '<button class="oep-btn oep-btn-primary oep-start-exam-btn" data-exam-id="' + 
                    examInfo.id + '">' + oep_exam_ajax.strings.start_exam + '</button>';
            if (examInfo.duration > 0) {
                html += '<p class="oep-start-note">' + 
                        oep_exam_ajax.strings.timer_warning_note.replace('%d', examInfo.duration) + '</p>';
            }
            html += '</div>';
        }
        
        html += '</div>';
        
        $('#oep-exam-container').html(html);
    }

    function navigateToQuestion(questionIndex) {
        if (questionIndex < 0 || questionIndex >= totalQuestions) {
            return;
        }

        currentQuestion = questionIndex;
        displayCurrentQuestion();
        updateNavigationButtons();
        updateQuestionCounter();
        updateProgress();
    }

    function displayCurrentQuestion() {
        var question = examData.questions[currentQuestion];
        var questionId = question.id;
        
        var html = '<div class="oep-question" data-question-id="' + questionId + '">';
        html += '<div class="oep-question-header">';
        html += '<span class="oep-question-number">' + oep_exam_ajax.strings.question + ' ' + (currentQuestion + 1) + '</span>';
        html += '</div>';
        
        html += '<div class="oep-question-text">' + question.question + '</div>';
        
        html += '<div class="oep-question-options">';
        var options = ['a', 'b', 'c', 'd'];
        var userAnswer = userAnswers[questionId] || '';
        
        options.forEach(function(option) {
            if (question.options[option]) {
                var isChecked = userAnswer === option ? 'checked' : '';
                html += '<label class="oep-option-label">';
                html += '<input type="radio" name="question_answer" value="' + option + '" ' + 
                        'data-question-id="' + questionId + '" ' + isChecked + '>';
                html += '<span class="oep-option-text">' + 
                        '<span class="oep-option-letter">' + option.toUpperCase() + '</span>' +
                        question.options[option] + '</span>';
                html += '</label>';
            }
        });
        
        html += '</div>';
        html += '</div>';
        
        $('#oep-current-question').html(html);
    }

    function updateNavigationButtons() {
        $('.oep-prev-question').prop('disabled', currentQuestion === 0);
        
        if (currentQuestion === totalQuestions - 1) {
            $('.oep-next-question').hide();
            $('.oep-finish-exam-btn').show();
        } else {
            $('.oep-next-question').show();
            $('.oep-finish-exam-btn').hide();
        }
    }

    function updateQuestionCounter() {
        $('.current-question').text(currentQuestion + 1);
    }

    function updateQuestionNavigation() {
        var html = '';
        
        for (var i = 0; i < totalQuestions; i++) {
            var questionId = examData.questions[i].id;
            var status = userAnswers[questionId] ? 'answered' : 'unanswered';
            var isActive = i === currentQuestion ? 'active' : '';
            
            html += '<button class="oep-question-nav-item ' + status + ' ' + isActive + '" ' +
                    'data-question-index="' + i + '">' + (i + 1) + '</button>';
        }
        
        $('#oep-question-nav').html(html);
    }

    function updateQuestionStatus(questionIndex, status) {
        var $navItem = $('.oep-question-nav-item[data-question-index="' + questionIndex + '"]');
        $navItem.removeClass('answered unanswered').addClass(status);
        updateProgress();
    }

    function updateProgress() {
        var answeredCount = Object.keys(userAnswers).length;
        var progressPercent = (answeredCount / totalQuestions) * 100;
        
        $('.oep-progress-fill').css('width', progressPercent + '%');
        $('.oep-progress-text').text(answeredCount + ' / ' + totalQuestions + ' ' + oep_exam_ajax.strings.answered);
        
        // Update navigation
        updateQuestionNavigation();
    }

    function startTimer() {
        updateTimerDisplay();
        
        examTimer = setInterval(function() {
            timeRemaining--;
            updateTimerDisplay();
            
            // Show warnings
            if (timeRemaining === 300) { // 5 minutes
                showTimerWarning(oep_exam_ajax.strings.five_minutes_remaining);
            } else if (timeRemaining === 60) { // 1 minute
                showTimerWarning(oep_exam_ajax.strings.one_minute_remaining);
            } else if (timeRemaining <= 0) {
                // Time's up
                clearInterval(examTimer);
                autoFinishExam();
            }
        }, 1000);
    }

    function updateTimerDisplay() {
        var hours = Math.floor(timeRemaining / 3600);
        var minutes = Math.floor((timeRemaining % 3600) / 60);
        var seconds = timeRemaining % 60;
        
        var display = '';
        if (hours > 0) {
            display = hours + ':' + (minutes < 10 ? '0' : '') + minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
        } else {
            display = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
        }
        
        $('#oep-exam-timer').text(display);
        
        // Change color when time is running low
        if (timeRemaining <= 300) { // 5 minutes
            $('#oep-exam-timer').addClass('oep-timer-warning');
        }
        if (timeRemaining <= 60) { // 1 minute
            $('#oep-exam-timer').addClass('oep-timer-critical');
        }
    }

    function showTimerWarning(message) {
        $('#oep-timer-warning-message').text(message);
        $('#oep-timer-warning-modal').fadeIn(300);
    }

    function hideTimerWarning() {
        $('#oep-timer-warning-modal').fadeOut(300);
    }

    function saveAnswer(questionId, answer) {
        $.ajax({
            url: oep_exam_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_submit_answer',
                session_id: examSessionId,
                question_id: questionId,
                answer: answer,
                current_question: currentQuestion,
                nonce: oep_exam_ajax.nonce
            },
            success: function(response) {
                if (!response.success) {
                    console.log('Failed to save answer:', response.data);
                }
            },
            error: function() {
                console.log('Error saving answer');
            }
        });
    }

    function autoSaveProgress() {
        $.ajax({
            url: oep_exam_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_auto_save_progress',
                session_id: examSessionId,
                current_question: currentQuestion,
                time_remaining: timeRemaining,
                nonce: oep_exam_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    console.log('Progress auto-saved');
                }
            }
        });
    }

    function confirmFinishExam() {
        var answeredCount = Object.keys(userAnswers).length;
        var unansweredCount = totalQuestions - answeredCount;
        
        var message = oep_exam_ajax.strings.confirm_finish_exam;
        if (unansweredCount > 0) {
            message += '\n\n' + oep_exam_ajax.strings.unanswered_questions.replace('%d', unansweredCount);
        }
        
        if (confirm(message)) {
            finishExam();
        }
    }

    function finishExam() {
        isExamFinished = true;
        
        // Clear intervals
        if (examTimer) {
            clearInterval(examTimer);
        }
        if (autoSaveInterval) {
            clearInterval(autoSaveInterval);
        }
        
        $.ajax({
            url: oep_exam_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'oep_finish_exam',
                session_id: examSessionId,
                time_spent: examData.duration * 60 - timeRemaining,
                nonce: oep_exam_ajax.nonce
            },
            beforeSend: function() {
                showLoading('#oep-exam-container');
                $('.oep-finish-exam-btn').prop('disabled', true).text(oep_exam_ajax.strings.finishing_exam);
            },
            success: function(response) {
                if (response.success) {
                    displayExamResults(response.data);
                } else {
                    displayError(response.data || oep_exam_ajax.strings.finish_exam_error);
                }
            },
            error: function() {
                displayError(oep_exam_ajax.strings.ajax_error);
            },
            complete: function() {
                hideLoading('#oep-exam-container');
            }
        });
    }

    function autoFinishExam() {
        isExamFinished = true;
        
        showMessage('warning', oep_exam_ajax.strings.time_up_auto_submit);
        
        setTimeout(function() {
            finishExam();
        }, 2000);
    }

    function displayExamResults(results) {
        var html = '<div class="oep-exam-results">';
        
        html += '<div class="oep-results-header">';
        html += '<h2>' + oep_exam_ajax.strings.exam_completed + '</h2>';
        html += '<div class="oep-exam-title">' + examData.exam_title + '</div>';
        html += '</div>';
        
        html += '<div class="oep-results-summary">';
        html += '<div class="oep-score-display">';
        html += '<div class="oep-score-circle ' + (results.passed ? 'passed' : 'failed') + '">';
        html += '<div class="oep-score-number">' + results.score + '%</div>';
        html += '<div class="oep-score-label">' + oep_exam_ajax.strings.your_score + '</div>';
        html += '</div>';
        html += '</div>';
        
        html += '<div class="oep-results-details">';
        html += '<div class="oep-result-item">';
        html += '<span class="oep-result-label">' + oep_exam_ajax.strings.status + ':</span>';
        html += '<span class="oep-result-value oep-status-' + (results.passed ? 'passed' : 'failed') + '">';
        html += results.passed ? oep_exam_ajax.strings.passed : oep_exam_ajax.strings.failed;
        html += '</span>';
        html += '</div>';
        
        html += '<div class="oep-result-item">';
        html += '<span class="oep-result-label">' + oep_exam_ajax.strings.correct_answers + ':</span>';
        html += '<span class="oep-result-value">' + results.correct_answers + ' / ' + results.total_questions + '</span>';
        html += '</div>';
        
        html += '<div class="oep-result-item">';
        html += '<span class="oep-result-label">' + oep_exam_ajax.strings.time_spent + ':</span>';
        html += '<span class="oep-result-value">' + formatTime(results.time_spent) + '</span>';
        html += '</div>';
        
        if (results.pass_score) {
            html += '<div class="oep-result-item">';
            html += '<span class="oep-result-label">' + oep_exam_ajax.strings.required_score + ':</span>';
            html += '<span class="oep-result-value">' + results.pass_score + '%</span>';
            html += '</div>';
        }
        html += '</div>';
        html += '</div>';
        
        if (results.show_answers && results.detailed_answers) {
            html += '<div class="oep-results-actions">';
            html += '<button class="oep-btn oep-btn-secondary oep-view-detailed-results">' + 
                    oep_exam_ajax.strings.view_detailed_results + '</button>';
            html += '</div>';
        }
        
        html += '<div class="oep-results-footer">';
        html += '<button class="oep-btn oep-btn-primary oep-close-results">' + 
                oep_exam_ajax.strings.back_to_panel + '</button>';
        html += '</div>';
        
        html += '</div>';
        
        // Store detailed results for later display
        if (results.detailed_answers) {
            window.oepDetailedResults = results.detailed_answers;
        }
        
        $('#oep-exam-container').html(html);
    }

    function showDetailedResults() {
        if (!window.oepDetailedResults) {
            return;
        }
        
        var html = '<div class="oep-detailed-results">';
        html += '<div class="oep-detailed-header">';
        html += '<h3>' + oep_exam_ajax.strings.detailed_results + '</h3>';
        html += '<button class="oep-btn oep-btn-secondary oep-back-to-summary">' + 
                oep_exam_ajax.strings.back_to_summary + '</button>';
        html += '</div>';
        
        html += '<div class="oep-answers-review">';
        
        window.oepDetailedResults.forEach(function(answer, index) {
            html += '<div class="oep-answer-review-item ' + (answer.is_correct ? 'correct' : 'incorrect') + '">';
            html += '<div class="oep-question-header">';
            html += '<span class="oep-question-number">' + oep_exam_ajax.strings.question + ' ' + (index + 1) + '</span>';
            html += '<span class="oep-answer-status">';
            html += answer.is_correct ? 
                '<i class="oep-icon-check"></i> ' + oep_exam_ajax.strings.correct :
                '<i class="oep-icon-x"></i> ' + oep_exam_ajax.strings.incorrect;
            html += '</span>';
            html += '</div>';
            
            html += '<div class="oep-question-text">' + answer.question + '</div>';
            
            html += '<div class="oep-answer-comparison">';
            html += '<div class="oep-user-answer">';
            html += '<strong>' + oep_exam_ajax.strings.your_answer + ':</strong> ';
            html += answer.user_answer ? answer.user_answer.toUpperCase() + ') ' + answer.user_answer_text : 
                    oep_exam_ajax.strings.no_answer;
            html += '</div>';
            
            html += '<div class="oep-correct-answer">';
            html += '<strong>' + oep_exam_ajax.strings.correct_answer + ':</strong> ';
            html += answer.correct_answer.toUpperCase() + ') ' + answer.correct_answer_text;
            html += '</div>';
            html += '</div>';
            
            if (answer.explanation) {
                html += '<div class="oep-answer-explanation">';
                html += '<strong>' + oep_exam_ajax.strings.explanation + ':</strong> ';
                html += answer.explanation;
                html += '</div>';
            }
            
            html += '</div>';
        });
        
        html += '</div>';
        html += '</div>';
        
        $('#oep-exam-container').html(html);
    }

    // Back to summary button
    $(document).on('click', '.oep-back-to-summary', function() {
        // This would require storing the summary results, simplified for now
        location.reload();
    });

    // Utility functions
    function showLoading(selector) {
        $(selector).append('<div class="oep-loading-overlay"><div class="oep-spinner"></div><div class="oep-loading-text">' + 
                          oep_exam_ajax.strings.loading + '</div></div>');
    }

    function hideLoading(selector) {
        $(selector).find('.oep-loading-overlay').remove();
    }

    function displayError(message) {
        var html = '<div class="oep-error-container">';
        html += '<div class="oep-error-icon">⚠️</div>';
        html += '<div class="oep-error-message">' + message + '</div>';
        html += '<div class="oep-error-actions">';
        html += '<button class="oep-btn oep-btn-primary" onclick="location.reload()">' + 
                oep_exam_ajax.strings.try_again + '</button>';
        html += '<a href="' + oep_exam_ajax.user_panel_url + '" class="oep-btn oep-btn-secondary">' + 
                oep_exam_ajax.strings.back_to_panel + '</a>';
        html += '</div>';
        html += '</div>';
        
        $('#oep-exam-container').html(html);
    }

    function showMessage(type, message) {
        var messageHtml = '<div class="oep-message oep-message-' + type + '">' + message + '</div>';
        
        // Remove existing messages
        $('.oep-message').remove();
        
        // Add new message
        $('body').prepend('<div class="oep-messages-container">' + messageHtml + '</div>');
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $('.oep-message').fadeOut();
        }, 5000);
        
        // Scroll to top to show message
        $('html, body').animate({scrollTop: 0}, 300);
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

    // Cleanup on page unload
    $(window).on('unload', function() {
        if (examTimer) {
            clearInterval(examTimer);
        }
        if (autoSaveInterval) {
            clearInterval(autoSaveInterval);
        }
    });
});