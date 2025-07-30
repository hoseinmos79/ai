<?php
/**
 * Exam Engine for Online Exam Payment Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEP_Exam_Engine {
    
    public function __construct() {
        add_action('wp_ajax_oep_start_exam', array($this, 'start_exam'));
        add_action('wp_ajax_oep_submit_answer', array($this, 'submit_answer'));
        add_action('wp_ajax_oep_finish_exam', array($this, 'finish_exam'));
        add_action('wp_ajax_oep_get_exam_state', array($this, 'get_exam_state'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('init', array($this, 'handle_exam_page'));
    }
    
    public function enqueue_scripts() {
        if (is_user_logged_in() && $this->is_exam_page()) {
            wp_enqueue_script('oep-exam-engine', OEP_PLUGIN_URL . 'assets/js/exam-engine.js', array('jquery'), OEP_VERSION, true);
            wp_localize_script('oep-exam-engine', 'oep_exam', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('oep_exam_nonce'),
                'strings' => array(
                    'confirm_finish' => __('آیا از پایان آزمون اطمینان دارید؟', 'online-exam-payment'),
                    'time_up' => __('زمان آزمون به پایان رسید!', 'online-exam-payment'),
                    'connection_error' => __('خطا در اتصال. لطفاً اتصال اینترنت خود را بررسی کنید.', 'online-exam-payment'),
                    'saving' => __('در حال ذخیره...', 'online-exam-payment'),
                    'saved' => __('ذخیره شد', 'online-exam-payment')
                )
            ));
        }
    }
    
    private function is_exam_page() {
        return isset($_GET['oep_exam']) || (isset($_GET['page']) && $_GET['page'] === 'exam');
    }
    
    public function handle_exam_page() {
        if (isset($_GET['oep_exam'])) {
            $this->display_exam_page();
        }
    }
    
    public function start_exam() {
        check_ajax_referer('oep_exam_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('برای شرکت در آزمون باید وارد حساب کاربری خود شوید.', 'online-exam-payment'));
        }
        
        $exam_id = intval($_POST['exam_id']);
        $user_id = get_current_user_id();
        
        if (!$this->user_can_take_exam($user_id, $exam_id)) {
            wp_send_json_error(__('شما دسترسی لازم برای شرکت در این آزمون را ندارید.', 'online-exam-payment'));
        }
        
        // Check if user has already started this exam
        $existing_session = $this->get_active_exam_session($user_id, $exam_id);
        if ($existing_session) {
            wp_send_json_success(array(
                'session_id' => $existing_session->id,
                'message' => __('آزمون قبلاً شروع شده است. ادامه می‌دهید؟', 'online-exam-payment')
            ));
        }
        
        // Check attempt limits
        if (!$this->can_attempt_exam($user_id, $exam_id)) {
            wp_send_json_error(__('شما به حداکثر تعداد تلاش مجاز رسیده‌اید.', 'online-exam-payment'));
        }
        
        // Get exam questions
        $questions = $this->get_exam_questions($exam_id);
        if (empty($questions)) {
            wp_send_json_error(__('این آزمون هنوز سوالی ندارد.', 'online-exam-payment'));
        }
        
        // Shuffle questions if needed
        $shuffle = get_post_meta($exam_id, '_oep_exam_shuffle_questions', true);
        if ($shuffle) {
            shuffle($questions);
        }
        
        // Create exam session
        $session_id = $this->create_exam_session($user_id, $exam_id, $questions);
        
        wp_send_json_success(array(
            'session_id' => $session_id,
            'total_questions' => count($questions),
            'duration' => get_post_meta($exam_id, '_oep_exam_duration', true),
            'message' => __('آزمون با موفقیت شروع شد.', 'online-exam-payment')
        ));
    }
    
    public function submit_answer() {
        check_ajax_referer('oep_exam_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('دسترسی غیرمجاز', 'online-exam-payment'));
        }
        
        $session_id = intval($_POST['session_id']);
        $question_index = intval($_POST['question_index']);
        $answer = sanitize_text_field($_POST['answer']);
        
        $session = $this->get_exam_session($session_id, get_current_user_id());
        if (!$session) {
            wp_send_json_error(__('جلسه آزمون یافت نشد.', 'online-exam-payment'));
        }
        
        // Check if exam is still active
        if ($this->is_exam_expired($session)) {
            wp_send_json_error(__('زمان آزمون به پایان رسیده است.', 'online-exam-payment'));
        }
        
        // Update answer
        $answers = json_decode($session->answers, true) ?: array();
        $answers[$question_index] = $answer;
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_sessions';
        
        $wpdb->update(
            $table_name,
            array('answers' => json_encode($answers)),
            array('id' => $session_id),
            array('%s'),
            array('%d')
        );
        
        wp_send_json_success(__('پاسخ ذخیره شد.', 'online-exam-payment'));
    }
    
    public function finish_exam() {
        check_ajax_referer('oep_exam_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('دسترسی غیرمجاز', 'online-exam-payment'));
        }
        
        $session_id = intval($_POST['session_id']);
        $user_id = get_current_user_id();
        
        $session = $this->get_exam_session($session_id, $user_id);
        if (!$session) {
            wp_send_json_error(__('جلسه آزمون یافت نشد.', 'online-exam-payment'));
        }
        
        // Calculate results
        $result = $this->calculate_exam_result($session);
        
        // Save result to database
        $result_id = $this->save_exam_result($result);
        
        // Mark session as completed
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'oep_exam_sessions';
        $wpdb->update(
            $sessions_table,
            array('status' => 'completed', 'completed_at' => current_time('mysql')),
            array('id' => $session_id),
            array('%s', '%s'),
            array('%d')
        );
        
        wp_send_json_success(array(
            'result_id' => $result_id,
            'score' => $result['score'],
            'passed' => $result['passed'],
            'correct_answers' => $result['correct_answers'],
            'total_questions' => $result['total_questions'],
            'message' => $result['passed'] ? 
                __('تبریک! شما در آزمون قبول شدید.', 'online-exam-payment') : 
                __('متأسفانه در این آزمون قبول نشدید.', 'online-exam-payment')
        ));
    }
    
    public function get_exam_state() {
        check_ajax_referer('oep_exam_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('دسترسی غیرمجاز', 'online-exam-payment'));
        }
        
        $session_id = intval($_POST['session_id']);
        $user_id = get_current_user_id();
        
        $session = $this->get_exam_session($session_id, $user_id);
        if (!$session) {
            wp_send_json_error(__('جلسه آزمون یافت نشد.', 'online-exam-payment'));
        }
        
        $questions = json_decode($session->questions, true);
        $answers = json_decode($session->answers, true) ?: array();
        $duration = get_post_meta($session->exam_id, '_oep_exam_duration', true);
        
        // Calculate remaining time
        $remaining_time = 0;
        if ($duration > 0) {
            $start_time = strtotime($session->started_at);
            $elapsed = time() - $start_time;
            $total_seconds = $duration * 60;
            $remaining_time = max(0, $total_seconds - $elapsed);
        }
        
        wp_send_json_success(array(
            'questions' => $questions,
            'answers' => $answers,
            'remaining_time' => $remaining_time,
            'exam_id' => $session->exam_id
        ));
    }
    
    private function user_can_take_exam($user_id, $exam_id) {
        // Check if exam is free
        $price = get_post_meta($exam_id, '_oep_exam_price', true);
        if (!$price || $price <= 0) {
            return true;
        }
        
        // Check if user has purchased the exam
        $purchased_exams = get_user_meta($user_id, 'oep_purchased_exams', true);
        return is_array($purchased_exams) && in_array($exam_id, $purchased_exams);
    }
    
    private function can_attempt_exam($user_id, $exam_id) {
        $max_attempts = get_post_meta($exam_id, '_oep_exam_max_attempts', true) ?: 1;
        
        global $wpdb;
        $results_table = $wpdb->prefix . 'oep_exam_results';
        
        $attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $results_table WHERE user_id = %d AND exam_id = %d",
            $user_id, $exam_id
        ));
        
        return $attempts < $max_attempts;
    }
    
    private function get_exam_questions($exam_id) {
        $questions = get_posts(array(
            'post_type' => 'oep_question',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_oep_question_exam_id',
                    'value' => $exam_id,
                    'compare' => '='
                )
            )
        ));
        
        $formatted_questions = array();
        foreach ($questions as $question) {
            $formatted_questions[] = array(
                'id' => $question->ID,
                'title' => $question->post_title,
                'content' => $question->post_content,
                'options' => array(
                    'a' => get_post_meta($question->ID, '_oep_question_option_a', true),
                    'b' => get_post_meta($question->ID, '_oep_question_option_b', true),
                    'c' => get_post_meta($question->ID, '_oep_question_option_c', true),
                    'd' => get_post_meta($question->ID, '_oep_question_option_d', true)
                ),
                'correct_answer' => get_post_meta($question->ID, '_oep_question_correct_answer', true),
                'explanation' => get_post_meta($question->ID, '_oep_question_explanation', true)
            );
        }
        
        return $formatted_questions;
    }
    
    private function get_active_exam_session($user_id, $exam_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_sessions';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND exam_id = %d AND status = 'active'",
            $user_id, $exam_id
        ));
    }
    
    private function create_exam_session($user_id, $exam_id, $questions) {
        global $wpdb;
        
        // Create sessions table if it doesn't exist
        $this->create_sessions_table();
        
        $table_name = $wpdb->prefix . 'oep_exam_sessions';
        
        $wpdb->insert($table_name, array(
            'user_id' => $user_id,
            'exam_id' => $exam_id,
            'questions' => json_encode($questions),
            'answers' => json_encode(array()),
            'status' => 'active',
            'started_at' => current_time('mysql')
        ));
        
        return $wpdb->insert_id;
    }
    
    private function get_exam_session($session_id, $user_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_sessions';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
            $session_id, $user_id
        ));
    }
    
    private function is_exam_expired($session) {
        $duration = get_post_meta($session->exam_id, '_oep_exam_duration', true);
        
        if (!$duration || $duration <= 0) {
            return false; // No time limit
        }
        
        $start_time = strtotime($session->started_at);
        $elapsed = time() - $start_time;
        $total_seconds = $duration * 60;
        
        return $elapsed >= $total_seconds;
    }
    
    private function calculate_exam_result($session) {
        $questions = json_decode($session->questions, true);
        $user_answers = json_decode($session->answers, true) ?: array();
        
        $total_questions = count($questions);
        $correct_answers = 0;
        
        foreach ($questions as $index => $question) {
            $user_answer = $user_answers[$index] ?? '';
            if ($user_answer === $question['correct_answer']) {
                $correct_answers++;
            }
        }
        
        $score = $total_questions > 0 ? ($correct_answers / $total_questions) * 100 : 0;
        $pass_score = get_post_meta($session->exam_id, '_oep_exam_pass_score', true) ?: 60;
        $passed = $score >= $pass_score;
        
        $start_time = strtotime($session->started_at);
        $time_spent = time() - $start_time;
        
        return array(
            'user_id' => $session->user_id,
            'exam_id' => $session->exam_id,
            'score' => $score,
            'total_questions' => $total_questions,
            'correct_answers' => $correct_answers,
            'time_spent' => $time_spent,
            'passed' => $passed,
            'answers' => json_encode($user_answers),
            'questions' => $session->questions
        );
    }
    
    private function save_exam_result($result) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_results';
        
        $wpdb->insert($table_name, array(
            'user_id' => $result['user_id'],
            'exam_id' => $result['exam_id'],
            'score' => $result['score'],
            'total_questions' => $result['total_questions'],
            'correct_answers' => $result['correct_answers'],
            'time_spent' => $result['time_spent'],
            'passed' => $result['passed'] ? 1 : 0,
            'answers' => $result['answers'],
            'started_at' => current_time('mysql'),
            'completed_at' => current_time('mysql')
        ));
        
        return $wpdb->insert_id;
    }
    
    private function create_sessions_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'oep_exam_sessions';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            exam_id bigint(20) NOT NULL,
            questions longtext,
            answers longtext,
            status varchar(20) DEFAULT 'active',
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY exam_id (exam_id),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function display_exam_page() {
        if (!is_user_logged_in()) {
            wp_redirect(wp_login_url(add_query_arg($_GET, home_url($_SERVER['REQUEST_URI']))));
            exit;
        }
        
        $exam_id = intval($_GET['exam_id'] ?? 0);
        if (!$exam_id) {
            wp_die(__('آزمون یافت نشد.', 'online-exam-payment'));
        }
        
        $exam = get_post($exam_id);
        if (!$exam || $exam->post_type !== 'oep_exam') {
            wp_die(__('آزمون یافت نشد.', 'online-exam-payment'));
        }
        
        // Check access
        if (!$this->user_can_take_exam(get_current_user_id(), $exam_id)) {
            wp_die(__('شما دسترسی لازم برای شرکت در این آزمون را ندارید.', 'online-exam-payment'));
        }
        
        // Get exam settings
        $duration = get_post_meta($exam_id, '_oep_exam_duration', true);
        $pass_score = get_post_meta($exam_id, '_oep_exam_pass_score', true) ?: 60;
        
        // Check for active session
        $active_session = $this->get_active_exam_session(get_current_user_id(), $exam_id);
        
        get_header();
        ?>
        <div class="oep-exam-container" data-exam-id="<?php echo $exam_id; ?>">
            <div class="oep-exam-header">
                <h1><?php echo esc_html($exam->post_title); ?></h1>
                <div class="oep-exam-info">
                    <?php if ($duration): ?>
                        <span class="oep-duration"><?php printf(__('مدت زمان: %d دقیقه', 'online-exam-payment'), $duration); ?></span>
                    <?php endif; ?>
                    <span class="oep-pass-score"><?php printf(__('حدنصاب قبولی: %d%%', 'online-exam-payment'), $pass_score); ?></span>
                </div>
            </div>
            
            <?php if ($active_session): ?>
                <div class="oep-exam-resume">
                    <p><?php _e('شما آزمونی در حال انجام دارید. آیا می‌خواهید ادامه دهید؟', 'online-exam-payment'); ?></p>
                    <button class="button button-primary oep-resume-exam" data-session-id="<?php echo $active_session->id; ?>">
                        <?php _e('ادامه آزمون', 'online-exam-payment'); ?>
                    </button>
                    <button class="button oep-start-new-exam">
                        <?php _e('شروع مجدد', 'online-exam-payment'); ?>
                    </button>
                </div>
            <?php else: ?>
                <div class="oep-exam-start">
                    <div class="oep-exam-description">
                        <?php echo wpautop($exam->post_content); ?>
                    </div>
                    <button class="button button-primary button-large oep-start-exam">
                        <?php _e('شروع آزمون', 'online-exam-payment'); ?>
                    </button>
                </div>
            <?php endif; ?>
            
            <div class="oep-exam-interface" style="display: none;">
                <div class="oep-exam-controls">
                    <div class="oep-timer" style="display: none;">
                        <span class="oep-timer-label"><?php _e('زمان باقی‌مانده:', 'online-exam-payment'); ?></span>
                        <span class="oep-timer-value">00:00:00</span>
                    </div>
                    <div class="oep-progress">
                        <span class="oep-question-counter">سوال <span class="current">1</span> از <span class="total">0</span></span>
                        <div class="oep-progress-bar">
                            <div class="oep-progress-fill"></div>
                        </div>
                    </div>
                </div>
                
                <div class="oep-question-container">
                    <!-- Questions will be loaded here -->
                </div>
                
                <div class="oep-exam-navigation">
                    <button class="button oep-prev-question" disabled><?php _e('سوال قبلی', 'online-exam-payment'); ?></button>
                    <button class="button button-primary oep-next-question"><?php _e('سوال بعدی', 'online-exam-payment'); ?></button>
                    <button class="button button-secondary oep-finish-exam" style="display: none;"><?php _e('پایان آزمون', 'online-exam-payment'); ?></button>
                </div>
            </div>
            
            <div class="oep-exam-result" style="display: none;">
                <!-- Results will be shown here -->
            </div>
        </div>
        
        <style>
        .oep-exam-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            direction: rtl;
            text-align: right;
        }
        
        .oep-exam-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #ddd;
            padding-bottom: 20px;
        }
        
        .oep-exam-info {
            margin-top: 10px;
            color: #666;
        }
        
        .oep-exam-info span {
            margin: 0 10px;
        }
        
        .oep-exam-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 5px;
        }
        
        .oep-timer {
            font-size: 18px;
            font-weight: bold;
            color: #d63384;
        }
        
        .oep-progress {
            flex: 1;
            margin-left: 20px;
        }
        
        .oep-progress-bar {
            width: 100%;
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
            margin-top: 5px;
        }
        
        .oep-progress-fill {
            height: 100%;
            background: #28a745;
            transition: width 0.3s ease;
        }
        
        .oep-question-container {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
            min-height: 300px;
        }
        
        .oep-question {
            margin-bottom: 20px;
        }
        
        .oep-question h3 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .oep-options {
            list-style: none;
            padding: 0;
        }
        
        .oep-options li {
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .oep-options li:hover {
            background: #e9ecef;
        }
        
        .oep-options li.selected {
            background: #d4edda;
            border: 2px solid #28a745;
        }
        
        .oep-exam-navigation {
            text-align: center;
        }
        
        .oep-exam-navigation button {
            margin: 0 10px;
        }
        
        .oep-exam-result {
            text-align: center;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .oep-result-score {
            font-size: 48px;
            font-weight: bold;
            margin: 20px 0;
        }
        
        .oep-result-passed {
            color: #28a745;
        }
        
        .oep-result-failed {
            color: #dc3545;
        }
        </style>
        <?php
        get_footer();
        exit;
    }
}