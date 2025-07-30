<?php
/**
 * User Panel for Online Exam Payment Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEP_User_Panel {
    
    public function __construct() {
        add_action('wp_ajax_oep_get_user_exams', array($this, 'get_user_exams'));
        add_action('wp_ajax_oep_get_exam_results', array($this, 'get_exam_results'));
    }
    
    public function get_user_exams() {
        check_ajax_referer('oep_user_panel_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('برای مشاهده آزمون‌ها باید وارد سایت شوید.', 'online-exam-payment'));
        }
        
        $user_id = get_current_user_id();
        $purchased_exams = get_user_meta($user_id, 'oep_purchased_exams', true) ?: array();
        
        // Get all published exams
        $all_exams = get_posts(array(
            'post_type' => 'oep_exam',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ));
        
        $user_exams = array();
        
        foreach ($all_exams as $exam) {
            $price = get_post_meta($exam->ID, '_oep_exam_price', true);
            $has_access = $price == 0 || in_array($exam->ID, $purchased_exams);
            
            if ($has_access) {
                $user_exams[] = $this->format_exam_data($exam, $user_id);
            }
        }
        
        wp_send_json_success($user_exams);
    }
    
    public function get_exam_results() {
        check_ajax_referer('oep_user_panel_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('دسترسی غیرمجاز', 'online-exam-payment'));
        }
        
        $exam_id = intval($_POST['exam_id']);
        $user_id = get_current_user_id();
        
        if (!$exam_id) {
            wp_send_json_error(__('آزمون مشخص نشده است.', 'online-exam-payment'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_results';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND exam_id = %d ORDER BY completed_at DESC",
            $user_id, $exam_id
        ));
        
        $formatted_results = array();
        foreach ($results as $result) {
            $answers = json_decode($result->answers, true) ?: array();
            
            $formatted_results[] = array(
                'id' => $result->id,
                'score' => round($result->score, 1),
                'total_questions' => $result->total_questions,
                'correct_answers' => $result->correct_answers,
                'time_spent' => $this->format_time($result->time_spent),
                'passed' => $result->passed,
                'completed_at' => date_i18n('Y/m/d H:i', strtotime($result->completed_at)),
                'answers' => $answers
            );
        }
        
        wp_send_json_success($formatted_results);
    }
    
    private function format_exam_data($exam, $user_id) {
        global $wpdb;
        
        // Get exam settings
        $price = get_post_meta($exam->ID, '_oep_exam_price', true);
        $duration = get_post_meta($exam->ID, '_oep_exam_duration', true);
        $pass_score = get_post_meta($exam->ID, '_oep_exam_pass_score', true) ?: 60;
        $max_attempts = get_post_meta($exam->ID, '_oep_exam_max_attempts', true) ?: 1;
        
        // Get questions count
        $questions_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_oep_question_exam_id' AND meta_value = %d",
            $exam->ID
        ));
        
        // Get user's attempts
        $results_table = $wpdb->prefix . 'oep_exam_results';
        $attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $results_table WHERE user_id = %d AND exam_id = %d",
            $user_id, $exam->ID
        ));
        
        // Get last result
        $last_result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $results_table WHERE user_id = %d AND exam_id = %d ORDER BY completed_at DESC LIMIT 1",
            $user_id, $exam->ID
        ));
        
        // Check for active session
        $sessions_table = $wpdb->prefix . 'oep_exam_sessions';
        $active_session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE user_id = %d AND exam_id = %d AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())",
            $user_id, $exam->ID
        ));
        
        return array(
            'id' => $exam->ID,
            'title' => $exam->post_title,
            'description' => $exam->post_excerpt ?: wp_trim_words($exam->post_content, 20),
            'price' => $price,
            'duration' => $duration,
            'pass_score' => $pass_score,
            'questions_count' => $questions_count,
            'max_attempts' => $max_attempts,
            'attempts' => $attempts,
            'can_attempt' => $attempts < $max_attempts,
            'has_active_session' => !empty($active_session),
            'session_id' => $active_session ? $active_session->id : null,
            'last_result' => $last_result ? array(
                'score' => round($last_result->score, 1),
                'passed' => $last_result->passed,
                'completed_at' => date_i18n('Y/m/d H:i', strtotime($last_result->completed_at)),
                'time_spent' => $this->format_time($last_result->time_spent)
            ) : null
        );
    }
    
    private function format_time($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        } else {
            return sprintf('%02d:%02d', $minutes, $seconds);
        }
    }
    
    public function display_user_panel() {
        if (!is_user_logged_in()) {
            return '<div class="oep-login-required">' . 
                   sprintf(__('برای مشاهده پنل کاربری باید <a href="%s">وارد سایت</a> شوید.', 'online-exam-payment'), wp_login_url()) . 
                   '</div>';
        }
        
        $user = wp_get_current_user();
        
        ob_start();
        ?>
        <div class="oep-user-panel" id="oep-user-panel">
            <div class="oep-user-panel-header">
                <h2><?php printf(__('پنل کاربری - %s', 'online-exam-payment'), $user->display_name); ?></h2>
            </div>
            
            <div class="oep-user-panel-tabs">
                <button class="oep-tab-button active" data-tab="exams">
                    <?php _e('آزمون‌های من', 'online-exam-payment'); ?>
                </button>
                <button class="oep-tab-button" data-tab="results">
                    <?php _e('نتایج آزمون‌ها', 'online-exam-payment'); ?>
                </button>
                <button class="oep-tab-button" data-tab="profile">
                    <?php _e('پروفایل', 'online-exam-payment'); ?>
                </button>
            </div>
            
            <div class="oep-tab-content">
                <div id="oep-tab-exams" class="oep-tab-panel active">
                    <div class="oep-loading">
                        <p><?php _e('در حال بارگذاری آزمون‌ها...', 'online-exam-payment'); ?></p>
                    </div>
                    <div id="oep-user-exams" style="display: none;"></div>
                </div>
                
                <div id="oep-tab-results" class="oep-tab-panel">
                    <div class="oep-results-container">
                        <p><?php _e('برای مشاهده نتایج، ابتدا یکی از آزمون‌های خود را انتخاب کنید.', 'online-exam-payment'); ?></p>
                        <div id="oep-exam-results" style="display: none;"></div>
                    </div>
                </div>
                
                <div id="oep-tab-profile" class="oep-tab-panel">
                    <div class="oep-profile-info">
                        <h3><?php _e('اطلاعات حساب کاربری', 'online-exam-payment'); ?></h3>
                        <table class="oep-profile-table">
                            <tr>
                                <th><?php _e('نام کاربری:', 'online-exam-payment'); ?></th>
                                <td><?php echo esc_html($user->user_login); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('نام نمایشی:', 'online-exam-payment'); ?></th>
                                <td><?php echo esc_html($user->display_name); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('ایمیل:', 'online-exam-payment'); ?></th>
                                <td><?php echo esc_html($user->user_email); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('تاریخ عضویت:', 'online-exam-payment'); ?></th>
                                <td><?php echo date_i18n('Y/m/d', strtotime($user->user_registered)); ?></td>
                            </tr>
                        </table>
                        
                        <div class="oep-profile-actions">
                            <a href="<?php echo admin_url('profile.php'); ?>" class="oep-btn oep-btn-primary">
                                <?php _e('ویرایش پروفایل', 'online-exam-payment'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .oep-user-panel {
                font-family: 'Tahoma', 'Arial', sans-serif;
                direction: rtl;
                text-align: right;
                max-width: 1000px;
                margin: 0 auto;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            
            .oep-user-panel-header {
                background: #2196F3;
                color: white;
                padding: 20px;
                text-align: center;
            }
            
            .oep-user-panel-header h2 {
                margin: 0;
                font-size: 24px;
            }
            
            .oep-user-panel-tabs {
                display: flex;
                background: #f8f9fa;
                border-bottom: 1px solid #dee2e6;
            }
            
            .oep-tab-button {
                flex: 1;
                padding: 15px 20px;
                border: none;
                background: transparent;
                cursor: pointer;
                font-size: 16px;
                transition: all 0.3s;
            }
            
            .oep-tab-button:hover {
                background: #e9ecef;
            }
            
            .oep-tab-button.active {
                background: #2196F3;
                color: white;
            }
            
            .oep-tab-content {
                padding: 30px;
            }
            
            .oep-tab-panel {
                display: none;
            }
            
            .oep-tab-panel.active {
                display: block;
            }
            
            .oep-loading {
                text-align: center;
                padding: 50px;
                color: #666;
            }
            
            .oep-exam-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            
            .oep-exam-card {
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 20px;
                background: #f8f9fa;
                transition: all 0.3s;
            }
            
            .oep-exam-card:hover {
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                transform: translateY(-2px);
            }
            
            .oep-exam-title {
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 10px;
                color: #333;
            }
            
            .oep-exam-description {
                font-size: 14px;
                color: #555;
                margin-bottom: 10px;
                height: 3.6em; /* Show 3 lines of text */
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .oep-exam-meta {
                margin: 15px 0;
                color: #666;
                font-size: 14px;
            }
            
            .oep-exam-meta span {
                display: block;
                margin-bottom: 5px;
            }
            
            .oep-exam-actions {
                margin-top: 15px;
            }
            
            .oep-btn {
                display: inline-block;
                padding: 8px 16px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                text-decoration: none;
                font-size: 14px;
                margin-left: 10px;
                transition: all 0.3s;
            }
            
            .oep-btn-primary {
                background: #2196F3;
                color: white;
            }
            
            .oep-btn-success {
                background: #28a745;
                color: white;
            }
            
            .oep-btn-secondary {
                background: #6c757d;
                color: white;
            }
            
            .oep-btn-warning {
                background: #ffc107;
                color: #212529;
            }
            
            .oep-btn:hover {
                opacity: 0.9;
                transform: translateY(-1px);
            }
            
            .oep-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
                transform: none;
            }
            
            .oep-status-badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: bold;
            }
            
            .oep-status-passed {
                background: #d4edda;
                color: #155724;
            }
            
            .oep-status-failed {
                background: #f8d7da;
                color: #721c24;
            }
            
            .oep-status-active {
                background: #fff3cd;
                color: #856404;
            }
            
            .oep-results-list {
                margin-top: 20px;
            }
            
            .oep-result-item {
                border: 1px solid #dee2e6;
                border-radius: 6px;
                padding: 15px;
                margin-bottom: 15px;
                background: white;
            }
            
            .oep-result-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }
            
            .oep-result-score {
                font-size: 18px;
                font-weight: bold;
            }
            
            .oep-result-details {
                color: #666;
                font-size: 14px;
            }
            
            .oep-detailed-answers {
                margin-top: 15px;
                border-top: 1px solid #dee2e6;
                padding-top: 15px;
            }
            
            .oep-answer-item {
                margin: 10px 0;
                padding: 10px;
                border-radius: 4px;
            }
            
            .oep-answer-correct {
                background: #f8fff9;
                border-right: 4px solid #28a745;
            }
            
            .oep-answer-incorrect {
                background: #fff8f8;
                border-right: 4px solid #dc3545;
            }
            
            .oep-profile-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            
            .oep-profile-table th,
            .oep-profile-table td {
                padding: 12px;
                text-align: right;
                border-bottom: 1px solid #dee2e6;
            }
            
            .oep-profile-table th {
                background: #f8f9fa;
                font-weight: bold;
                width: 30%;
            }
            
            .oep-profile-actions {
                text-align: center;
                margin-top: 20px;
            }
            
            .oep-login-required {
                text-align: center;
                padding: 50px;
                background: #f8f9fa;
                border-radius: 8px;
                color: #666;
            }
            
            @media (max-width: 768px) {
                .oep-user-panel-tabs {
                    flex-direction: column;
                }
                
                .oep-exam-grid {
                    grid-template-columns: 1fr;
                }
                
                .oep-tab-content {
                    padding: 20px;
                }
                
                .oep-result-header {
                    flex-direction: column;
                    align-items: flex-start;
                }
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var userPanelNonce = '<?php echo wp_create_nonce('oep_user_panel_nonce'); ?>';
            
            // Tab switching
            $('.oep-tab-button').on('click', function() {
                var tabId = $(this).data('tab');
                
                $('.oep-tab-button').removeClass('active');
                $('.oep-tab-panel').removeClass('active');
                
                $(this).addClass('active');
                $('#oep-tab-' + tabId).addClass('active');
                
                if (tabId === 'exams') {
                    loadUserExams();
                }
            });
            
            // Load user exams on page load
            loadUserExams();
            
            function loadUserExams() {
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'oep_get_user_exams',
                        nonce: userPanelNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            displayUserExams(response.data);
                        } else {
                            $('#oep-user-exams').html('<p class="error">' + response.data + '</p>');
                        }
                        $('#oep-tab-exams .oep-loading').hide();
                        $('#oep-user-exams').show();
                    },
                    error: function() {
                        $('#oep-user-exams').html('<p class="error"><?php _e('خطا در بارگذاری آزمون‌ها', 'online-exam-payment'); ?></p>');
                        $('#oep-tab-exams .oep-loading').hide();
                        $('#oep-user-exams').show();
                    }
                });
            }
            
            function displayUserExams(exams) {
                if (exams.length === 0) {
                    $('#oep-user-exams').html('<p><?php _e('شما هنوز آزمونی نخریده‌اید.', 'online-exam-payment'); ?></p>');
                    return;
                }
                
                var html = '<div class="oep-exam-grid">';
                
                exams.forEach(function(exam) {
                    html += '<div class="oep-exam-card">';
                    html += '<div class="oep-exam-title">' + exam.title + '</div>';
                    html += '<div class="oep-exam-description">' + exam.description + '</div>';
                    html += '<div class="oep-exam-meta">';
                    html += '<span><strong><?php _e('تعداد سوالات:', 'online-exam-payment'); ?></strong> ' + exam.questions_count + '</span>';
                    if (exam.duration) {
                        html += '<span><strong><?php _e('مدت زمان:', 'online-exam-payment'); ?></strong> ' + exam.duration + ' <?php _e('دقیقه', 'online-exam-payment'); ?></span>';
                    }
                    html += '<span><strong><?php _e('نمره قبولی:', 'online-exam-payment'); ?></strong> ' + exam.pass_score + '%</span>';
                    html += '<span><strong><?php _e('تلاش‌ها:', 'online-exam-payment'); ?></strong> ' + exam.attempts + '/' + exam.max_attempts + '</span>';
                    html += '</div>';
                    
                    if (exam.last_result) {
                        html += '<div class="oep-last-result">';
                        html += '<span class="oep-status-badge ' + (exam.last_result.passed ? 'oep-status-passed' : 'oep-status-failed') + '">';
                        html += '<?php _e('آخرین نتیجه:', 'online-exam-payment'); ?> ' + exam.last_result.score + '% ';
                        html += exam.last_result.passed ? '<?php _e('(قبول)', 'online-exam-payment'); ?>' : '<?php _e('(رد)', 'online-exam-payment'); ?>';
                        html += '</span>';
                        html += '</div>';
                    }
                    
                    html += '<div class="oep-exam-actions">';
                    
                    if (exam.has_active_session) {
                        html += '<a href="<?php echo home_url('/exam/'); ?>?exam_id=' + exam.id + '" class="oep-btn oep-btn-warning">';
                        html += '<?php _e('ادامه آزمون', 'online-exam-payment'); ?></a>';
                    } else if (exam.can_attempt) {
                        html += '<a href="<?php echo home_url('/exam/'); ?>?exam_id=' + exam.id + '" class="oep-btn oep-btn-primary">';
                        html += '<?php _e('شروع آزمون', 'online-exam-payment'); ?></a>';
                    } else {
                        html += '<button class="oep-btn oep-btn-secondary" disabled>';
                        html += '<?php _e('تعداد تلاش تمام شد', 'online-exam-payment'); ?></button>';
                    }
                    
                    if (exam.attempts > 0) {
                        html += '<button class="oep-btn oep-btn-success oep-view-results" data-exam-id="' + exam.id + '">';
                        html += '<?php _e('مشاهده نتایج', 'online-exam-payment'); ?></button>';
                    }
                    
                    html += '</div>';
                    html += '</div>';
                });
                
                html += '</div>';
                $('#oep-user-exams').html(html);
            }
            
            // View exam results
            $(document).on('click', '.oep-view-results', function() {
                var examId = $(this).data('exam-id');
                loadExamResults(examId);
                
                $('.oep-tab-button').removeClass('active');
                $('.oep-tab-panel').removeClass('active');
                $('[data-tab="results"]').addClass('active');
                $('#oep-tab-results').addClass('active');
            });
            
            function loadExamResults(examId) {
                $('#oep-exam-results').html('<div class="oep-loading"><p><?php _e('در حال بارگذاری نتایج...', 'online-exam-payment'); ?></p></div>').show();
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'oep_get_exam_results',
                        exam_id: examId,
                        nonce: userPanelNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            displayExamResults(response.data);
                        } else {
                            $('#oep-exam-results').html('<p class="error">' + response.data + '</p>');
                        }
                    },
                    error: function() {
                        $('#oep-exam-results').html('<p class="error"><?php _e('خطا در بارگذاری نتایج', 'online-exam-payment'); ?></p>');
                    }
                });
            }
            
            function displayExamResults(results) {
                if (results.length === 0) {
                    $('#oep-exam-results').html('<p><?php _e('نتیجه‌ای یافت نشد.', 'online-exam-payment'); ?></p>');
                    return;
                }
                
                var html = '<div class="oep-results-list">';
                
                results.forEach(function(result, index) {
                    html += '<div class="oep-result-item">';
                    html += '<div class="oep-result-header">';
                    html += '<div>';
                    html += '<span class="oep-result-score ' + (result.passed ? 'oep-status-passed' : 'oep-status-failed') + '">';
                    html += result.score + '%</span>';
                    html += '<span class="oep-status-badge ' + (result.passed ? 'oep-status-passed' : 'oep-status-failed') + '">';
                    html += result.passed ? '<?php _e('قبول', 'online-exam-payment'); ?>' : '<?php _e('رد', 'online-exam-payment'); ?>';
                    html += '</span>';
                    html += '</div>';
                    html += '<div class="oep-result-details">';
                    html += '<span><?php _e('تاریخ:', 'online-exam-payment'); ?> ' + result.completed_at + '</span><br>';
                    html += '<span><?php _e('زمان صرف شده:', 'online-exam-payment'); ?> ' + result.time_spent + '</span>';
                    html += '</div>';
                    html += '</div>';
                    
                    if (result.answers && result.answers.length > 0) {
                        html += '<div class="oep-detailed-answers">';
                        html += '<h4><?php _e('جزئیات پاسخ‌ها:', 'online-exam-payment'); ?></h4>';
                        
                        result.answers.forEach(function(answer, qIndex) {
                            html += '<div class="oep-answer-item ' + (answer.is_correct ? 'oep-answer-correct' : 'oep-answer-incorrect') + '">';
                            html += '<strong>سوال ' + (qIndex + 1) + ':</strong> ' + answer.question_title + '<br>';
                            html += '<strong><?php _e('پاسخ شما:', 'online-exam-payment'); ?></strong> ' + (answer.user_answer || '<?php _e('پاسخ داده نشده', 'online-exam-payment'); ?>') + '<br>';
                            html += '<strong><?php _e('پاسخ صحیح:', 'online-exam-payment'); ?></strong> ' + answer.correct_answer;
                            if (answer.explanation) {
                                html += '<br><strong><?php _e('توضیح:', 'online-exam-payment'); ?></strong> ' + answer.explanation;
                            }
                            html += '</div>';
                        });
                        
                        html += '</div>';
                    }
                    
                    html += '</div>';
                });
                
                html += '</div>';
                $('#oep-exam-results').html(html);
            }
        });
        </script>
        <?php
        
        return ob_get_clean();
    }
}