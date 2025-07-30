<?php
/**
 * Exam Engine for Online Exam Payment Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEP_Exam_Engine {
    
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('template_redirect', array($this, 'handle_exam_page'));
        add_action('wp_ajax_oep_start_exam', array($this, 'start_exam'));
        add_action('wp_ajax_oep_submit_answer', array($this, 'submit_answer'));
        add_action('wp_ajax_oep_finish_exam', array($this, 'finish_exam'));
        add_action('wp_ajax_oep_get_exam_state', array($this, 'get_exam_state'));
        
        // Create sessions table if it doesn't exist
        $this->create_sessions_table();
    }
    
    public function enqueue_scripts() {
        if ($this->is_exam_page()) {
            wp_enqueue_script('oep-exam-engine', OEP_PLUGIN_URL . 'assets/js/exam-engine.js', array('jquery'), OEP_VERSION, true);
            wp_localize_script('oep-exam-engine', 'oep_exam_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('oep_exam_nonce'),
                'strings' => array(
                    'confirm_finish' => __('آیا از اتمام آزمون اطمینان دارید؟', 'online-exam-payment'),
                    'time_up' => __('زمان آزمون به پایان رسید!', 'online-exam-payment'),
                    'saved' => __('ذخیره شد', 'online-exam-payment'),
                    'saving' => __('در حال ذخیره...', 'online-exam-payment'),
                    'error' => __('خطا در ذخیره', 'online-exam-payment'),
                    'loading' => __('در حال بارگذاری...', 'online-exam-payment'),
                    'please_wait' => __('لطفاً صبر کنید...', 'online-exam-payment')
                )
            ));
        }
    }
    
    private function is_exam_page() {
        return isset($_GET['exam_id']) || (is_page() && get_query_var('pagename') === 'exam');
    }
    
    public function handle_exam_page() {
        if ($this->is_exam_page()) {
            $this->display_exam_page();
            exit;
        }
    }
    
    public function start_exam() {
        check_ajax_referer('oep_exam_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('برای شرکت در آزمون باید وارد سایت شوید.', 'online-exam-payment'));
        }
        
        $exam_id = intval($_POST['exam_id']);
        $user_id = get_current_user_id();
        
        if (!$this->user_can_take_exam($user_id, $exam_id)) {
            wp_send_json_error(__('شما مجاز به شرکت در این آزمون نیستید.', 'online-exam-payment'));
        }
        
        if (!$this->can_attempt_exam($user_id, $exam_id)) {
            wp_send_json_error(__('شما به حداکثر تعداد تلاش مجاز رسیده‌اید.', 'online-exam-payment'));
        }
        
        $questions = $this->get_exam_questions($exam_id);
        if (empty($questions)) {
            wp_send_json_error(__('این آزمون هنوز سوالی ندارد.', 'online-exam-payment'));
        }
        
        // Check for existing active session
        $existing_session = $this->get_active_exam_session($user_id, $exam_id);
        if ($existing_session) {
            wp_send_json_error(__('شما آزمونی در حال انجام دارید. ابتدا آن را تمام کنید.', 'online-exam-payment'));
        }
        
        // Create new session
        $session_id = $this->create_exam_session($user_id, $exam_id, $questions);
        
        wp_send_json_success(array(
            'session_id' => $session_id,
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
        
        if ($this->is_exam_expired($session)) {
            wp_send_json_error(__('زمان آزمون به پایان رسیده است.', 'online-exam-payment'));
        }
        
        $session_data = json_decode($session->session_data, true);
        $answers = json_decode($session->answers, true) ?: array();
        
        // Save answer
        $answers[$question_index] = $answer;
        
        // Update session
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_sessions';
        
        $wpdb->update(
            $table_name,
            array(
                'answers' => json_encode($answers),
                'current_question' => $question_index
            ),
            array('id' => $session_id),
            array('%s', '%d'),
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
        
        // Save result
        $result_id = $this->save_exam_result($result);
        
        // Mark session as completed
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'oep_exam_sessions';
        $wpdb->update(
            $sessions_table,
            array('status' => 'completed'),
            array('id' => $session_id),
            array('%s'),
            array('%d')
        );
        
        wp_send_json_success(array(
            'result' => $result,
            'message' => __('آزمون با موفقیت تمام شد.', 'online-exam-payment')
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
        
        if ($this->is_exam_expired($session)) {
            wp_send_json_error(__('زمان آزمون به پایان رسیده است.', 'online-exam-payment'));
        }
        
        $session_data = json_decode($session->session_data, true);
        $answers = json_decode($session->answers, true) ?: array();
        
        wp_send_json_success(array(
            'questions' => $session_data['questions'],
            'answers' => $answers,
            'current_question' => $session->current_question,
            'time_remaining' => max(0, strtotime($session->expires_at) - time()),
            'exam_settings' => $session_data['exam_settings']
        ));
    }
    
    private function user_can_take_exam($user_id, $exam_id) {
        // Check if exam exists and is published
        $exam = get_post($exam_id);
        if (!$exam || $exam->post_type !== 'oep_exam' || $exam->post_status !== 'publish') {
            return false;
        }
        
        // Check if user has access (purchased or free)
        $price = get_post_meta($exam_id, '_oep_exam_price', true);
        if ($price > 0) {
            $purchased_exams = get_user_meta($user_id, 'oep_purchased_exams', true);
            return is_array($purchased_exams) && in_array($exam_id, $purchased_exams);
        }
        
        return true; // Free exam
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
            'meta_query' => array(
                array(
                    'key' => '_oep_question_exam_id',
                    'value' => $exam_id,
                    'compare' => '='
                )
            ),
            'numberposts' => -1,
            'post_status' => 'publish'
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
        
        // Shuffle questions if enabled
        $shuffle = get_post_meta($exam_id, '_oep_exam_shuffle_questions', true);
        if ($shuffle) {
            shuffle($formatted_questions);
        }
        
        return $formatted_questions;
    }
    
    private function get_active_exam_session($user_id, $exam_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_sessions';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND exam_id = %d AND status = 'active' AND expires_at > NOW()",
            $user_id, $exam_id
        ));
    }
    
    private function create_exam_session($user_id, $exam_id, $questions) {
        $duration = get_post_meta($exam_id, '_oep_exam_duration', true);
        $expires_at = $duration ? date('Y-m-d H:i:s', time() + ($duration * 60)) : null;
        
        $exam_settings = array(
            'duration' => $duration,
            'pass_score' => get_post_meta($exam_id, '_oep_exam_pass_score', true) ?: 60,
            'show_results' => get_post_meta($exam_id, '_oep_exam_show_results', true),
            'shuffle_questions' => get_post_meta($exam_id, '_oep_exam_shuffle_questions', true)
        );
        
        $session_data = array(
            'questions' => $questions,
            'exam_settings' => $exam_settings,
            'started_at' => current_time('mysql')
        );
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_sessions';
        
        $wpdb->insert($table_name, array(
            'user_id' => $user_id,
            'exam_id' => $exam_id,
            'session_data' => json_encode($session_data),
            'expires_at' => $expires_at,
            'status' => 'active'
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
        return $session->expires_at && strtotime($session->expires_at) <= time();
    }
    
    private function calculate_exam_result($session) {
        $session_data = json_decode($session->session_data, true);
        $answers = json_decode($session->answers, true) ?: array();
        $questions = $session_data['questions'];
        
        $total_questions = count($questions);
        $correct_answers = 0;
        $detailed_answers = array();
        
        foreach ($questions as $index => $question) {
            $user_answer = $answers[$index] ?? '';
            $correct_answer = $question['correct_answer'];
            $is_correct = $user_answer === $correct_answer;
            
            if ($is_correct) {
                $correct_answers++;
            }
            
            $detailed_answers[] = array(
                'question_id' => $question['id'],
                'question_title' => $question['title'],
                'user_answer' => $user_answer,
                'correct_answer' => $correct_answer,
                'is_correct' => $is_correct,
                'explanation' => $question['explanation']
            );
        }
        
        $score = $total_questions > 0 ? ($correct_answers / $total_questions) * 100 : 0;
        $pass_score = $session_data['exam_settings']['pass_score'];
        $passed = $score >= $pass_score;
        
        $time_spent = time() - strtotime($session->started_at);
        
        return array(
            'user_id' => $session->user_id,
            'exam_id' => $session->exam_id,
            'score' => $score,
            'total_questions' => $total_questions,
            'correct_answers' => $correct_answers,
            'time_spent' => $time_spent,
            'passed' => $passed,
            'answers' => json_encode($detailed_answers),
            'started_at' => $session->started_at,
            'completed_at' => current_time('mysql')
        );
    }
    
    private function save_exam_result($result) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_results';
        
        $wpdb->insert($table_name, $result);
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
            session_data longtext,
            current_question int(11) DEFAULT 0,
            answers longtext,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime,
            status varchar(20) DEFAULT 'active',
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
            wp_die(__('آزمون مورد نظر یافت نشد.', 'online-exam-payment'));
        }
        
        $exam = get_post($exam_id);
        if (!$exam || $exam->post_type !== 'oep_exam') {
            wp_die(__('آزمون مورد نظر یافت نشد.', 'online-exam-payment'));
        }
        
        $user_id = get_current_user_id();
        
        // Check access
        if (!$this->user_can_take_exam($user_id, $exam_id)) {
            wp_die(__('شما مجاز به شرکت در این آزمون نیستید.', 'online-exam-payment'));
        }
        
        // Check for existing session
        $existing_session = $this->get_active_exam_session($user_id, $exam_id);
        
        // Display exam interface
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($exam->post_title); ?> - <?php bloginfo('name'); ?></title>
            <?php wp_head(); ?>
            <style>
                body { font-family: 'Tahoma', 'Arial', sans-serif; direction: rtl; text-align: right; margin: 0; padding: 20px; background: #f5f5f5; }
                .oep-exam-container { max-width: 900px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .oep-exam-header { background: #2196F3; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
                .oep-exam-content { padding: 30px; }
                .oep-exam-footer { background: #f8f9fa; padding: 20px; border-radius: 0 0 8px 8px; border-top: 1px solid #dee2e6; }
                .oep-timer { font-size: 18px; font-weight: bold; color: #e74c3c; }
                .oep-progress { background: #e9ecef; height: 8px; border-radius: 4px; margin: 15px 0; }
                .oep-progress-bar { background: #28a745; height: 100%; border-radius: 4px; transition: width 0.3s; }
                .oep-question { margin: 20px 0; }
                .oep-question-title { font-size: 18px; font-weight: bold; margin-bottom: 15px; line-height: 1.6; }
                .oep-options { list-style: none; padding: 0; }
                .oep-options li { margin: 10px 0; padding: 12px; border: 2px solid #e9ecef; border-radius: 6px; cursor: pointer; transition: all 0.3s; }
                .oep-options li:hover { border-color: #2196F3; background: #f8f9ff; }
                .oep-options li.selected { border-color: #2196F3; background: #e3f2fd; }
                .oep-nav-buttons { display: flex; justify-content: space-between; margin-top: 30px; }
                .oep-btn { padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; transition: all 0.3s; }
                .oep-btn-primary { background: #2196F3; color: white; }
                .oep-btn-secondary { background: #6c757d; color: white; }
                .oep-btn-success { background: #28a745; color: white; }
                .oep-btn-danger { background: #dc3545; color: white; }
                .oep-btn:hover { opacity: 0.9; transform: translateY(-1px); }
                .oep-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
                .oep-loading { text-align: center; padding: 50px; }
                .oep-message { padding: 15px; margin: 15px 0; border-radius: 6px; }
                .oep-message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
                .oep-message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
                .oep-results { margin-top: 30px; }
                .oep-result-summary { background: #f8f9fa; padding: 20px; border-radius: 6px; margin-bottom: 20px; }
                .oep-detailed-answers { margin-top: 20px; }
                .oep-answer-item { margin: 15px 0; padding: 15px; border: 1px solid #dee2e6; border-radius: 6px; }
                .oep-answer-correct { border-color: #28a745; background: #f8fff9; }
                .oep-answer-incorrect { border-color: #dc3545; background: #fff8f8; }
                .oep-save-status { position: fixed; top: 20px; left: 20px; padding: 8px 12px; border-radius: 4px; font-size: 14px; }
                .oep-save-status.saving { background: #ffc107; color: #856404; }
                .oep-save-status.saved { background: #28a745; color: white; }
                .oep-save-status.error { background: #dc3545; color: white; }
                @media (max-width: 768px) {
                    body { padding: 10px; }
                    .oep-exam-content { padding: 20px; }
                    .oep-nav-buttons { flex-direction: column; gap: 10px; }
                    .oep-btn { width: 100%; }
                }
            </style>
        </head>
        <body>
            <div class="oep-exam-container">
                <div class="oep-exam-header">
                    <h1><?php echo esc_html($exam->post_title); ?></h1>
                    <div class="oep-exam-meta">
                        <span class="oep-timer" id="oep-timer" style="display: none;"></span>
                        <div class="oep-progress">
                            <div class="oep-progress-bar" id="oep-progress-bar" style="width: 0%;"></div>
                        </div>
                        <span id="oep-question-counter"></span>
                    </div>
                </div>
                
                <div class="oep-exam-content">
                    <div id="oep-exam-start" <?php echo $existing_session ? 'style="display: none;"' : ''; ?>>
                        <div class="oep-exam-info">
                            <?php if ($exam->post_content): ?>
                                <div class="oep-exam-description">
                                    <?php echo wpautop($exam->post_content); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="oep-exam-details">
                                <?php
                                $duration = get_post_meta($exam_id, '_oep_exam_duration', true);
                                $pass_score = get_post_meta($exam_id, '_oep_exam_pass_score', true) ?: 60;
                                $questions_count = count($this->get_exam_questions($exam_id));
                                ?>
                                <p><strong><?php _e('تعداد سوالات:', 'online-exam-payment'); ?></strong> <?php echo $questions_count; ?></p>
                                <?php if ($duration): ?>
                                    <p><strong><?php _e('مدت زمان:', 'online-exam-payment'); ?></strong> <?php echo $duration; ?> <?php _e('دقیقه', 'online-exam-payment'); ?></p>
                                <?php endif; ?>
                                <p><strong><?php _e('نمره قبولی:', 'online-exam-payment'); ?></strong> <?php echo $pass_score; ?>%</p>
                            </div>
                        </div>
                        
                        <div class="oep-nav-buttons">
                            <button type="button" class="oep-btn oep-btn-primary oep-start-exam" data-exam-id="<?php echo $exam_id; ?>">
                                <?php _e('شروع آزمون', 'online-exam-payment'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <?php if ($existing_session): ?>
                        <div id="oep-resume-exam">
                            <div class="oep-message">
                                <p><?php _e('شما آزمونی در حال انجام دارید. می‌توانید ادامه دهید یا آزمون جدیدی شروع کنید.', 'online-exam-payment'); ?></p>
                            </div>
                            <div class="oep-nav-buttons">
                                <button type="button" class="oep-btn oep-btn-primary oep-resume-exam" data-session-id="<?php echo $existing_session->id; ?>">
                                    <?php _e('ادامه آزمون', 'online-exam-payment'); ?>
                                </button>
                                <button type="button" class="oep-btn oep-btn-secondary oep-start-new-exam" data-exam-id="<?php echo $exam_id; ?>">
                                    <?php _e('شروع آزمون جدید', 'online-exam-payment'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div id="oep-exam-interface" style="display: none;">
                        <div id="oep-question-container"></div>
                        
                        <div class="oep-nav-buttons">
                            <button type="button" class="oep-btn oep-btn-secondary oep-prev-question" disabled>
                                <?php _e('سوال قبلی', 'online-exam-payment'); ?>
                            </button>
                            <button type="button" class="oep-btn oep-btn-primary oep-next-question">
                                <?php _e('سوال بعدی', 'online-exam-payment'); ?>
                            </button>
                            <button type="button" class="oep-btn oep-btn-danger oep-finish-exam" style="display: none;">
                                <?php _e('اتمام آزمون', 'online-exam-payment'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <div id="oep-exam-results" style="display: none;"></div>
                    
                    <div id="oep-loading" class="oep-loading" style="display: none;">
                        <p><?php _e('در حال بارگذاری...', 'online-exam-payment'); ?></p>
                    </div>
                </div>
            </div>
            
            <div id="oep-save-status" class="oep-save-status" style="display: none;"></div>
            
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
}