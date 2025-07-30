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
        if (!is_admin()) {
            wp_enqueue_style('oep-shortcodes-css', OEP_PLUGIN_URL . 'assets/css/shortcodes.css', array(), OEP_VERSION);
            wp_enqueue_script('oep-shortcodes-js', OEP_PLUGIN_URL . 'assets/js/shortcodes.js', array('jquery'), OEP_VERSION, true);
            
            wp_localize_script('oep-shortcodes-js', 'oep_shortcodes_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'payment_nonce' => wp_create_nonce('oep_payment_nonce'),
                'strings' => array(
                    'loading' => __('در حال بارگذاری...', 'online-exam-payment'),
                    'error' => __('خطا در انجام عملیات', 'online-exam-payment'),
                    'success' => __('عملیات با موفقیت انجام شد', 'online-exam-payment'),
                    'login_required' => __('برای خرید آزمون باید وارد سایت شوید.', 'online-exam-payment'),
                    'confirm_purchase' => __('آیا از خرید این آزمون اطمینان دارید؟', 'online-exam-payment'),
                    'please_wait' => __('لطفاً صبر کنید...', 'online-exam-payment')
                )
            ));
        }
    }
    
    public function exam_list_shortcode($atts) {
        $atts = shortcode_atts(array(
            'category' => '',
            'limit' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'show_price' => 'yes',
            'show_description' => 'yes',
            'columns' => 3
        ), $atts, 'oep_exam_list');
        
        // Build query args
        $query_args = array(
            'post_type' => 'oep_exam',
            'post_status' => 'publish',
            'numberposts' => intval($atts['limit']),
            'orderby' => sanitize_text_field($atts['orderby']),
            'order' => strtoupper($atts['order']) === 'DESC' ? 'DESC' : 'ASC'
        );
        
        // Add category filter if specified
        if (!empty($atts['category'])) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'oep_exam_category',
                    'field' => 'slug',
                    'terms' => sanitize_text_field($atts['category'])
                )
            );
        }
        
        $exams = get_posts($query_args);
        
        if (empty($exams)) {
            return '<div class="oep-no-exams"><p>' . __('هیچ آزمونی یافت نشد.', 'online-exam-payment') . '</p></div>';
        }
        
        $user_id = get_current_user_id();
        $purchased_exams = $user_id ? get_user_meta($user_id, 'oep_purchased_exams', true) ?: array() : array();
        
        ob_start();
        ?>
        <div class="oep-exam-list" data-columns="<?php echo intval($atts['columns']); ?>">
            <div class="oep-exam-grid">
                <?php foreach ($exams as $exam): ?>
                    <?php
                    $price = get_post_meta($exam->ID, '_oep_exam_price', true);
                    $duration = get_post_meta($exam->ID, '_oep_exam_duration', true);
                    $questions_count = $this->get_exam_questions_count($exam->ID);
                    $has_access = $price == 0 || in_array($exam->ID, $purchased_exams);
                    $categories = get_the_terms($exam->ID, 'oep_exam_category');
                    ?>
                    <div class="oep-exam-card">
                        <?php if (has_post_thumbnail($exam->ID)): ?>
                            <div class="oep-exam-thumbnail">
                                <?php echo get_the_post_thumbnail($exam->ID, 'medium'); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="oep-exam-content">
                            <h3 class="oep-exam-title"><?php echo esc_html($exam->post_title); ?></h3>
                            
                            <?php if ($categories && !is_wp_error($categories)): ?>
                                <div class="oep-exam-categories">
                                    <?php foreach ($categories as $category): ?>
                                        <span class="oep-category-badge"><?php echo esc_html($category->name); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($atts['show_description'] === 'yes' && !empty($exam->post_excerpt)): ?>
                                <div class="oep-exam-description">
                                    <?php echo esc_html($exam->post_excerpt); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="oep-exam-meta">
                                <span class="oep-meta-item">
                                    <i class="oep-icon-questions"></i>
                                    <?php printf(__('%d سوال', 'online-exam-payment'), $questions_count); ?>
                                </span>
                                
                                <?php if ($duration): ?>
                                    <span class="oep-meta-item">
                                        <i class="oep-icon-time"></i>
                                        <?php printf(__('%d دقیقه', 'online-exam-payment'), $duration); ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($atts['show_price'] === 'yes'): ?>
                                    <span class="oep-meta-item oep-price">
                                        <i class="oep-icon-price"></i>
                                        <?php if ($price > 0): ?>
                                            <?php echo number_format($price) . ' ' . __('تومان', 'online-exam-payment'); ?>
                                        <?php else: ?>
                                            <?php _e('رایگان', 'online-exam-payment'); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="oep-exam-actions">
                                <?php if ($has_access): ?>
                                    <a href="<?php echo add_query_arg('exam_id', $exam->ID, home_url('/exam/')); ?>" 
                                       class="oep-btn oep-btn-primary oep-btn-block">
                                        <?php _e('شروع آزمون', 'online-exam-payment'); ?>
                                    </a>
                                <?php else: ?>
                                    <button class="oep-btn oep-btn-success oep-btn-block oep-buy-exam" 
                                            data-exam-id="<?php echo $exam->ID; ?>">
                                        <?php _e('خرید آزمون', 'online-exam-payment'); ?>
                                    </button>
                                <?php endif; ?>
                                
                                <button class="oep-btn oep-btn-outline oep-exam-details" 
                                        data-exam-id="<?php echo $exam->ID; ?>">
                                    <?php _e('جزئیات بیشتر', 'online-exam-payment'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Payment Modal -->
        <div id="oep-payment-modal" class="oep-modal" style="display: none;">
            <div class="oep-modal-content">
                <span class="oep-modal-close">&times;</span>
                <div id="oep-payment-content"></div>
            </div>
        </div>
        
        <!-- Exam Details Modal -->
        <div id="oep-details-modal" class="oep-modal" style="display: none;">
            <div class="oep-modal-content">
                <span class="oep-modal-close">&times;</span>
                <div id="oep-details-content"></div>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    public function user_panel_shortcode($atts) {
        $user_panel = new OEP_User_Panel();
        return $user_panel->display_user_panel();
    }
    
    public function exam_single_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => 0
        ), $atts, 'oep_exam_single');
        
        $exam_id = intval($atts['id']);
        if (!$exam_id) {
            return '<div class="oep-error"><p>' . __('شناسه آزمون مشخص نشده است.', 'online-exam-payment') . '</p></div>';
        }
        
        $exam = get_post($exam_id);
        if (!$exam || $exam->post_type !== 'oep_exam' || $exam->post_status !== 'publish') {
            return '<div class="oep-error"><p>' . __('آزمون مورد نظر یافت نشد.', 'online-exam-payment') . '</p></div>';
        }
        
        $user_id = get_current_user_id();
        $purchased_exams = $user_id ? get_user_meta($user_id, 'oep_purchased_exams', true) ?: array() : array();
        
        // Get exam data
        $price = get_post_meta($exam_id, '_oep_exam_price', true);
        $duration = get_post_meta($exam_id, '_oep_exam_duration', true);
        $pass_score = get_post_meta($exam_id, '_oep_exam_pass_score', true) ?: 60;
        $max_attempts = get_post_meta($exam_id, '_oep_exam_max_attempts', true) ?: 1;
        $questions_count = $this->get_exam_questions_count($exam_id);
        $has_access = $price == 0 || in_array($exam_id, $purchased_exams);
        $categories = get_the_terms($exam_id, 'oep_exam_category');
        
        ob_start();
        ?>
        <div class="oep-exam-single">
            <div class="oep-exam-header">
                <?php if (has_post_thumbnail($exam_id)): ?>
                    <div class="oep-exam-featured-image">
                        <?php echo get_the_post_thumbnail($exam_id, 'large'); ?>
                    </div>
                <?php endif; ?>
                
                <div class="oep-exam-info">
                    <h1 class="oep-exam-title"><?php echo esc_html($exam->post_title); ?></h1>
                    
                    <?php if ($categories && !is_wp_error($categories)): ?>
                        <div class="oep-exam-categories">
                            <?php foreach ($categories as $category): ?>
                                <span class="oep-category-badge"><?php echo esc_html($category->name); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="oep-exam-price">
                        <?php if ($price > 0): ?>
                            <span class="oep-price-amount"><?php echo number_format($price); ?></span>
                            <span class="oep-price-currency"><?php _e('تومان', 'online-exam-payment'); ?></span>
                        <?php else: ?>
                            <span class="oep-price-free"><?php _e('رایگان', 'online-exam-payment'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="oep-exam-body">
                <div class="oep-exam-content">
                    <?php if (!empty($exam->post_content)): ?>
                        <div class="oep-exam-description">
                            <?php echo wpautop($exam->post_content); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="oep-exam-specifications">
                        <h3><?php _e('مشخصات آزمون', 'online-exam-payment'); ?></h3>
                        <ul class="oep-specs-list">
                            <li>
                                <strong><?php _e('تعداد سوالات:', 'online-exam-payment'); ?></strong>
                                <?php echo $questions_count; ?> <?php _e('سوال', 'online-exam-payment'); ?>
                            </li>
                            <?php if ($duration): ?>
                                <li>
                                    <strong><?php _e('مدت زمان:', 'online-exam-payment'); ?></strong>
                                    <?php echo $duration; ?> <?php _e('دقیقه', 'online-exam-payment'); ?>
                                </li>
                            <?php else: ?>
                                <li>
                                    <strong><?php _e('مدت زمان:', 'online-exam-payment'); ?></strong>
                                    <?php _e('نامحدود', 'online-exam-payment'); ?>
                                </li>
                            <?php endif; ?>
                            <li>
                                <strong><?php _e('نمره قبولی:', 'online-exam-payment'); ?></strong>
                                <?php echo $pass_score; ?>%
                            </li>
                            <li>
                                <strong><?php _e('تعداد تلاش مجاز:', 'online-exam-payment'); ?></strong>
                                <?php echo $max_attempts; ?> <?php _e('مرتبه', 'online-exam-payment'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <div class="oep-exam-sidebar">
                    <div class="oep-purchase-box">
                        <?php if ($has_access): ?>
                            <div class="oep-access-granted">
                                <p class="oep-success-message">
                                    <?php _e('شما به این آزمون دسترسی دارید.', 'online-exam-payment'); ?>
                                </p>
                                <a href="<?php echo add_query_arg('exam_id', $exam_id, home_url('/exam/')); ?>" 
                                   class="oep-btn oep-btn-primary oep-btn-large oep-btn-block">
                                    <?php _e('شروع آزمون', 'online-exam-payment'); ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="oep-purchase-info">
                                <div class="oep-price-display">
                                    <?php if ($price > 0): ?>
                                        <span class="oep-price-amount"><?php echo number_format($price); ?></span>
                                        <span class="oep-price-currency"><?php _e('تومان', 'online-exam-payment'); ?></span>
                                    <?php else: ?>
                                        <span class="oep-price-free"><?php _e('رایگان', 'online-exam-payment'); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (is_user_logged_in()): ?>
                                    <button class="oep-btn oep-btn-success oep-btn-large oep-btn-block oep-buy-exam" 
                                            data-exam-id="<?php echo $exam_id; ?>">
                                        <?php _e('خرید آزمون', 'online-exam-payment'); ?>
                                    </button>
                                <?php else: ?>
                                    <a href="<?php echo wp_login_url(get_permalink()); ?>" 
                                       class="oep-btn oep-btn-primary oep-btn-large oep-btn-block">
                                        <?php _e('ورود برای خرید', 'online-exam-payment'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment Modal -->
        <div id="oep-payment-modal" class="oep-modal" style="display: none;">
            <div class="oep-modal-content">
                <span class="oep-modal-close">&times;</span>
                <div id="oep-payment-content"></div>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    public function payment_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'exam_id' => 0
        ), $atts, 'oep_payment_form');
        
        $exam_id = intval($atts['exam_id']);
        if (!$exam_id) {
            return '<div class="oep-error"><p>' . __('شناسه آزمون مشخص نشده است.', 'online-exam-payment') . '</p></div>';
        }
        
        $exam = get_post($exam_id);
        if (!$exam || $exam->post_type !== 'oep_exam') {
            return '<div class="oep-error"><p>' . __('آزمون مورد نظر یافت نشد.', 'online-exam-payment') . '</p></div>';
        }
        
        if (!is_user_logged_in()) {
            return '<div class="oep-login-required"><p>' . 
                   sprintf(__('برای خرید آزمون باید <a href="%s">وارد سایت</a> شوید.', 'online-exam-payment'), wp_login_url()) . 
                   '</p></div>';
        }
        
        $price = get_post_meta($exam_id, '_oep_exam_price', true);
        $settings = get_option('oep_settings', array());
        
        ob_start();
        ?>
        <div class="oep-payment-form">
            <div class="oep-payment-header">
                <h3><?php _e('خرید آزمون', 'online-exam-payment'); ?></h3>
                <div class="oep-exam-info">
                    <h4><?php echo esc_html($exam->post_title); ?></h4>
                    <div class="oep-price">
                        <?php if ($price > 0): ?>
                            <?php echo number_format($price) . ' ' . __('تومان', 'online-exam-payment'); ?>
                        <?php else: ?>
                            <?php _e('رایگان', 'online-exam-payment'); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="oep-payment-methods">
                <h4><?php _e('روش پرداخت را انتخاب کنید:', 'online-exam-payment'); ?></h4>
                
                <div class="oep-payment-options">
                    <?php if (!empty($settings['zarinpal_merchant_id'])): ?>
                        <label class="oep-payment-option">
                            <input type="radio" name="payment_method" value="zarinpal" checked>
                            <div class="oep-option-content">
                                <div class="oep-option-icon">💳</div>
                                <div class="oep-option-info">
                                    <strong><?php _e('پرداخت آنلاین', 'online-exam-payment'); ?></strong>
                                    <span><?php _e('پرداخت امن از طریق زرین‌پال', 'online-exam-payment'); ?></span>
                                </div>
                            </div>
                        </label>
                    <?php endif; ?>
                    
                    <?php if (!empty($settings['card_number'])): ?>
                        <label class="oep-payment-option">
                            <input type="radio" name="payment_method" value="card_transfer">
                            <div class="oep-option-content">
                                <div class="oep-option-icon">🏦</div>
                                <div class="oep-option-info">
                                    <strong><?php _e('کارت به کارت', 'online-exam-payment'); ?></strong>
                                    <span><?php _e('انتقال وجه به حساب بانکی', 'online-exam-payment'); ?></span>
                                </div>
                            </div>
                        </label>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="oep-payment-actions">
                <button type="button" class="oep-btn oep-btn-primary oep-btn-large oep-proceed-payment" 
                        data-exam-id="<?php echo $exam_id; ?>">
                    <?php _e('ادامه پرداخت', 'online-exam-payment'); ?>
                </button>
            </div>
        </div>
        
        <!-- Card Transfer Modal -->
        <div id="oep-card-transfer-modal" class="oep-modal" style="display: none;">
            <div class="oep-modal-content">
                <span class="oep-modal-close">&times;</span>
                <div id="oep-card-transfer-content"></div>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    private function get_exam_questions_count($exam_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_oep_question_exam_id' AND meta_value = %d",
            $exam_id
        ));
    }
    
    private function user_has_exam_access($user_id, $exam_id) {
        if (!$user_id) return false;
        
        $purchased_exams = get_user_meta($user_id, 'oep_purchased_exams', true);
        return is_array($purchased_exams) && in_array($exam_id, $purchased_exams);
    }
}