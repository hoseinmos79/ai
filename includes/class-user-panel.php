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
        check_ajax_referer('oep_user_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('دسترسی غیرمجاز', 'online-exam-payment'));
        }
        
        $user_id = get_current_user_id();
        $purchased_exams = get_user_meta($user_id, 'oep_purchased_exams', true) ?: array();
        
        $exams = array();
        
        // Get purchased exams
        if (!empty($purchased_exams)) {
            $purchased_posts = get_posts(array(
                'post_type' => 'oep_exam',
                'post__in' => $purchased_exams,
                'posts_per_page' => -1,
                'post_status' => 'publish'
            ));
            
            foreach ($purchased_posts as $exam) {
                $exams[] = $this->format_exam_data($exam, $user_id);
            }
        }
        
        // Get free exams
        $free_exams = get_posts(array(
            'post_type' => 'oep_exam',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_oep_exam_price',
                    'value' => array('0', ''),
                    'compare' => 'IN'
                )
            )
        ));
        
        foreach ($free_exams as $exam) {
            // Check if not already in purchased list
            if (!in_array($exam->ID, $purchased_exams)) {
                $exams[] = $this->format_exam_data($exam, $user_id);
            }
        }
        
        wp_send_json_success($exams);
    }
    
    public function get_exam_results() {
        check_ajax_referer('oep_user_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('دسترسی غیرمجاز', 'online-exam-payment'));
        }
        
        $exam_id = intval($_POST['exam_id']);
        $user_id = get_current_user_id();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_results';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND exam_id = %d ORDER BY completed_at DESC",
            $user_id, $exam_id
        ));
        
        $formatted_results = array();
        foreach ($results as $result) {
            $formatted_results[] = array(
                'id' => $result->id,
                'score' => round($result->score, 1),
                'correct_answers' => $result->correct_answers,
                'total_questions' => $result->total_questions,
                'time_spent' => $this->format_time($result->time_spent),
                'passed' => $result->passed == 1,
                'completed_at' => date_i18n('Y/m/d H:i', strtotime($result->completed_at)),
                'answers' => json_decode($result->answers, true)
            );
        }
        
        wp_send_json_success($formatted_results);
    }
    
    private function format_exam_data($exam, $user_id) {
        $price = get_post_meta($exam->ID, '_oep_exam_price', true);
        $duration = get_post_meta($exam->ID, '_oep_exam_duration', true);
        $max_attempts = get_post_meta($exam->ID, '_oep_exam_max_attempts', true) ?: 1;
        
        // Get user's attempts
        global $wpdb;
        $results_table = $wpdb->prefix . 'oep_exam_results';
        
        $attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $results_table WHERE user_id = %d AND exam_id = %d",
            $user_id, $exam->ID
        ));
        
        $last_result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $results_table WHERE user_id = %d AND exam_id = %d ORDER BY completed_at DESC LIMIT 1",
            $user_id, $exam->ID
        ));
        
        // Get questions count
        $questions_count = count(get_posts(array(
            'post_type' => 'oep_question',
            'meta_query' => array(
                array(
                    'key' => '_oep_question_exam_id',
                    'value' => $exam->ID,
                    'compare' => '='
                )
            ),
            'posts_per_page' => -1,
            'fields' => 'ids'
        )));
        
        return array(
            'id' => $exam->ID,
            'title' => $exam->post_title,
            'description' => wp_trim_words($exam->post_content, 20),
            'price' => $price,
            'duration' => $duration,
            'questions_count' => $questions_count,
            'max_attempts' => $max_attempts,
            'user_attempts' => $attempts,
            'can_attempt' => $attempts < $max_attempts,
            'last_score' => $last_result ? round($last_result->score, 1) : null,
            'last_passed' => $last_result ? $last_result->passed == 1 : null,
            'last_attempt_date' => $last_result ? date_i18n('Y/m/d', strtotime($last_result->completed_at)) : null,
            'exam_url' => add_query_arg(array('oep_exam' => '1', 'exam_id' => $exam->ID), home_url())
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
            return '<p>' . __('برای مشاهده پنل کاربری باید وارد حساب خود شوید.', 'online-exam-payment') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="oep-user-panel" id="oep-user-panel">
            <div class="oep-panel-header">
                <h2><?php _e('پنل کاربری آزمون‌ها', 'online-exam-payment'); ?></h2>
                <div class="oep-panel-tabs">
                    <button class="oep-tab-button active" data-tab="exams"><?php _e('آزمون‌های من', 'online-exam-payment'); ?></button>
                    <button class="oep-tab-button" data-tab="results"><?php _e('نتایج آزمون‌ها', 'online-exam-payment'); ?></button>
                </div>
            </div>
            
            <div class="oep-tab-content" id="oep-tab-exams">
                <div class="oep-loading"><?php _e('در حال بارگذاری...', 'online-exam-payment'); ?></div>
                <div class="oep-exams-list" style="display: none;"></div>
            </div>
            
            <div class="oep-tab-content" id="oep-tab-results" style="display: none;">
                <div class="oep-results-container">
                    <p><?php _e('برای مشاهده نتایج، ابتدا یک آزمون را انتخاب کنید.', 'online-exam-payment'); ?></p>
                </div>
            </div>
        </div>
        
        <style>
        .oep-user-panel {
            max-width: 1000px;
            margin: 20px auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            direction: rtl;
        }
        
        .oep-panel-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .oep-panel-header h2 {
            margin: 0 0 15px 0;
            color: #333;
        }
        
        .oep-panel-tabs {
            display: flex;
            gap: 10px;
        }
        
        .oep-tab-button {
            padding: 10px 20px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .oep-tab-button:hover {
            background: #e9ecef;
        }
        
        .oep-tab-button.active {
            background: #007cba;
            color: white;
            border-color: #007cba;
        }
        
        .oep-tab-content {
            padding: 20px;
        }
        
        .oep-exam-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            background: #fafafa;
            transition: box-shadow 0.3s;
        }
        
        .oep-exam-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .oep-exam-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }
        
        .oep-exam-meta {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            color: #666;
            font-size: 14px;
        }
        
        .oep-exam-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .oep-exam-status {
            padding: 5px 10px;
            border-radius: 15px;
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
        
        .oep-status-not-attempted {
            background: #fff3cd;
            color: #856404;
        }
        
        .oep-loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .oep-results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .oep-results-table th,
        .oep-results-table td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid #ddd;
        }
        
        .oep-results-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .oep-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .oep-modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            direction: rtl;
        }
        
        .oep-modal-close {
            color: #aaa;
            float: left;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .oep-modal-close:hover {
            color: #000;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var currentTab = 'exams';
            
            // Tab switching
            $('.oep-tab-button').click(function() {
                var tab = $(this).data('tab');
                $('.oep-tab-button').removeClass('active');
                $(this).addClass('active');
                $('.oep-tab-content').hide();
                $('#oep-tab-' + tab).show();
                currentTab = tab;
                
                if (tab === 'exams') {
                    loadUserExams();
                }
            });
            
            // Load exams on page load
            loadUserExams();
            
            function loadUserExams() {
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'oep_get_user_exams',
                        nonce: '<?php echo wp_create_nonce('oep_user_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            displayExams(response.data);
                        } else {
                            $('.oep-exams-list').html('<p>خطا در بارگذاری آزمون‌ها</p>');
                        }
                    },
                    error: function() {
                        $('.oep-exams-list').html('<p>خطا در اتصال به سرور</p>');
                    }
                });
            }
            
            function displayExams(exams) {
                $('.oep-loading').hide();
                var html = '';
                
                if (exams.length === 0) {
                    html = '<p>هنوز آزمونی خریداری نکرده‌اید.</p>';
                } else {
                    exams.forEach(function(exam) {
                        var statusClass = 'oep-status-not-attempted';
                        var statusText = 'شرکت نکرده';
                        
                        if (exam.last_passed === true) {
                            statusClass = 'oep-status-passed';
                            statusText = 'قبول';
                        } else if (exam.last_passed === false) {
                            statusClass = 'oep-status-failed';
                            statusText = 'مردود';
                        }
                        
                        html += '<div class="oep-exam-card">';
                        html += '<div class="oep-exam-title">' + exam.title + '</div>';
                        html += '<div class="oep-exam-meta">';
                        html += '<span>تعداد سوالات: ' + exam.questions_count + '</span>';
                        if (exam.duration) {
                            html += '<span>مدت زمان: ' + exam.duration + ' دقیقه</span>';
                        }
                        html += '<span>تلاش‌ها: ' + exam.user_attempts + '/' + exam.max_attempts + '</span>';
                        if (exam.last_score !== null) {
                            html += '<span>آخرین نمره: ' + exam.last_score + '%</span>';
                        }
                        html += '</div>';
                        html += '<div class="oep-exam-actions">';
                        html += '<span class="oep-exam-status ' + statusClass + '">' + statusText + '</span>';
                        
                        if (exam.can_attempt) {
                            html += '<a href="' + exam.exam_url + '" class="button button-primary">شروع آزمون</a>';
                        } else {
                            html += '<span class="button disabled">حداکثر تلاش</span>';
                        }
                        
                        if (exam.user_attempts > 0) {
                            html += '<button class="button oep-view-results" data-exam-id="' + exam.id + '">مشاهده نتایج</button>';
                        }
                        html += '</div>';
                        html += '</div>';
                    });
                }
                
                $('.oep-exams-list').html(html).show();
            }
            
            // View results
            $(document).on('click', '.oep-view-results', function() {
                var examId = $(this).data('exam-id');
                loadExamResults(examId);
            });
            
            function loadExamResults(examId) {
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'oep_get_exam_results',
                        exam_id: examId,
                        nonce: '<?php echo wp_create_nonce('oep_user_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            displayResults(response.data);
                            // Switch to results tab
                            $('.oep-tab-button[data-tab="results"]').click();
                        }
                    }
                });
            }
            
            function displayResults(results) {
                var html = '<h3>نتایج آزمون‌ها</h3>';
                
                if (results.length === 0) {
                    html += '<p>هنوز نتیجه‌ای ثبت نشده است.</p>';
                } else {
                    html += '<table class="oep-results-table">';
                    html += '<thead><tr><th>تاریخ</th><th>نمره</th><th>درصد</th><th>زمان صرف شده</th><th>وضعیت</th></tr></thead>';
                    html += '<tbody>';
                    
                    results.forEach(function(result) {
                        var statusClass = result.passed ? 'oep-status-passed' : 'oep-status-failed';
                        var statusText = result.passed ? 'قبول' : 'مردود';
                        
                        html += '<tr>';
                        html += '<td>' + result.completed_at + '</td>';
                        html += '<td>' + result.correct_answers + '/' + result.total_questions + '</td>';
                        html += '<td>' + result.score + '%</td>';
                        html += '<td>' + result.time_spent + '</td>';
                        html += '<td><span class="oep-exam-status ' + statusClass + '">' + statusText + '</span></td>';
                        html += '</tr>';
                    });
                    
                    html += '</tbody></table>';
                }
                
                $('.oep-results-container').html(html);
            }
        });
        </script>
        <?php
        
        return ob_get_clean();
    }
}