<?php
/**
 * Shortcodes for Online Exam Payment Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEP_Shortcodes {
    
    public function __construct() {
        add_action('init', array($this, 'register_shortcodes'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_shortcode_scripts'));
    }
    
    public function register_shortcodes() {
        add_shortcode('oep_exam_list', array($this, 'exam_list_shortcode'));
        add_shortcode('oep_user_panel', array($this, 'user_panel_shortcode'));
        add_shortcode('oep_exam_single', array($this, 'exam_single_shortcode'));
        add_shortcode('oep_payment_form', array($this, 'payment_form_shortcode'));
    }
    
    public function enqueue_shortcode_scripts() {
        wp_enqueue_style('oep-shortcodes', OEP_PLUGIN_URL . 'assets/css/shortcodes.css', array(), OEP_VERSION);
        wp_enqueue_script('oep-shortcodes', OEP_PLUGIN_URL . 'assets/js/shortcodes.js', array('jquery'), OEP_VERSION, true);
        
        wp_localize_script('oep-shortcodes', 'oep_shortcodes', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'payment_nonce' => wp_create_nonce('oep_payment_nonce'),
            'user_nonce' => wp_create_nonce('oep_user_nonce'),
            'strings' => array(
                'login_required' => __('برای خرید آزمون باید وارد حساب کاربری خود شوید.', 'online-exam-payment'),
                'select_payment_method' => __('لطفاً روش پرداخت را انتخاب کنید.', 'online-exam-payment'),
                'processing' => __('در حال پردازش...', 'online-exam-payment'),
                'error' => __('خطا در پردازش درخواست', 'online-exam-payment')
            )
        ));
    }
    
    /**
     * Display list of exams
     * Usage: [oep_exam_list category="category-slug" limit="10" show_price="true"]
     */
    public function exam_list_shortcode($atts) {
        $atts = shortcode_atts(array(
            'category' => '',
            'limit' => -1,
            'show_price' => 'true',
            'show_description' => 'true',
            'columns' => '3'
        ), $atts);
        
        $args = array(
            'post_type' => 'oep_exam',
            'posts_per_page' => intval($atts['limit']),
            'post_status' => 'publish'
        );
        
        if (!empty($atts['category'])) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'oep_exam_category',
                    'field' => 'slug',
                    'terms' => $atts['category']
                )
            );
        }
        
        $exams = get_posts($args);
        
        if (empty($exams)) {
            return '<p class="oep-no-exams">' . __('هیچ آزمونی یافت نشد.', 'online-exam-payment') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="oep-exam-list oep-columns-<?php echo esc_attr($atts['columns']); ?>">
            <?php foreach ($exams as $exam): 
                $price = get_post_meta($exam->ID, '_oep_exam_price', true);
                $duration = get_post_meta($exam->ID, '_oep_exam_duration', true);
                $questions_count = $this->get_exam_questions_count($exam->ID);
                $user_has_access = is_user_logged_in() ? $this->user_has_exam_access(get_current_user_id(), $exam->ID) : false;
                $settings = get_option('oep_settings', array());
                $currency = $settings['currency'] ?? 'toman';
                $currency_label = $currency === 'rial' ? __('ریال', 'online-exam-payment') : __('تومان', 'online-exam-payment');
            ?>
            <div class="oep-exam-card" data-exam-id="<?php echo $exam->ID; ?>">
                <?php if (has_post_thumbnail($exam->ID)): ?>
                    <div class="oep-exam-thumbnail">
                        <?php echo get_the_post_thumbnail($exam->ID, 'medium'); ?>
                    </div>
                <?php endif; ?>
                
                <div class="oep-exam-content">
                    <h3 class="oep-exam-title"><?php echo esc_html($exam->post_title); ?></h3>
                    
                    <?php if ($atts['show_description'] === 'true' && !empty($exam->post_content)): ?>
                        <div class="oep-exam-description">
                            <?php echo wp_trim_words($exam->post_content, 20); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="oep-exam-meta">
                        <span class="oep-questions-count">
                            <i class="dashicons dashicons-editor-help"></i>
                            <?php printf(__('%d سوال', 'online-exam-payment'), $questions_count); ?>
                        </span>
                        
                        <?php if ($duration): ?>
                            <span class="oep-duration">
                                <i class="dashicons dashicons-clock"></i>
                                <?php printf(__('%d دقیقه', 'online-exam-payment'), $duration); ?>
                            </span>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_price'] === 'true'): ?>
                            <span class="oep-price">
                                <i class="dashicons dashicons-money-alt"></i>
                                <?php if ($price && $price > 0): ?>
                                    <?php echo number_format($price) . ' ' . $currency_label; ?>
                                <?php else: ?>
                                    <?php _e('رایگان', 'online-exam-payment'); ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="oep-exam-actions">
                        <?php if ($user_has_access): ?>
                            <a href="<?php echo add_query_arg(array('oep_exam' => '1', 'exam_id' => $exam->ID), home_url()); ?>" 
                               class="button button-primary oep-start-exam">
                                <?php _e('شروع آزمون', 'online-exam-payment'); ?>
                            </a>
                        <?php elseif (!$price || $price <= 0): ?>
                            <a href="<?php echo add_query_arg(array('oep_exam' => '1', 'exam_id' => $exam->ID), home_url()); ?>" 
                               class="button button-primary oep-start-exam">
                                <?php _e('شروع آزمون رایگان', 'online-exam-payment'); ?>
                            </a>
                        <?php else: ?>
                            <button class="button button-primary oep-buy-exam" data-exam-id="<?php echo $exam->ID; ?>">
                                <?php _e('خرید آزمون', 'online-exam-payment'); ?>
                            </button>
                        <?php endif; ?>
                        
                        <button class="button oep-exam-details" data-exam-id="<?php echo $exam->ID; ?>">
                            <?php _e('جزئیات', 'online-exam-payment'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Payment Modal -->
        <div id="oep-payment-modal" class="oep-modal" style="display: none;">
            <div class="oep-modal-content">
                <span class="oep-modal-close">&times;</span>
                <div id="oep-payment-content">
                    <!-- Payment form will be loaded here -->
                </div>
            </div>
        </div>
        
        <!-- Exam Details Modal -->
        <div id="oep-exam-details-modal" class="oep-modal" style="display: none;">
            <div class="oep-modal-content">
                <span class="oep-modal-close">&times;</span>
                <div id="oep-exam-details-content">
                    <!-- Exam details will be loaded here -->
                </div>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Display user panel
     * Usage: [oep_user_panel]
     */
    public function user_panel_shortcode($atts) {
        if (!class_exists('OEP_User_Panel')) {
            return '<p>' . __('خطا در بارگذاری پنل کاربری', 'online-exam-payment') . '</p>';
        }
        
        $user_panel = new OEP_User_Panel();
        return $user_panel->display_user_panel();
    }
    
    /**
     * Display single exam with purchase option
     * Usage: [oep_exam_single id="123"]
     */
    public function exam_single_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => 0
        ), $atts);
        
        $exam_id = intval($atts['id']);
        if (!$exam_id) {
            return '<p>' . __('شناسه آزمون مشخص نشده است.', 'online-exam-payment') . '</p>';
        }
        
        $exam = get_post($exam_id);
        if (!$exam || $exam->post_type !== 'oep_exam' || $exam->post_status !== 'publish') {
            return '<p>' . __('آزمون یافت نشد.', 'online-exam-payment') . '</p>';
        }
        
        $price = get_post_meta($exam_id, '_oep_exam_price', true);
        $duration = get_post_meta($exam_id, '_oep_exam_duration', true);
        $pass_score = get_post_meta($exam_id, '_oep_exam_pass_score', true) ?: 60;
        $max_attempts = get_post_meta($exam_id, '_oep_exam_max_attempts', true) ?: 1;
        $questions_count = $this->get_exam_questions_count($exam_id);
        $user_has_access = is_user_logged_in() ? $this->user_has_exam_access(get_current_user_id(), $exam_id) : false;
        
        $settings = get_option('oep_settings', array());
        $currency = $settings['currency'] ?? 'toman';
        $currency_label = $currency === 'rial' ? __('ریال', 'online-exam-payment') : __('تومان', 'online-exam-payment');
        
        ob_start();
        ?>
        <div class="oep-exam-single" data-exam-id="<?php echo $exam_id; ?>">
            <div class="oep-exam-header">
                <h1 class="oep-exam-title"><?php echo esc_html($exam->post_title); ?></h1>
                
                <?php if (has_post_thumbnail($exam_id)): ?>
                    <div class="oep-exam-featured-image">
                        <?php echo get_the_post_thumbnail($exam_id, 'large'); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="oep-exam-info-grid">
                <div class="oep-exam-details">
                    <h3><?php _e('جزئیات آزمون', 'online-exam-payment'); ?></h3>
                    <ul class="oep-exam-specs">
                        <li>
                            <strong><?php _e('تعداد سوالات:', 'online-exam-payment'); ?></strong>
                            <?php echo $questions_count; ?> <?php _e('سوال', 'online-exam-payment'); ?>
                        </li>
                        <?php if ($duration): ?>
                        <li>
                            <strong><?php _e('مدت زمان:', 'online-exam-payment'); ?></strong>
                            <?php echo $duration; ?> <?php _e('دقیقه', 'online-exam-payment'); ?>
                        </li>
                        <?php endif; ?>
                        <li>
                            <strong><?php _e('حدنصاب قبولی:', 'online-exam-payment'); ?></strong>
                            <?php echo $pass_score; ?>%
                        </li>
                        <li>
                            <strong><?php _e('تعداد تلاش مجاز:', 'online-exam-payment'); ?></strong>
                            <?php echo $max_attempts; ?> <?php _e('بار', 'online-exam-payment'); ?>
                        </li>
                        <li>
                            <strong><?php _e('قیمت:', 'online-exam-payment'); ?></strong>
                            <?php if ($price && $price > 0): ?>
                                <?php echo number_format($price) . ' ' . $currency_label; ?>
                            <?php else: ?>
                                <?php _e('رایگان', 'online-exam-payment'); ?>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
                
                <div class="oep-exam-purchase">
                    <div class="oep-purchase-box">
                        <?php if ($user_has_access): ?>
                            <div class="oep-access-granted">
                                <h4><?php _e('شما دسترسی به این آزمون را دارید', 'online-exam-payment'); ?></h4>
                                <a href="<?php echo add_query_arg(array('oep_exam' => '1', 'exam_id' => $exam_id), home_url()); ?>" 
                                   class="button button-primary button-large">
                                    <?php _e('شروع آزمون', 'online-exam-payment'); ?>
                                </a>
                            </div>
                        <?php elseif (!$price || $price <= 0): ?>
                            <div class="oep-free-exam">
                                <h4><?php _e('آزمون رایگان', 'online-exam-payment'); ?></h4>
                                <a href="<?php echo add_query_arg(array('oep_exam' => '1', 'exam_id' => $exam_id), home_url()); ?>" 
                                   class="button button-primary button-large">
                                    <?php _e('شروع آزمون', 'online-exam-payment'); ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="oep-purchase-form">
                                <div class="oep-price-display">
                                    <span class="oep-price-amount"><?php echo number_format($price); ?></span>
                                    <span class="oep-price-currency"><?php echo $currency_label; ?></span>
                                </div>
                                
                                <?php if (is_user_logged_in()): ?>
                                    <button class="button button-primary button-large oep-buy-exam" data-exam-id="<?php echo $exam_id; ?>">
                                        <?php _e('خرید و شروع آزمون', 'online-exam-payment'); ?>
                                    </button>
                                <?php else: ?>
                                    <p class="oep-login-required">
                                        <?php _e('برای خرید آزمون باید وارد حساب کاربری خود شوید.', 'online-exam-payment'); ?>
                                    </p>
                                    <a href="<?php echo wp_login_url(get_permalink()); ?>" class="button button-primary button-large">
                                        <?php _e('ورود به حساب کاربری', 'online-exam-payment'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($exam->post_content)): ?>
                <div class="oep-exam-description">
                    <h3><?php _e('توضیحات آزمون', 'online-exam-payment'); ?></h3>
                    <div class="oep-exam-content">
                        <?php echo wpautop($exam->post_content); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Payment Modal -->
        <div id="oep-payment-modal" class="oep-modal" style="display: none;">
            <div class="oep-modal-content">
                <span class="oep-modal-close">&times;</span>
                <div id="oep-payment-content">
                    <!-- Payment form will be loaded here -->
                </div>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Display payment form
     * Usage: [oep_payment_form exam_id="123"]
     */
    public function payment_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'exam_id' => 0
        ), $atts);
        
        $exam_id = intval($atts['exam_id']);
        if (!$exam_id) {
            return '<p>' . __('شناسه آزمون مشخص نشده است.', 'online-exam-payment') . '</p>';
        }
        
        if (!is_user_logged_in()) {
            return '<p>' . __('برای خرید آزمون باید وارد حساب کاربری خود شوید.', 'online-exam-payment') . '</p>';
        }
        
        $exam = get_post($exam_id);
        if (!$exam || $exam->post_type !== 'oep_exam') {
            return '<p>' . __('آزمون یافت نشد.', 'online-exam-payment') . '</p>';
        }
        
        $price = get_post_meta($exam_id, '_oep_exam_price', true);
        if (!$price || $price <= 0) {
            return '<p>' . __('این آزمون رایگان است.', 'online-exam-payment') . '</p>';
        }
        
        if ($this->user_has_exam_access(get_current_user_id(), $exam_id)) {
            return '<p>' . __('شما قبلاً این آزمون را خریداری کرده‌اید.', 'online-exam-payment') . '</p>';
        }
        
        $settings = get_option('oep_settings', array());
        $currency = $settings['currency'] ?? 'toman';
        $currency_label = $currency === 'rial' ? __('ریال', 'online-exam-payment') : __('تومان', 'online-exam-payment');
        
        ob_start();
        ?>
        <div class="oep-payment-form" data-exam-id="<?php echo $exam_id; ?>">
            <h3><?php _e('خرید آزمون', 'online-exam-payment'); ?></h3>
            
            <div class="oep-payment-summary">
                <h4><?php echo esc_html($exam->post_title); ?></h4>
                <div class="oep-payment-amount">
                    <span><?php _e('مبلغ قابل پرداخت:', 'online-exam-payment'); ?></span>
                    <strong><?php echo number_format($price) . ' ' . $currency_label; ?></strong>
                </div>
            </div>
            
            <div class="oep-payment-methods">
                <h4><?php _e('روش پرداخت را انتخاب کنید:', 'online-exam-payment'); ?></h4>
                
                <?php if (!empty($settings['zarinpal_merchant_id'])): ?>
                <label class="oep-payment-method">
                    <input type="radio" name="payment_method" value="zarinpal" checked>
                    <span class="oep-method-info">
                        <strong><?php _e('پرداخت آنلاین (زرین‌پال)', 'online-exam-payment'); ?></strong>
                        <small><?php _e('پرداخت امن با کارت‌های بانکی', 'online-exam-payment'); ?></small>
                    </span>
                </label>
                <?php endif; ?>
                
                <?php if (!empty($settings['card_number'])): ?>
                <label class="oep-payment-method">
                    <input type="radio" name="payment_method" value="card_transfer">
                    <span class="oep-method-info">
                        <strong><?php _e('واریز کارت به کارت', 'online-exam-payment'); ?></strong>
                        <small><?php _e('واریز مستقیم به حساب بانکی', 'online-exam-payment'); ?></small>
                    </span>
                </label>
                <?php endif; ?>
            </div>
            
            <div class="oep-payment-actions">
                <button class="button button-primary button-large oep-proceed-payment">
                    <?php _e('ادامه پرداخت', 'online-exam-payment'); ?>
                </button>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    private function get_exam_questions_count($exam_id) {
        return count(get_posts(array(
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
        )));
    }
    
    private function user_has_exam_access($user_id, $exam_id) {
        $purchased_exams = get_user_meta($user_id, 'oep_purchased_exams', true);
        return is_array($purchased_exams) && in_array($exam_id, $purchased_exams);
    }
}