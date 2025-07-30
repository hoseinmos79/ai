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
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_oep_approve_transaction', array($this, 'approve_transaction'));
        add_action('wp_ajax_oep_reject_transaction', array($this, 'reject_transaction'));
    }
    
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            __('آزمون‌های آنلاین', 'online-exam-payment'),
            __('آزمون‌های آنلاین', 'online-exam-payment'),
            'manage_options',
            'oep-exams',
            array($this, 'exams_page'),
            'dashicons-clipboard',
            30
        );
        
        // Submenus
        add_submenu_page(
            'oep-exams',
            __('همه آزمون‌ها', 'online-exam-payment'),
            __('همه آزمون‌ها', 'online-exam-payment'),
            'manage_options',
            'oep-exams'
        );
        
        add_submenu_page(
            'oep-exams',
            __('افزودن آزمون جدید', 'online-exam-payment'),
            __('افزودن آزمون جدید', 'online-exam-payment'),
            'manage_options',
            'post-new.php?post_type=oep_exam'
        );
        
        add_submenu_page(
            'oep-exams',
            __('سوالات', 'online-exam-payment'),
            __('سوالات', 'online-exam-payment'),
            'manage_options',
            'edit.php?post_type=oep_question'
        );
        
        add_submenu_page(
            'oep-exams',
            __('افزودن سوال جدید', 'online-exam-payment'),
            __('افزودن سوال جدید', 'online-exam-payment'),
            'manage_options',
            'post-new.php?post_type=oep_question'
        );
        
        add_submenu_page(
            'oep-exams',
            __('وارد کردن سوالات', 'online-exam-payment'),
            __('وارد کردن سوالات', 'online-exam-payment'),
            'manage_options',
            'oep-import-questions',
            array($this, 'import_questions_page')
        );
        
        add_submenu_page(
            'oep-exams',
            __('تراکنش‌ها', 'online-exam-payment'),
            __('تراکنش‌ها', 'online-exam-payment'),
            'manage_options',
            'oep-transactions',
            array($this, 'transactions_page')
        );
        
        add_submenu_page(
            'oep-exams',
            __('نتایج آزمون‌ها', 'online-exam-payment'),
            __('نتایج آزمون‌ها', 'online-exam-payment'),
            'manage_options',
            'oep-results',
            array($this, 'results_page')
        );
        
        add_submenu_page(
            'oep-exams',
            __('دسته‌بندی‌ها', 'online-exam-payment'),
            __('دسته‌بندی‌ها', 'online-exam-payment'),
            'manage_options',
            'edit-tags.php?taxonomy=oep_exam_category&post_type=oep_exam'
        );
        
        add_submenu_page(
            'oep-exams',
            __('تنظیمات', 'online-exam-payment'),
            __('تنظیمات', 'online-exam-payment'),
            'manage_options',
            'oep-settings',
            array($this, 'settings_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'oep-') !== false) {
            wp_enqueue_style('oep-admin-style', OEP_PLUGIN_URL . 'assets/css/admin.css', array(), OEP_VERSION);
            wp_enqueue_script('oep-admin-script', OEP_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), OEP_VERSION, true);
            
            wp_localize_script('oep-admin-script', 'oep_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('oep_admin_nonce'),
                'strings' => array(
                    'confirm_approve' => __('آیا از تایید این تراکنش اطمینان دارید؟', 'online-exam-payment'),
                    'confirm_reject' => __('آیا از رد این تراکنش اطمینان دارید؟', 'online-exam-payment')
                )
            ));
        }
    }
    
    public function exams_page() {
        $exams = get_posts(array(
            'post_type' => 'oep_exam',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1><?php _e('آزمون‌های آنلاین', 'online-exam-payment'); ?> 
                <a href="<?php echo admin_url('post-new.php?post_type=oep_exam'); ?>" class="page-title-action"><?php _e('افزودن آزمون جدید', 'online-exam-payment'); ?></a>
            </h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('عنوان آزمون', 'online-exam-payment'); ?></th>
                        <th><?php _e('قیمت', 'online-exam-payment'); ?></th>
                        <th><?php _e('تعداد سوالات', 'online-exam-payment'); ?></th>
                        <th><?php _e('مدت زمان', 'online-exam-payment'); ?></th>
                        <th><?php _e('وضعیت', 'online-exam-payment'); ?></th>
                        <th><?php _e('عملیات', 'online-exam-payment'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($exams as $exam): 
                        $price = get_post_meta($exam->ID, '_oep_exam_price', true);
                        $duration = get_post_meta($exam->ID, '_oep_exam_duration', true);
                        $questions_count = $this->get_exam_questions_count($exam->ID);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($exam->post_title); ?></strong></td>
                        <td><?php echo $price ? number_format($price) . ' ' . __('تومان', 'online-exam-payment') : __('رایگان', 'online-exam-payment'); ?></td>
                        <td><?php echo $questions_count; ?> <?php _e('سوال', 'online-exam-payment'); ?></td>
                        <td><?php echo $duration ? $duration . ' ' . __('دقیقه', 'online-exam-payment') : __('نامحدود', 'online-exam-payment'); ?></td>
                        <td>
                            <?php 
                            $status_labels = array(
                                'publish' => __('منتشر شده', 'online-exam-payment'),
                                'draft' => __('پیش‌نویس', 'online-exam-payment'),
                                'private' => __('خصوصی', 'online-exam-payment')
                            );
                            echo isset($status_labels[$exam->post_status]) ? $status_labels[$exam->post_status] : $exam->post_status;
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo get_edit_post_link($exam->ID); ?>" class="button button-small"><?php _e('ویرایش', 'online-exam-payment'); ?></a>
                            <a href="<?php echo admin_url('admin.php?page=oep-results&exam_id=' . $exam->ID); ?>" class="button button-small"><?php _e('نتایج', 'online-exam-payment'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function import_questions_page() {
        if (isset($_POST['submit_import']) && wp_verify_nonce($_POST['oep_import_nonce'], 'oep_import_questions')) {
            $this->handle_questions_import();
        }
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1><?php _e('وارد کردن سوالات از فایل', 'online-exam-payment'); ?></h1>
            
            <div class="card">
                <h2><?php _e('راهنمای فرمت فایل', 'online-exam-payment'); ?></h2>
                <p><?php _e('فایل Word باید دارای فرمت زیر باشد:', 'online-exam-payment'); ?></p>
                <ul>
                    <li><?php _e('هر سوال در یک خط جداگانه', 'online-exam-payment'); ?></li>
                    <li><?php _e('گزینه‌ها در خطوط بعدی با علامت الف) ب) ج) د)', 'online-exam-payment'); ?></li>
                    <li><?php _e('پاسخ صحیح با علامت * مشخص شود', 'online-exam-payment'); ?></li>
                </ul>
                
                <h3><?php _e('نمونه:', 'online-exam-payment'); ?></h3>
                <pre style="background: #f9f9f9; padding: 10px; border: 1px solid #ddd;">
سوال نمونه: کدام گزینه صحیح است؟
الف) گزینه اول
ب) گزینه دوم
*ج) گزینه سوم (پاسخ صحیح)
د) گزینه چهارم
                </pre>
            </div>
            
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
                                $exams = get_posts(array('post_type' => 'oep_exam', 'posts_per_page' => -1));
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
                            <input type="file" name="questions_file" id="questions_file" accept=".docx,.doc,.txt" required />
                            <p class="description"><?php _e('فرمت‌های پشتیبانی شده: .docx, .doc, .txt', 'online-exam-payment'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('وارد کردن سوالات', 'online-exam-payment'), 'primary', 'submit_import'); ?>
            </form>
        </div>
        <?php
    }
    
    public function transactions_page() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'oep_transactions';
        $transactions = $wpdb->get_results("
            SELECT t.*, u.display_name, e.post_title as exam_title 
            FROM $table_name t 
            LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID 
            LEFT JOIN {$wpdb->posts} e ON t.exam_id = e.ID 
            ORDER BY t.created_at DESC
        ");
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1><?php _e('مدیریت تراکنش‌ها', 'online-exam-payment'); ?></h1>
            
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
                        <td>#<?php echo $transaction->id; ?></td>
                        <td><?php echo esc_html($transaction->display_name); ?></td>
                        <td><?php echo esc_html($transaction->exam_title); ?></td>
                        <td><?php echo number_format($transaction->amount); ?> <?php _e('تومان', 'online-exam-payment'); ?></td>
                        <td>
                            <?php 
                            $payment_methods = array(
                                'zarinpal' => __('زرین‌پال', 'online-exam-payment'),
                                'card_transfer' => __('کارت به کارت', 'online-exam-payment')
                            );
                            echo isset($payment_methods[$transaction->payment_method]) ? $payment_methods[$transaction->payment_method] : $transaction->payment_method;
                            ?>
                        </td>
                        <td>
                            <?php 
                            $status_labels = array(
                                'pending' => '<span class="oep-status-pending">' . __('در انتظار', 'online-exam-payment') . '</span>',
                                'completed' => '<span class="oep-status-completed">' . __('تکمیل شده', 'online-exam-payment') . '</span>',
                                'failed' => '<span class="oep-status-failed">' . __('ناموفق', 'online-exam-payment') . '</span>'
                            );
                            echo isset($status_labels[$transaction->status]) ? $status_labels[$transaction->status] : $transaction->status;
                            ?>
                        </td>
                        <td><?php echo date_i18n('Y/m/d H:i', strtotime($transaction->created_at)); ?></td>
                        <td>
                            <?php if ($transaction->status === 'pending' && $transaction->payment_method === 'card_transfer'): ?>
                                <button class="button button-small oep-approve-transaction" data-transaction-id="<?php echo $transaction->id; ?>">
                                    <?php _e('تایید', 'online-exam-payment'); ?>
                                </button>
                                <button class="button button-small oep-reject-transaction" data-transaction-id="<?php echo $transaction->id; ?>">
                                    <?php _e('رد', 'online-exam-payment'); ?>
                                </button>
                                <?php if ($transaction->card_transfer_info): ?>
                                    <button class="button button-small oep-view-transfer-info" data-info="<?php echo esc_attr($transaction->card_transfer_info); ?>">
                                        <?php _e('جزئیات', 'online-exam-payment'); ?>
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Modal for transfer info -->
        <div id="oep-transfer-info-modal" style="display: none;">
            <div class="oep-modal-content">
                <span class="oep-modal-close">&times;</span>
                <h3><?php _e('اطلاعات واریز کارت به کارت', 'online-exam-payment'); ?></h3>
                <div id="oep-transfer-info-content"></div>
            </div>
        </div>
        <?php
    }
    
    public function results_page() {
        global $wpdb;
        
        $exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
        $results_table = $wpdb->prefix . 'oep_exam_results';
        
        if ($exam_id) {
            $results = $wpdb->get_results($wpdb->prepare("
                SELECT r.*, u.display_name, e.post_title as exam_title 
                FROM $results_table r 
                LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
                LEFT JOIN {$wpdb->posts} e ON r.exam_id = e.ID 
                WHERE r.exam_id = %d
                ORDER BY r.completed_at DESC
            ", $exam_id));
        } else {
            $results = $wpdb->get_results("
                SELECT r.*, u.display_name, e.post_title as exam_title 
                FROM $results_table r 
                LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
                LEFT JOIN {$wpdb->posts} e ON r.exam_id = e.ID 
                ORDER BY r.completed_at DESC
                LIMIT 100
            ");
        }
        
        $exams = get_posts(array('post_type' => 'oep_exam', 'posts_per_page' => -1));
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1><?php _e('نتایج آزمون‌ها', 'online-exam-payment'); ?></h1>
            
            <div class="oep-filter-form">
                <form method="get">
                    <input type="hidden" name="page" value="oep-results" />
                    <select name="exam_id">
                        <option value=""><?php _e('همه آزمون‌ها', 'online-exam-payment'); ?></option>
                        <?php foreach ($exams as $exam): ?>
                            <option value="<?php echo $exam->ID; ?>" <?php selected($exam_id, $exam->ID); ?>>
                                <?php echo esc_html($exam->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button(__('فیلتر', 'online-exam-payment'), 'secondary', 'filter', false); ?>
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('کاربر', 'online-exam-payment'); ?></th>
                        <th><?php _e('آزمون', 'online-exam-payment'); ?></th>
                        <th><?php _e('نمره', 'online-exam-payment'); ?></th>
                        <th><?php _e('درصد', 'online-exam-payment'); ?></th>
                        <th><?php _e('وضعیت', 'online-exam-payment'); ?></th>
                        <th><?php _e('زمان صرف شده', 'online-exam-payment'); ?></th>
                        <th><?php _e('تاریخ', 'online-exam-payment'); ?></th>
                        <th><?php _e('عملیات', 'online-exam-payment'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $result): ?>
                    <tr>
                        <td><?php echo esc_html($result->display_name); ?></td>
                        <td><?php echo esc_html($result->exam_title); ?></td>
                        <td><?php echo $result->correct_answers . '/' . $result->total_questions; ?></td>
                        <td><?php echo round($result->score, 1); ?>%</td>
                        <td>
                            <?php if ($result->passed): ?>
                                <span class="oep-status-passed"><?php _e('قبول', 'online-exam-payment'); ?></span>
                            <?php else: ?>
                                <span class="oep-status-failed"><?php _e('مردود', 'online-exam-payment'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo gmdate('H:i:s', $result->time_spent); ?></td>
                        <td><?php echo date_i18n('Y/m/d H:i', strtotime($result->completed_at)); ?></td>
                        <td>
                            <button class="button button-small oep-view-answers" data-result-id="<?php echo $result->id; ?>">
                                <?php _e('مشاهده پاسخ‌ها', 'online-exam-payment'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }
        
        $settings = get_option('oep_settings', array());
        
        ?>
        <div class="wrap oep-admin-wrap">
            <h1><?php _e('تنظیمات افزونه', 'online-exam-payment'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('oep_settings_nonce', 'oep_settings_nonce'); ?>
                
                <h2><?php _e('تنظیمات زرین‌پال', 'online-exam-payment'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="zarinpal_merchant_id"><?php _e('مرچنت آی‌دی', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="zarinpal_merchant_id" name="settings[zarinpal_merchant_id]" 
                                   value="<?php echo esc_attr($settings['zarinpal_merchant_id'] ?? ''); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('حالت آزمایشی', 'online-exam-payment'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="settings[zarinpal_sandbox]" value="1" 
                                       <?php checked($settings['zarinpal_sandbox'] ?? 0, 1); ?> />
                                <?php _e('فعال‌سازی حالت sandbox زرین‌پال', 'online-exam-payment'); ?>
                            </label>
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
                            <input type="text" id="card_number" name="settings[card_number]" 
                                   value="<?php echo esc_attr($settings['card_number'] ?? ''); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="card_holder_name"><?php _e('نام صاحب حساب', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="card_holder_name" name="settings[card_holder_name]" 
                                   value="<?php echo esc_attr($settings['card_holder_name'] ?? ''); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="card_transfer_description"><?php _e('توضیحات واریز', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="card_transfer_description" name="settings[card_transfer_description]" 
                                      rows="4" class="large-text"><?php echo esc_textarea($settings['card_transfer_description'] ?? ''); ?></textarea>
                            <p class="description"><?php _e('توضیحاتی که به کاربر در هنگام پرداخت کارت به کارت نمایش داده می‌شود', 'online-exam-payment'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php _e('تنظیمات عمومی', 'online-exam-payment'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="currency"><?php _e('واحد پول', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <select id="currency" name="settings[currency]">
                                <option value="toman" <?php selected($settings['currency'] ?? 'toman', 'toman'); ?>><?php _e('تومان', 'online-exam-payment'); ?></option>
                                <option value="rial" <?php selected($settings['currency'] ?? 'toman', 'rial'); ?>><?php _e('ریال', 'online-exam-payment'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="success_message"><?php _e('پیام موفقیت', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="success_message" name="settings[success_message]" rows="3" class="large-text"><?php 
                                echo esc_textarea($settings['success_message'] ?? __('تبریک! پرداخت شما با موفقیت انجام شد.', 'online-exam-payment')); 
                            ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="failure_message"><?php _e('پیام خطا', 'online-exam-payment'); ?></label>
                        </th>
                        <td>
                            <textarea id="failure_message" name="settings[failure_message]" rows="3" class="large-text"><?php 
                                echo esc_textarea($settings['failure_message'] ?? __('متأسفانه پرداخت شما ناموفق بود. لطفاً مجدداً تلاش کنید.', 'online-exam-payment')); 
                            ?></textarea>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('ذخیره تنظیمات', 'online-exam-payment')); ?>
            </form>
        </div>
        <?php
    }
    
    private function save_settings() {
        if (!wp_verify_nonce($_POST['oep_settings_nonce'], 'oep_settings_nonce')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = $_POST['settings'] ?? array();
        update_option('oep_settings', $settings);
        
        echo '<div class="notice notice-success"><p>' . __('تنظیمات با موفقیت ذخیره شد.', 'online-exam-payment') . '</p></div>';
    }
    
    private function get_exam_questions_count($exam_id) {
        return get_posts(array(
            'post_type' => 'oep_question',
            'meta_query' => array(
                array(
                    'key' => '_oep_question_exam_id',
                    'value' => $exam_id,
                    'compare' => '='
                )
            ),
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
    }
    
    private function handle_questions_import() {
        // This will be implemented in the question importer class
        if (class_exists('OEP_Question_Importer')) {
            $importer = new OEP_Question_Importer();
            $result = $importer->import_from_file($_FILES['questions_file'], $_POST['exam_id']);
            
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>' . sprintf(__('%d سوال با موفقیت وارد شد.', 'online-exam-payment'), $result['count']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
            }
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
            // Grant access to exam
            $transaction = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $transaction_id));
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
            array('status' => 'failed'),
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
        $user_exams = get_user_meta($user_id, 'oep_purchased_exams', true);
        if (!is_array($user_exams)) {
            $user_exams = array();
        }
        
        if (!in_array($exam_id, $user_exams)) {
            $user_exams[] = $exam_id;
            update_user_meta($user_id, 'oep_purchased_exams', $user_exams);
        }
    }
    
    public function register_settings() {
        // Settings will be handled manually in save_settings method
    }
}