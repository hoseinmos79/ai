<?php
/**
 * Payment Handler for Online Exam Payment Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEP_Payment_Handler {
    
    private $settings;
    
    public function __construct() {
        $this->settings = get_option('oep_settings', array());
        
        add_action('wp_ajax_oep_start_payment', array($this, 'start_payment'));
        add_action('wp_ajax_nopriv_oep_start_payment', array($this, 'start_payment'));
        add_action('wp_ajax_oep_submit_card_transfer', array($this, 'submit_card_transfer'));
        add_action('wp_ajax_nopriv_oep_submit_card_transfer', array($this, 'submit_card_transfer'));
        add_action('init', array($this, 'handle_zarinpal_callback'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    public function enqueue_scripts() {
        if (is_user_logged_in()) {
            wp_enqueue_script('oep-payment', OEP_PLUGIN_URL . 'assets/js/payment.js', array('jquery'), OEP_VERSION, true);
            wp_localize_script('oep-payment', 'oep_payment', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('oep_payment_nonce'),
                'strings' => array(
                    'processing' => __('در حال پردازش...', 'online-exam-payment'),
                    'error' => __('خطا در پردازش درخواست', 'online-exam-payment')
                )
            ));
        }
    }
    
    public function start_payment() {
        check_ajax_referer('oep_payment_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('برای خرید آزمون باید وارد حساب کاربری خود شوید.', 'online-exam-payment'));
        }
        
        $exam_id = intval($_POST['exam_id']);
        $payment_method = sanitize_text_field($_POST['payment_method']);
        
        if (!$exam_id) {
            wp_send_json_error(__('آزمون انتخاب شده معتبر نیست.', 'online-exam-payment'));
        }
        
        // Check if user already has access
        if ($this->user_has_exam_access(get_current_user_id(), $exam_id)) {
            wp_send_json_error(__('شما قبلاً این آزمون را خریداری کرده‌اید.', 'online-exam-payment'));
        }
        
        $exam = get_post($exam_id);
        if (!$exam || $exam->post_type !== 'oep_exam') {
            wp_send_json_error(__('آزمون یافت نشد.', 'online-exam-payment'));
        }
        
        $price = get_post_meta($exam_id, '_oep_exam_price', true);
        if (!$price || $price <= 0) {
            // Free exam - grant access immediately
            $this->grant_exam_access(get_current_user_id(), $exam_id);
            wp_send_json_success(array(
                'message' => __('دسترسی به آزمون رایگان برای شما فعال شد.', 'online-exam-payment'),
                'redirect' => $this->get_exam_url($exam_id)
            ));
        }
        
        // Convert price to rial if needed
        $amount_in_rial = $this->convert_to_rial($price);
        
        if ($payment_method === 'zarinpal') {
            $this->process_zarinpal_payment($exam_id, $amount_in_rial, $price);
        } elseif ($payment_method === 'card_transfer') {
            $this->process_card_transfer_payment($exam_id, $price);
        } else {
            wp_send_json_error(__('روش پرداخت انتخاب شده معتبر نیست.', 'online-exam-payment'));
        }
    }
    
    private function process_zarinpal_payment($exam_id, $amount_in_rial, $original_amount) {
        $merchant_id = $this->settings['zarinpal_merchant_id'] ?? '';
        
        if (empty($merchant_id)) {
            wp_send_json_error(__('تنظیمات زرین‌پال کامل نیست. لطفاً با مدیر سایت تماس بگیرید.', 'online-exam-payment'));
        }
        
        $sandbox = isset($this->settings['zarinpal_sandbox']) && $this->settings['zarinpal_sandbox'] == 1;
        $base_url = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
        
        $exam = get_post($exam_id);
        $callback_url = add_query_arg(array(
            'oep_callback' => '1',
            'exam_id' => $exam_id
        ), home_url());
        
        $data = array(
            'merchant_id' => $merchant_id,
            'amount' => $amount_in_rial,
            'callback_url' => $callback_url,
            'description' => sprintf(__('خرید آزمون: %s', 'online-exam-payment'), $exam->post_title),
            'metadata' => array(
                'email' => wp_get_current_user()->user_email,
                'mobile' => get_user_meta(get_current_user_id(), 'mobile', true)
            )
        );
        
        $response = $this->zarinpal_request($base_url . '/pg/v4/payment/request.json', $data);
        
        if ($response && isset($response['data']['code']) && $response['data']['code'] == 100) {
            $authority = $response['data']['authority'];
            
            // Save transaction
            $this->save_transaction(array(
                'user_id' => get_current_user_id(),
                'exam_id' => $exam_id,
                'amount' => $original_amount,
                'payment_method' => 'zarinpal',
                'status' => 'pending',
                'zarinpal_authority' => $authority
            ));
            
            $payment_url = $base_url . '/pg/StartPay/' . $authority;
            
            wp_send_json_success(array(
                'redirect' => $payment_url
            ));
        } else {
            $error_message = $this->get_zarinpal_error_message($response['errors']['code'] ?? 0);
            wp_send_json_error($error_message);
        }
    }
    
    private function process_card_transfer_payment($exam_id, $amount) {
        $card_number = $this->settings['card_number'] ?? '';
        $card_holder = $this->settings['card_holder_name'] ?? '';
        $description = $this->settings['card_transfer_description'] ?? '';
        
        if (empty($card_number)) {
            wp_send_json_error(__('اطلاعات کارت به کارت تنظیم نشده است.', 'online-exam-payment'));
        }
        
        // Save pending transaction
        $transaction_id = $this->save_transaction(array(
            'user_id' => get_current_user_id(),
            'exam_id' => $exam_id,
            'amount' => $amount,
            'payment_method' => 'card_transfer',
            'status' => 'pending'
        ));
        
        $exam = get_post($exam_id);
        $currency = $this->settings['currency'] ?? 'toman';
        $currency_label = $currency === 'rial' ? __('ریال', 'online-exam-payment') : __('تومان', 'online-exam-payment');
        
        ob_start();
        ?>
        <div class="oep-card-transfer-info">
            <h3><?php _e('اطلاعات پرداخت کارت به کارت', 'online-exam-payment'); ?></h3>
            <div class="oep-payment-details">
                <p><strong><?php _e('آزمون:', 'online-exam-payment'); ?></strong> <?php echo esc_html($exam->post_title); ?></p>
                <p><strong><?php _e('مبلغ قابل پرداخت:', 'online-exam-payment'); ?></strong> <?php echo number_format($amount); ?> <?php echo $currency_label; ?></p>
                <p><strong><?php _e('شماره کارت:', 'online-exam-payment'); ?></strong> <?php echo esc_html($card_number); ?></p>
                <p><strong><?php _e('نام صاحب حساب:', 'online-exam-payment'); ?></strong> <?php echo esc_html($card_holder); ?></p>
                <p><strong><?php _e('شناسه تراکنش:', 'online-exam-payment'); ?></strong> #<?php echo $transaction_id; ?></p>
            </div>
            
            <?php if ($description): ?>
                <div class="oep-transfer-description">
                    <h4><?php _e('توضیحات:', 'online-exam-payment'); ?></h4>
                    <p><?php echo nl2br(esc_html($description)); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="oep-transfer-form">
                <h4><?php _e('پس از واریز، اطلاعات زیر را تکمیل کنید:', 'online-exam-payment'); ?></h4>
                <form id="oep-card-transfer-form" data-transaction-id="<?php echo $transaction_id; ?>">
                    <table class="form-table">
                        <tr>
                            <th><label for="sender_card"><?php _e('چهار رقم آخر کارت شما:', 'online-exam-payment'); ?></label></th>
                            <td><input type="text" id="sender_card" name="sender_card" maxlength="4" pattern="[0-9]{4}" required /></td>
                        </tr>
                        <tr>
                            <th><label for="tracking_code"><?php _e('کد پیگیری:', 'online-exam-payment'); ?></label></th>
                            <td><input type="text" id="tracking_code" name="tracking_code" required /></td>
                        </tr>
                        <tr>
                            <th><label for="transfer_date"><?php _e('تاریخ واریز:', 'online-exam-payment'); ?></label></th>
                            <td><input type="date" id="transfer_date" name="transfer_date" required /></td>
                        </tr>
                        <tr>
                            <th><label for="receipt_image"><?php _e('تصویر رسید (اختیاری):', 'online-exam-payment'); ?></label></th>
                            <td><input type="file" id="receipt_image" name="receipt_image" accept="image/*" /></td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" class="button button-primary"><?php _e('ثبت اطلاعات واریز', 'online-exam-payment'); ?></button>
                        <button type="button" class="button" onclick="jQuery('.oep-payment-modal').hide();"><?php _e('انصراف', 'online-exam-payment'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
        $content = ob_get_clean();
        
        wp_send_json_success(array(
            'show_modal' => true,
            'modal_content' => $content
        ));
    }
    
    public function submit_card_transfer() {
        check_ajax_referer('oep_payment_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('دسترسی غیرمجاز', 'online-exam-payment'));
        }
        
        $transaction_id = intval($_POST['transaction_id']);
        $sender_card = sanitize_text_field($_POST['sender_card']);
        $tracking_code = sanitize_text_field($_POST['tracking_code']);
        $transfer_date = sanitize_text_field($_POST['transfer_date']);
        
        if (!$transaction_id || !$sender_card || !$tracking_code || !$transfer_date) {
            wp_send_json_error(__('لطفاً تمام فیلدهای الزامی را پر کنید.', 'online-exam-payment'));
        }
        
        // Handle file upload if present
        $receipt_url = '';
        if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['size'] > 0) {
            $upload = wp_handle_upload($_FILES['receipt_image'], array('test_form' => false));
            if ($upload && !isset($upload['error'])) {
                $receipt_url = $upload['url'];
            }
        }
        
        $transfer_info = array(
            'sender_card' => $sender_card,
            'tracking_code' => $tracking_code,
            'transfer_date' => $transfer_date,
            'receipt_url' => $receipt_url,
            'submitted_at' => current_time('mysql')
        );
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_transactions';
        
        $result = $wpdb->update(
            $table_name,
            array('card_transfer_info' => json_encode($transfer_info)),
            array('id' => $transaction_id, 'user_id' => get_current_user_id()),
            array('%s'),
            array('%d', '%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(__('اطلاعات واریز شما ثبت شد و در انتظار تایید مدیر سایت است.', 'online-exam-payment'));
        } else {
            wp_send_json_error(__('خطا در ثبت اطلاعات', 'online-exam-payment'));
        }
    }
    
    public function handle_zarinpal_callback() {
        if (!isset($_GET['oep_callback']) || !isset($_GET['Authority'])) {
            return;
        }
        
        $authority = sanitize_text_field($_GET['Authority']);
        $status = sanitize_text_field($_GET['Status'] ?? '');
        $exam_id = intval($_GET['exam_id'] ?? 0);
        
        if ($status !== 'OK') {
            $this->redirect_with_message($exam_id, 'error', __('پرداخت لغو شد یا با خطا مواجه شد.', 'online-exam-payment'));
            return;
        }
        
        // Get transaction
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_transactions';
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE zarinpal_authority = %s AND status = 'pending'",
            $authority
        ));
        
        if (!$transaction) {
            $this->redirect_with_message($exam_id, 'error', __('تراکنش یافت نشد.', 'online-exam-payment'));
            return;
        }
        
        // Verify payment
        $merchant_id = $this->settings['zarinpal_merchant_id'] ?? '';
        $sandbox = isset($this->settings['zarinpal_sandbox']) && $this->settings['zarinpal_sandbox'] == 1;
        $base_url = $sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
        
        $amount_in_rial = $this->convert_to_rial($transaction->amount);
        
        $data = array(
            'merchant_id' => $merchant_id,
            'amount' => $amount_in_rial,
            'authority' => $authority
        );
        
        $response = $this->zarinpal_request($base_url . '/pg/v4/payment/verify.json', $data);
        
        if ($response && isset($response['data']['code']) && $response['data']['code'] == 100) {
            $ref_id = $response['data']['ref_id'];
            
            // Update transaction
            $wpdb->update(
                $table_name,
                array(
                    'status' => 'completed',
                    'zarinpal_ref_id' => $ref_id
                ),
                array('id' => $transaction->id),
                array('%s', '%s'),
                array('%d')
            );
            
            // Grant exam access
            $this->grant_exam_access($transaction->user_id, $transaction->exam_id);
            
            $message = sprintf(__('پرداخت با موفقیت انجام شد. کد پیگیری: %s', 'online-exam-payment'), $ref_id);
            $this->redirect_with_message($exam_id, 'success', $message);
        } else {
            $wpdb->update(
                $table_name,
                array('status' => 'failed'),
                array('id' => $transaction->id),
                array('%s'),
                array('%d')
            );
            
            $error_message = $this->get_zarinpal_error_message($response['errors']['code'] ?? 0);
            $this->redirect_with_message($exam_id, 'error', $error_message);
        }
    }
    
    private function zarinpal_request($url, $data) {
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function get_zarinpal_error_message($code) {
        $errors = array(
            -9 => __('خطای اعتبارسنجی', 'online-exam-payment'),
            -10 => __('ترمینال یافت نشد', 'online-exam-payment'),
            -11 => __('درخواست یافت نشد', 'online-exam-payment'),
            -12 => __('امکان ویرایش درخواست وجود ندارد', 'online-exam-payment'),
            -21 => __('هیچ نوع عملیات مالی برای این تراکنش یافت نشد', 'online-exam-payment'),
            -22 => __('تراکنش ناموفق', 'online-exam-payment'),
            -33 => __('رقم تراکنش با رقم پرداخت شده مطابقت ندارد', 'online-exam-payment'),
            -34 => __('سقف تقسیم تراکنش از لحاظ تعداد یا مبلغ عبور کرده', 'online-exam-payment'),
            -40 => __('اجازه دسترسی به متد مربوطه وجود ندارد', 'online-exam-payment'),
            -41 => __('اطلاعات ارسال شده مربوط به AdditionalData غیر معتبر', 'online-exam-payment'),
            -42 => __('مدت زمان معتبر طول عمر شناسه پرداخت باید بین 30 دقیقه تا 45 روز باشد', 'online-exam-payment'),
            -54 => __('درخواست مورد نظر یافت نشد', 'online-exam-payment'),
            101 => __('تراکنش قبلاً تأیید شده', 'online-exam-payment')
        );
        
        return isset($errors[$code]) ? $errors[$code] : __('خطای نامشخص در پرداخت', 'online-exam-payment');
    }
    
    private function convert_to_rial($amount) {
        $currency = $this->settings['currency'] ?? 'toman';
        return $currency === 'rial' ? $amount : $amount * 10;
    }
    
    private function save_transaction($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_transactions';
        
        $wpdb->insert($table_name, $data);
        return $wpdb->insert_id;
    }
    
    private function user_has_exam_access($user_id, $exam_id) {
        $user_exams = get_user_meta($user_id, 'oep_purchased_exams', true);
        return is_array($user_exams) && in_array($exam_id, $user_exams);
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
    
    private function get_exam_url($exam_id) {
        // This will be implemented when we create the exam engine
        return add_query_arg('exam_id', $exam_id, home_url('exam'));
    }
    
    private function redirect_with_message($exam_id, $type, $message) {
        $url = add_query_arg(array(
            'oep_message' => urlencode($message),
            'oep_type' => $type,
            'exam_id' => $exam_id
        ), home_url());
        
        wp_redirect($url);
        exit;
    }
}