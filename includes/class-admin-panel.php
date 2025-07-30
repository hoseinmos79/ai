<?php
/**
 * Admin Panel for Online Exam Payment Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEP_Admin_Panel {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_oep_approve_transaction', array($this, 'approve_transaction'));
        add_action('wp_ajax_oep_reject_transaction', array($this, 'reject_transaction'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('آزمون آنلاین', 'online-exam-payment'),
            __('آزمون آنلاین', 'online-exam-payment'),
            'manage_options',
            'online-exam-payment',
            array($this, 'main_page'),
            'dashicons-clipboard',
            25
        );
        
        add_submenu_page(
            'online-exam-payment',
            __('آزمون‌ها', 'online-exam-payment'),
            __('آزمون‌ها', 'online-exam-payment'),
            'manage_options',
            'oep-exams',
            array($this, 'exams_page')
        );
        
        add_submenu_page(
            'online-exam-payment',
            __('سوالات', 'online-exam-payment'),
            __('سوالات', 'online-exam-payment'),
            'manage_options',
            'oep-questions',
            array($this, 'questions_page')
        );
        
        add_submenu_page(
            'online-exam-payment',
            __('وارد کردن سوالات', 'online-exam-payment'),
            __('وارد کردن سوالات', 'online-exam-payment'),
            'manage_options',
            'oep-import-questions',
            array($this, 'import_questions_page')
        );
        
        add_submenu_page(
            'online-exam-payment',
            __('تراکنش‌ها', 'online-exam-payment'),
            __('تراکنش‌ها', 'online-exam-payment'),
            'manage_options',
            'oep-transactions',
            array($this, 'transactions_page')
        );
        
        add_submenu_page(
            'online-exam-payment',
            __('نتایج آزمون‌ها', 'online-exam-payment'),
            __('نتایج آزمون‌ها', 'online-exam-payment'),
            'manage_options',
            'oep-results',
            array($this, 'results_page')
        );
        
        add_submenu_page(
            'online-exam-payment',
            __('دسته‌بندی‌ها', 'online-exam-payment'),
            __('دسته‌بندی‌ها', 'online-exam-payment'),
            'manage_options',
            'edit-tags.php?taxonomy=oep_exam_category&post_type=oep_exam'
        );
        
        add_submenu_page(
            'online-exam-payment',
            __('تنظیمات', 'online-exam-payment'),
            __('تنظیمات', 'online-exam-payment'),
            'manage_options',
            'oep-settings',
            array($this, 'settings_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'online-exam-payment') !== false || strpos($hook, 'oep-') !== false) {
            wp_enqueue_style('oep-admin-css', OEP_PLUGIN_URL . 'assets/css/admin.css', array(), OEP_VERSION);
            wp_enqueue_script('oep-admin-js', OEP_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), OEP_VERSION, true);
            
            wp_localize_script('oep-admin-js', 'oep_admin_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('oep_admin_nonce'),
                'strings' => array(
                    'confirm_approve' => __('آیا از تایید این تراکنش اطمینان دارید؟', 'online-exam-payment'),
                    'confirm_reject' => __('آیا از رد این تراکنش اطمینان دارید؟', 'online-exam-payment'),
                    'processing' => __('در حال پردازش...', 'online-exam-payment'),
                    'error' => __('خطا در انجام عملیات', 'online-exam-payment')
                )
            ));
        }
    }
    
    public function main_page() {
        ?>
        <div class="wrap oep-admin-wrap">
            <h1><?php _e('آزمون آنلاین با پرداخت', 'online-exam-payment'); ?></h1>
            <div class="oep-dashboard">
                <div class="oep-stats-grid">
                    <div class="oep-stat-card">
                        <h3><?php _e('تعداد آزمون‌ها', 'online-exam-payment'); ?></h3>
                        <p class="oep-stat-number"><?php echo wp_count_posts('oep_exam')->publish; ?></p>
                    </div>
                    <div class="oep-stat-card">
                        <h3><?php _e('تعداد سوالات', 'online-exam-payment'); ?></h3>
                        <p class="oep-stat-number"><?php echo wp_count_posts('oep_question')->publish; ?></p>
                    </div>
                    <div class="oep-stat-card">
                        <h3><?php _e('تراکنش‌های در انتظار', 'online-exam-payment'); ?></h3>
                        <p class="oep-stat-number"><?php echo $this->get_pending_transactions_count(); ?></p>
                    </div>
                    <div class="oep-stat-card">
                        <h3><?php _e('نتایج آزمون‌ها', 'online-exam-payment'); ?></h3>
                        <p class="oep-stat-number"><?php echo $this->get_exam_results_count(); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function exams_page() {
        $exams = get_posts(array(
            'post_type' => 'oep_exam',
            'numberposts' => -1,
            'post_status' => 'any'
        ));
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1>
                <?php _e('آزمون‌ها', 'online-exam-payment'); ?>
                <a href="<?php echo admin_url('post-new.php?post_type=oep_exam'); ?>" class="page-title-action">
                    <?php _e('افزودن آزمون جدید', 'online-exam-payment'); ?>
                </a>
            </h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('عنوان', 'online-exam-payment'); ?></th>
                        <th><?php _e('قیمت', 'online-exam-payment'); ?></th>
                        <th><?php _e('تعداد سوالات', 'online-exam-payment'); ?></th>
                        <th><?php _e('مدت زمان', 'online-exam-payment'); ?></th>
                        <th><?php _e('وضعیت', 'online-exam-payment'); ?></th>
                        <th><?php _e('عملیات', 'online-exam-payment'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($exams as $exam): ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="<?php echo get_edit_post_link($exam->ID); ?>">
                                        <?php echo esc_html($exam->post_title); ?>
                                    </a>
                                </strong>
                            </td>
                            <td>
                                <?php 
                                $price = get_post_meta($exam->ID, '_oep_exam_price', true);
                                echo $price ? number_format($price) . ' ' . __('تومان', 'online-exam-payment') : __('رایگان', 'online-exam-payment');
                                ?>
                            </td>
                            <td><?php echo $this->get_exam_questions_count($exam->ID); ?></td>
                            <td>
                                <?php 
                                $duration = get_post_meta($exam->ID, '_oep_exam_duration', true);
                                echo $duration ? $duration . ' ' . __('دقیقه', 'online-exam-payment') : __('نامحدود', 'online-exam-payment');
                                ?>
                            </td>
                            <td>
                                <span class="oep-status-badge oep-status-<?php echo $exam->post_status; ?>">
                                    <?php echo get_post_status_object($exam->post_status)->label; ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo get_edit_post_link($exam->ID); ?>" class="button button-small">
                                    <?php _e('ویرایش', 'online-exam-payment'); ?>
                                </a>
                                <a href="<?php echo get_delete_post_link($exam->ID); ?>" class="button button-small" 
                                   onclick="return confirm('<?php _e('آیا از حذف این آزمون اطمینان دارید؟', 'online-exam-payment'); ?>')">
                                    <?php _e('حذف', 'online-exam-payment'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function questions_page() {
        $questions = get_posts(array(
            'post_type' => 'oep_question',
            'numberposts' => -1,
            'post_status' => 'any'
        ));
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1>
                <?php _e('سوالات', 'online-exam-payment'); ?>
                <a href="<?php echo admin_url('post-new.php?post_type=oep_question'); ?>" class="page-title-action">
                    <?php _e('افزودن سوال جدید', 'online-exam-payment'); ?>
                </a>
            </h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('عنوان سوال', 'online-exam-payment'); ?></th>
                        <th><?php _e('آزمون مربوطه', 'online-exam-payment'); ?></th>
                        <th><?php _e('پاسخ صحیح', 'online-exam-payment'); ?></th>
                        <th><?php _e('وضعیت', 'online-exam-payment'); ?></th>
                        <th><?php _e('عملیات', 'online-exam-payment'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($questions as $question): ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="<?php echo get_edit_post_link($question->ID); ?>">
                                        <?php echo esc_html($question->post_title); ?>
                                    </a>
                                </strong>
                            </td>
                            <td>
                                <?php 
                                $exam_id = get_post_meta($question->ID, '_oep_question_exam_id', true);
                                if ($exam_id) {
                                    $exam = get_post($exam_id);
                                    echo $exam ? esc_html($exam->post_title) : __('آزمون حذف شده', 'online-exam-payment');
                                } else {
                                    echo __('تعیین نشده', 'online-exam-payment');
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                $correct = get_post_meta($question->ID, '_oep_question_correct_answer', true);
                                echo $correct ? strtoupper($correct) : __('تعیین نشده', 'online-exam-payment');
                                ?>
                            </td>
                            <td>
                                <span class="oep-status-badge oep-status-<?php echo $question->post_status; ?>">
                                    <?php echo get_post_status_object($question->post_status)->label; ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo get_edit_post_link($question->ID); ?>" class="button button-small">
                                    <?php _e('ویرایش', 'online-exam-payment'); ?>
                                </a>
                                <a href="<?php echo get_delete_post_link($question->ID); ?>" class="button button-small"
                                   onclick="return confirm('<?php _e('آیا از حذف این سوال اطمینان دارید؟', 'online-exam-payment'); ?>')">
                                    <?php _e('حذف', 'online-exam-payment'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function import_questions_page() {
        if (isset($_POST['submit_import']) && check_admin_referer('oep_import_questions', 'oep_import_nonce')) {
            $this->handle_questions_import();
        }
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1><?php _e('وارد کردن سوالات', 'online-exam-payment'); ?></h1>
            
            <div class="oep-import-section">
                <form method="post" enctype="multipart/form-data" class="oep-import-form">
                    <?php wp_nonce_field('oep_import_questions', 'oep_import_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="exam_id"><?php _e('انتخاب آزمون', 'online-exam-payment'); ?></label>
                            </th>
                            <td>
                                <select name="exam_id" id="exam_id" required>
                                    <option value=""><?php _e('انتخاب کنید', 'online-exam-payment'); ?></option>
                                    <?php
                                    $exams = get_posts(array('post_type' => 'oep_exam', 'numberposts' => -1));
                                    foreach ($exams as $exam) {
                                        echo '<option value="' . $exam->ID . '">' . esc_html($exam->post_title) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="questions_file"><?php _e('فایل سوالات', 'online-exam-payment'); ?></label>
                            </th>
                            <td>
                                <input type="file" name="questions_file" id="questions_file" 
                                       accept=".txt,.doc,.docx" required />
                                <p class="description">
                                    <?php _e('فرمت‌های پشتیبانی شده: .txt, .doc, .docx', 'online-exam-payment'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit_import" class="button-primary" 
                               value="<?php _e('وارد کردن سوالات', 'online-exam-payment'); ?>" />
                    </p>
                </form>
                
                <div class="oep-import-instructions">
                    <h3><?php _e('راهنمای فرمت فایل', 'online-exam-payment'); ?></h3>
                    <?php echo OEP_Question_Importer::get_sample_format(); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function transactions_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_transactions';
        
        $transactions = $wpdb->get_results("
            SELECT t.*, u.display_name, p.post_title as exam_title 
            FROM $table_name t
            LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
            LEFT JOIN {$wpdb->posts} p ON t.exam_id = p.ID
            ORDER BY t.created_at DESC
        ");
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1><?php _e('تراکنش‌ها', 'online-exam-payment'); ?></h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('شناسه', 'online-exam-payment'); ?></th>
                        <th><?php _e('کاربر', 'online-exam-payment'); ?></th>
                        <th><?php _e('آزمون', 'online-exam-payment'); ?></th>
                        <th><?php _e('مبلغ', 'online-exam-payment'); ?></th>
                        <th><?php _e('روش پرداخت', 'online-exam-payment'); ?></th>
                        <th><?php _e('وضعیت', 'online-exam-payment'); ?></th>
                        <th><?php _e('تاریخ', 'online-exam-payment'); ?></th>
                        <th><?php _e('عملیات', 'online-exam-payment'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?php echo $transaction->id; ?></td>
                            <td><?php echo esc_html($transaction->display_name); ?></td>
                            <td><?php echo esc_html($transaction->exam_title); ?></td>
                            <td><?php echo number_format($transaction->amount) . ' ' . __('تومان', 'online-exam-payment'); ?></td>
                            <td>
                                <?php 
                                echo $transaction->payment_method === 'zarinpal' ? 
                                    __('زرین‌پال', 'online-exam-payment') : 
                                    __('کارت به کارت', 'online-exam-payment');
                                ?>
                            </td>
                            <td>
                                <span class="oep-status-badge oep-status-<?php echo $transaction->status; ?>">
                                    <?php
                                    $statuses = array(
                                        'pending' => __('در انتظار', 'online-exam-payment'),
                                        'completed' => __('تکمیل شده', 'online-exam-payment'),
                                        'failed' => __('ناموفق', 'online-exam-payment'),
                                        'rejected' => __('رد شده', 'online-exam-payment')
                                    );
                                    echo $statuses[$transaction->status] ?? $transaction->status;
                                    ?>
                                </span>
                            </td>
                            <td><?php echo date_i18n('Y/m/d H:i', strtotime($transaction->created_at)); ?></td>
                            <td>
                                <?php if ($transaction->status === 'pending' && $transaction->payment_method === 'card_transfer'): ?>
                                    <button class="button button-small oep-approve-transaction" 
                                            data-transaction-id="<?php echo $transaction->id; ?>">
                                        <?php _e('تایید', 'online-exam-payment'); ?>
                                    </button>
                                    <button class="button button-small oep-reject-transaction" 
                                            data-transaction-id="<?php echo $transaction->id; ?>">
                                        <?php _e('رد', 'online-exam-payment'); ?>
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($transaction->payment_method === 'card_transfer' && $transaction->card_transfer_info): ?>
                                    <button class="button button-small oep-view-transfer-info" 
                                            data-transfer-info='<?php echo esc_attr($transaction->card_transfer_info); ?>'>
                                        <?php _e('جزئیات', 'online-exam-payment'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Transfer Info Modal -->
        <div id="oep-transfer-modal" class="oep-modal" style="display: none;">
            <div class="oep-modal-content">
                <span class="oep-modal-close">&times;</span>
                <h3><?php _e('جزئیات انتقال کارت به کارت', 'online-exam-payment'); ?></h3>
                <div id="oep-transfer-details"></div>
            </div>
        </div>
        <?php
    }
    
    public function results_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_results';
        
        $exam_filter = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
        $where_clause = $exam_filter ? "WHERE r.exam_id = $exam_filter" : "";
        
        $results = $wpdb->get_results("
            SELECT r.*, u.display_name, p.post_title as exam_title 
            FROM $table_name r
            LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
            LEFT JOIN {$wpdb->posts} p ON r.exam_id = p.ID
            $where_clause
            ORDER BY r.completed_at DESC
        ");
        
        $exams = get_posts(array('post_type' => 'oep_exam', 'numberposts' => -1));
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1><?php _e('نتایج آزمون‌ها', 'online-exam-payment'); ?></h1>
            
            <div class="oep-filter-section">
                <form method="get">
                    <input type="hidden" name="page" value="oep-results" />
                    <select name="exam_id" onchange="this.form.submit()">
                        <option value=""><?php _e('همه آزمون‌ها', 'online-exam-payment'); ?></option>
                        <?php foreach ($exams as $exam): ?>
                            <option value="<?php echo $exam->ID; ?>" <?php selected($exam_filter, $exam->ID); ?>>
                                <?php echo esc_html($exam->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('کاربر', 'online-exam-payment'); ?></th>
                        <th><?php _e('آزمون', 'online-exam-payment'); ?></th>
                        <th><?php _e('نمره', 'online-exam-payment'); ?></th>
                        <th><?php _e('پاسخ صحیح', 'online-exam-payment'); ?></th>
                        <th><?php _e('زمان صرف شده', 'online-exam-payment'); ?></th>
                        <th><?php _e('وضعیت', 'online-exam-payment'); ?></th>
                        <th><?php _e('تاریخ', 'online-exam-payment'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $result): ?>
                        <tr>
                            <td><?php echo esc_html($result->display_name); ?></td>
                            <td><?php echo esc_html($result->exam_title); ?></td>
                            <td>
                                <?php echo round($result->score, 1); ?>%
                            </td>
                            <td>
                                <?php echo $result->correct_answers . '/' . $result->total_questions; ?>
                            </td>
                            <td>
                                <?php 
                                $minutes = floor($result->time_spent / 60);
                                $seconds = $result->time_spent % 60;
                                echo sprintf('%02d:%02d', $minutes, $seconds);
                                ?>
                            </td>
                            <td>
                                <span class="oep-status-badge oep-status-<?php echo $result->passed ? 'passed' : 'failed'; ?>">
                                    <?php echo $result->passed ? __('قبول', 'online-exam-payment') : __('رد', 'online-exam-payment'); ?>
                                </span>
                            </td>
                            <td><?php echo date_i18n('Y/m/d H:i', strtotime($result->completed_at)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('oep_settings', 'oep_settings_nonce');
            $this->save_settings();
            echo '<div class="notice notice-success"><p>' . __('تنظیمات ذخیره شد.', 'online-exam-payment') . '</p></div>';
        }
        
        $settings = get_option('oep_settings', array());
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1><?php _e('تنظیمات', 'online-exam-payment'); ?></h1>
            
            <form method="post">
                <?php wp_nonce_field('oep_settings', 'oep_settings_nonce'); ?>
                
                <h2><?php _e('تنظیمات زرین‌پال', 'online-exam-payment'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="zarinpal_merchant_id"><?php _e('مرچنت آی‌دی', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="zarinpal_merchant_id" name="zarinpal_merchant_id" 
                                   value="<?php echo esc_attr($settings['zarinpal_merchant_id'] ?? ''); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('حالت آزمایشی', 'online-exam-payment'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="zarinpal_sandbox" value="1" 
                                       <?php checked($settings['zarinpal_sandbox'] ?? 0, 1); ?> />
                                <?php _e('فعال کردن حالت آزمایشی (سندباکس)', 'online-exam-payment'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="currency"><?php _e('واحد پول', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <select id="currency" name="currency">
                                <option value="toman" <?php selected($settings['currency'] ?? 'toman', 'toman'); ?>>
                                    <?php _e('تومان', 'online-exam-payment'); ?>
                                </option>
                                <option value="rial" <?php selected($settings['currency'] ?? 'toman', 'rial'); ?>>
                                    <?php _e('ریال', 'online-exam-payment'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <h2><?php _e('تنظیمات کارت به کارت', 'online-exam-payment'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="card_number"><?php _e('شماره کارت', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="card_number" name="card_number" 
                                   value="<?php echo esc_attr($settings['card_number'] ?? ''); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="card_holder_name"><?php _e('نام صاحب حساب', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="card_holder_name" name="card_holder_name" 
                                   value="<?php echo esc_attr($settings['card_holder_name'] ?? ''); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="card_transfer_instructions"><?php _e('راهنمای پرداخت', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="card_transfer_instructions" name="card_transfer_instructions" 
                                      rows="5" class="large-text"><?php echo esc_textarea($settings['card_transfer_instructions'] ?? ''); ?></textarea>
                            <p class="description"><?php _e('راهنمایی که به کاربران برای پرداخت کارت به کارت نمایش داده می‌شود.', 'online-exam-payment'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php _e('پیام‌های سیستم', 'online-exam-payment'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="welcome_message"><?php _e('پیام خوشامدگویی', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="welcome_message" name="welcome_message" 
                                      rows="3" class="large-text"><?php echo esc_textarea($settings['welcome_message'] ?? ''); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pass_message"><?php _e('پیام قبولی', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="pass_message" name="pass_message" 
                                      rows="3" class="large-text"><?php echo esc_textarea($settings['pass_message'] ?? ''); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fail_message"><?php _e('پیام مردودی', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="fail_message" name="fail_message" 
                                      rows="3" class="large-text"><?php echo esc_textarea($settings['fail_message'] ?? ''); ?></textarea>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" 
                           value="<?php _e('ذخیره تنظیمات', 'online-exam-payment'); ?>" />
                </p>
            </form>
        </div>
        <?php
    }
    
    private function save_settings() {
        $settings = array(
            'zarinpal_merchant_id' => sanitize_text_field($_POST['zarinpal_merchant_id']),
            'zarinpal_sandbox' => isset($_POST['zarinpal_sandbox']) ? 1 : 0,
            'currency' => sanitize_text_field($_POST['currency']),
            'card_number' => sanitize_text_field($_POST['card_number']),
            'card_holder_name' => sanitize_text_field($_POST['card_holder_name']),
            'card_transfer_instructions' => sanitize_textarea_field($_POST['card_transfer_instructions']),
            'welcome_message' => sanitize_textarea_field($_POST['welcome_message']),
            'pass_message' => sanitize_textarea_field($_POST['pass_message']),
            'fail_message' => sanitize_textarea_field($_POST['fail_message'])
        );
        
        update_option('oep_settings', $settings);
    }
    
    private function get_exam_questions_count($exam_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_oep_question_exam_id' AND meta_value = %d",
            $exam_id
        ));
    }
    
    private function get_pending_transactions_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_transactions';
        return $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'");
    }
    
    private function get_exam_results_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_exam_results';
        return $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }
    
    private function handle_questions_import() {
        if (!isset($_FILES['questions_file']) || !isset($_POST['exam_id'])) {
            return;
        }
        
        $exam_id = intval($_POST['exam_id']);
        $file = $_FILES['questions_file'];
        
        $importer = new OEP_Question_Importer();
        $result = $importer->import_from_file($file, $exam_id);
        
        if ($result['success']) {
            echo '<div class="notice notice-success"><p>' . 
                 sprintf(__('%d سوال با موفقیت وارد شد.', 'online-exam-payment'), $result['count']) . 
                 '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
        }
    }
    
    public function approve_transaction() {
        check_ajax_referer('oep_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('دسترسی غیرمجاز', 'online-exam-payment'));
        }
        
        $transaction_id = intval($_POST['transaction_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_transactions';
        
        $result = $wpdb->update(
            $table_name,
            array('status' => 'completed'),
            array('id' => $transaction_id),
            array('%s'),
            array('%d')
        );
        
        if ($result !== false) {
            // Grant access to the exam
            $transaction = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $transaction_id
            ));
            
            if ($transaction) {
                $this->grant_exam_access($transaction->user_id, $transaction->exam_id);
            }
            
            wp_send_json_success(__('تراکنش با موفقیت تایید شد.', 'online-exam-payment'));
        } else {
            wp_send_json_error(__('خطا در تایید تراکنش', 'online-exam-payment'));
        }
    }
    
    public function reject_transaction() {
        check_ajax_referer('oep_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('دسترسی غیرمجاز', 'online-exam-payment'));
        }
        
        $transaction_id = intval($_POST['transaction_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_transactions';
        
        $result = $wpdb->update(
            $table_name,
            array('status' => 'rejected'),
            array('id' => $transaction_id),
            array('%s'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(__('تراکنش رد شد.', 'online-exam-payment'));
        } else {
            wp_send_json_error(__('خطا در رد تراکنش', 'online-exam-payment'));
        }
    }
    
    private function grant_exam_access($user_id, $exam_id) {
        $purchased_exams = get_user_meta($user_id, 'oep_purchased_exams', true);
        if (!is_array($purchased_exams)) {
            $purchased_exams = array();
        }
        
        if (!in_array($exam_id, $purchased_exams)) {
            $purchased_exams[] = $exam_id;
            update_user_meta($user_id, 'oep_purchased_exams', $purchased_exams);
        }
    }
}