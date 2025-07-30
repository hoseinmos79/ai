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
        
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_oep_start_payment', array($this, 'start_payment'));
        add_action('wp_ajax_nopriv_oep_start_payment', array($this, 'start_payment'));
        add_action('wp_ajax_oep_submit_card_transfer', array($this, 'submit_card_transfer'));
        add_action('wp_ajax_nopriv_oep_submit_card_transfer', array($this, 'submit_card_transfer'));
        add_action('init', array($this, 'handle_zarinpal_callback'));
    }
    
    public function enqueue_scripts() {
        if (!is_admin()) {
            wp_enqueue_script('oep-payment', OEP_PLUGIN_URL . 'assets/js/payment.js', array('jquery'), OEP_VERSION, true);
            wp_localize_script('oep-payment', 'oep_payment_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('oep_payment_nonce'),
                'strings' => array(
                    'processing' => __('در حال پردازش...', 'online-exam-payment'),
                    'error' => __('خطا در انجام عملیات', 'online-exam-payment'),
                    'success' => __('عملیات با موفقیت انجام شد', 'online-exam-payment'),
                    'please_wait' => __('لطفاً صبر کنید...', 'online-exam-payment'),
                    'redirecting' => __('در حال انتقال به درگاه پرداخت...', 'online-exam-payment')
                )
            ));
        }
    }
    
    public function start_payment() {
        check_ajax_referer('oep_payment_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('برای خرید آزمون باید وارد سایت شوید.', 'online-exam-payment'));
        }
        
        $exam_id = intval($_POST['exam_id']);
        $payment_method = sanitize_text_field($_POST['payment_method']);
        
        if (!$exam_id || !in_array($payment_method, array('zarinpal', 'card_transfer'))) {
            wp_send_json_error(__('اطلاعات ارسالی نامعتبر است.', 'online-exam-payment'));
        }
        
        $exam = get_post($exam_id);
        if (!$exam || $exam->post_type !== 'oep_exam') {
            wp_send_json_error(__('آزمون مورد نظر یافت نشد.', 'online-exam-payment'));
        }
        
        $user_id = get_current_user_id();
        
        // Check if user already has access
        if ($this->user_has_exam_access($user_id, $exam_id)) {
            wp_send_json_error(__('شما قبلاً این آزمون را خریداری کرده‌اید.', 'online-exam-payment'));
        }
        
        $price = get_post_meta($exam_id, '_oep_exam_price', true);
        
        // Handle free exams
        if (!$price || $price == 0) {
            $this->grant_exam_access($user_id, $exam_id);
            wp_send_json_success(array(
                'message' => __('آزمون رایگان با موفقیت به حساب شما اضافه شد.', 'online-exam-payment'),
                'redirect' => $this->get_exam_url($exam_id)
            ));
        }
        
        // Process payment based on method
        if ($payment_method === 'zarinpal') {
            $this->process_zarinpal_payment($exam_id, $price, $price);
        } else {
            $this->process_card_transfer_payment($exam_id, $price);
        }
    }
    
    private function process_zarinpal_payment($exam_id, $amount_in_toman, $original_amount) {
        $merchant_id = $this->settings['zarinpal_merchant_id'] ?? '';
        if (empty($merchant_id)) {
            wp_send_json_error(__('درگاه پرداخت پیکربندی نشده است.', 'online-exam-payment'));
        }
        
        $amount_in_rial = $this->convert_to_rial($amount_in_toman);
        $exam = get_post($exam_id);
        $callback_url = add_query_arg(array(
            'oep_callback' => 'zarinpal',
            'exam_id' => $exam_id
        ), home_url('/'));
        
        $is_sandbox = $this->settings['zarinpal_sandbox'] ?? 0;
        $api_url = $is_sandbox ? 
            'https://sandbox.zarinpal.com/pg/rest/WebGate/PaymentRequest.json' : 
            'https://api.zarinpal.com/pg/v4/payment/request.json';
        
        $data = array(
            'merchant_id' => $merchant_id,
            'amount' => $amount_in_rial,
            'description' => sprintf(__('خرید آزمون: %s', 'online-exam-payment'), $exam->post_title),
            'callback_url' => $callback_url,
            'metadata' => array(
                'email' => wp_get_current_user()->user_email,
                'mobile' => get_user_meta(get_current_user_id(), 'mobile', true)
            )
        );
        
        $response = $this->zarinpal_request($api_url, $data);
        
        if ($response && isset($response['data']['code']) && $response['data']['code'] == 100) {
            $authority = $response['data']['authority'];
            
            // Save pending transaction
            $transaction_id = $this->save_transaction(array(
                'user_id' => get_current_user_id(),
                'exam_id' => $exam_id,
                'amount' => $original_amount,
                'payment_method' => 'zarinpal',
                'status' => 'pending',
                'zarinpal_authority' => $authority
            ));
            
            $payment_url = $is_sandbox ?
                'https://sandbox.zarinpal.com/pg/StartPay/' . $authority :
                'https://www.zarinpal.com/pg/StartPay/' . $authority;
            
            wp_send_json_success(array(
                'redirect_url' => $payment_url,
                'message' => __('در حال انتقال به درگاه پرداخت...', 'online-exam-payment')
            ));
        } else {
            $error_code = $response['errors']['code'] ?? 0;
            $error_message = $this->get_zarinpal_error_message($error_code);
            wp_send_json_error($error_message);
        }
    }
    
    private function process_card_transfer_payment($exam_id, $amount) {
        $user_id = get_current_user_id();
        
        // Save pending transaction
        $transaction_id = $this->save_transaction(array(
            'user_id' => $user_id,
            'exam_id' => $exam_id,
            'amount' => $amount,
            'payment_method' => 'card_transfer',
            'status' => 'pending'
        ));
        
        $card_info = array(
            'card_number' => $this->settings['card_number'] ?? '',
            'card_holder_name' => $this->settings['card_holder_name'] ?? '',
            'amount' => number_format($amount),
            'instructions' => $this->settings['card_transfer_instructions'] ?? '',
            'transaction_id' => $transaction_id
        );
        
        wp_send_json_success(array(
            'show_card_form' => true,
            'card_info' => $card_info,
            'message' => __('اطلاعات کارت به کارت آماده شد.', 'online-exam-payment')
        ));
    }
    
    public function submit_card_transfer() {
        check_ajax_referer('oep_payment_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('برای ثبت اطلاعات باید وارد سایت شوید.', 'online-exam-payment'));
        }
        
        $transaction_id = intval($_POST['transaction_id']);
        $card_last_digits = sanitize_text_field($_POST['card_last_digits']);
        $tracking_code = sanitize_text_field($_POST['tracking_code']);
        $transfer_date = sanitize_text_field($_POST['transfer_date']);
        $additional_info = sanitize_textarea_field($_POST['additional_info']);
        
        if (!$transaction_id || !$card_last_digits || !$tracking_code) {
            wp_send_json_error(__('لطفاً تمامی فیلدهای الزامی را پر کنید.', 'online-exam-payment'));
        }
        
        // Handle receipt upload
        $receipt_url = '';
        if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $upload = wp_handle_upload($_FILES['receipt'], array('test_form' => false));
            if (!isset($upload['error'])) {
                $receipt_url = $upload['url'];
            }
        }
        
        $transfer_info = array(
            'card_last_digits' => $card_last_digits,
            'tracking_code' => $tracking_code,
            'transfer_date' => $transfer_date,
            'additional_info' => $additional_info,
            'receipt_url' => $receipt_url,
            'submitted_at' => current_time('mysql')
        );
        
        // Update transaction with transfer info
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
            wp_send_json_success(__('اطلاعات انتقال با موفقیت ثبت شد. پس از بررسی توسط مدیر، دسترسی شما فعال خواهد شد.', 'online-exam-payment'));
        } else {
            wp_send_json_error(__('خطا در ثبت اطلاعات. لطفاً مجدداً تلاش کنید.', 'online-exam-payment'));
        }
    }
    
    public function handle_zarinpal_callback() {
        if (!isset($_GET['oep_callback']) || $_GET['oep_callback'] !== 'zarinpal') {
            return;
        }
        
        $status = $_GET['Status'] ?? '';
        $authority = $_GET['Authority'] ?? '';
        $exam_id = intval($_GET['exam_id'] ?? 0);
        
        if (!$exam_id || !$authority) {
            $this->redirect_with_message($exam_id, 'error', __('اطلاعات پرداخت نامعتبر است.', 'online-exam-payment'));
            return;
        }
        
        if ($status !== 'OK') {
            $this->redirect_with_message($exam_id, 'error', __('پرداخت لغو شد یا با خطا مواجه شد.', 'online-exam-payment'));
            return;
        }
        
        // Find transaction
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_transactions';
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE zarinpal_authority = %s AND status = 'pending'",
            $authority
        ));
        
        if (!$transaction) {
            $this->redirect_with_message($exam_id, 'error', __('تراکنش مورد نظر یافت نشد.', 'online-exam-payment'));
            return;
        }
        
        // Verify payment
        $merchant_id = $this->settings['zarinpal_merchant_id'] ?? '';
        $amount_in_rial = $this->convert_to_rial($transaction->amount);
        
        $is_sandbox = $this->settings['zarinpal_sandbox'] ?? 0;
        $verify_url = $is_sandbox ?
            'https://sandbox.zarinpal.com/pg/rest/WebGate/PaymentVerification.json' :
            'https://api.zarinpal.com/pg/v4/payment/verify.json';
        
        $verify_data = array(
            'merchant_id' => $merchant_id,
            'amount' => $amount_in_rial,
            'authority' => $authority
        );
        
        $verify_response = $this->zarinpal_request($verify_url, $verify_data);
        
        if ($verify_response && isset($verify_response['data']['code']) && $verify_response['data']['code'] == 100) {
            $ref_id = $verify_response['data']['ref_id'];
            
            // Update transaction as completed
            $wpdb->update(
                $table_name,
                array(
                    'status' => 'completed',
                    'zarinpal_ref_id' => $ref_id,
                    'transaction_id' => $ref_id
                ),
                array('id' => $transaction->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
            
            // Grant exam access
            $this->grant_exam_access($transaction->user_id, $transaction->exam_id);
            
            $this->redirect_with_message($exam_id, 'success', 
                sprintf(__('پرداخت با موفقیت انجام شد. شماره پیگیری: %s', 'online-exam-payment'), $ref_id)
            );
        } else {
            // Update transaction as failed
            $wpdb->update(
                $table_name,
                array('status' => 'failed'),
                array('id' => $transaction->id),
                array('%s'),
                array('%d')
            );
            
            $error_code = $verify_response['errors']['code'] ?? 0;
            $error_message = $this->get_zarinpal_error_message($error_code);
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
            'timeout' => 30,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function get_zarinpal_error_message($code) {
        $error_messages = array(
            -9 => __('خطای اعتبارسنجی', 'online-exam-payment'),
            -10 => __('ترمینال شما تایید نشده است', 'online-exam-payment'),
            -11 => __('درخواست مورد نظر یافت نشد', 'online-exam-payment'),
            -12 => __('امکان ویرایش درخواست میسر نمی‌باشد', 'online-exam-payment'),
            -15 => __('ترمینال شما غیرفعال است', 'online-exam-payment'),
            -16 => __('سطح تایید پذیرنده پایین‌تر از سطح نقره‌ای است', 'online-exam-payment'),
            -30 => __('اجازه دسترسی به تسویه اشتراکی شناور ندارید', 'online-exam-payment'),
            -31 => __('حساب بانکی تسویه را به پنل اضافه کنید', 'online-exam-payment'),
            -32 => __('Shaparak ID معتبر نیست', 'online-exam-payment'),
            -33 => __('مبلغ کمتر از حداقل مجاز است', 'online-exam-payment'),
            -34 => __('مبلغ بیشتر از حداکثر مجاز است', 'online-exam-payment'),
            -40 => __('پارامترهای اضافی نامعتبر', 'online-exam-payment'),
            -41 => __('حداکثر تعداد تراکنش‌های در انتظار پرداخت', 'online-exam-payment'),
            -42 => __('شناسه قبض نامعتبر است', 'online-exam-payment'),
            -43 => __('شناسه پرداخت نامعتبر است', 'online-exam-payment'),
            -44 => __('شناسه قبض تکراری است', 'online-exam-payment'),
            -45 => __('قبض منقضی شده است', 'online-exam-payment'),
            -46 => __('قبض نامعتبر است', 'online-exam-payment'),
            -47 => __('شناسه قبض نامعتبر است', 'online-exam-payment'),
            -48 => __('قبض بلامانع نیست', 'online-exam-payment'),
            -49 => __('شناسه تسهیم نامعتبر است', 'online-exam-payment'),
            -50 => __('مبلغ تسهیم بیشتر از کل مبلغ است', 'online-exam-payment'),
            -51 => __('تسهیم عادلانه نیست', 'online-exam-payment'),
            -52 => __('استعلام ناموفق', 'online-exam-payment'),
            -53 => __('پذیرنده تسهیم تایید نشده است', 'online-exam-payment'),
            -54 => __('مدت زمان تسهیم نامعتبر است', 'online-exam-payment'),
            101 => __('تراکنش قبلاً تایید شده است', 'online-exam-payment')
        );
        
        return $error_messages[$code] ?? sprintf(__('خطای ناشناخته: %d', 'online-exam-payment'), $code);
    }
    
    private function convert_to_rial($amount) {
        $currency = $this->settings['currency'] ?? 'toman';
        return $currency === 'toman' ? $amount * 10 : $amount;
    }
    
    private function save_transaction($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oep_transactions';
        
        $wpdb->insert($table_name, $data);
        return $wpdb->insert_id;
    }
    
    private function user_has_exam_access($user_id, $exam_id) {
        $purchased_exams = get_user_meta($user_id, 'oep_purchased_exams', true);
        return is_array($purchased_exams) && in_array($exam_id, $purchased_exams);
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
    
    private function get_exam_url($exam_id) {
        return add_query_arg('exam_id', $exam_id, home_url('/exam/'));
    }
    
    private function redirect_with_message($exam_id, $type, $message) {
        $redirect_url = add_query_arg(array(
            'oep_message' => urlencode($message),
            'oep_message_type' => $type
        ), $this->get_exam_url($exam_id));
        
        wp_redirect($redirect_url);
        exit;
    }
}