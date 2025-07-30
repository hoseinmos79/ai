<?php
/**
 * Custom Post Types for Online Exam Payment Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEP_Custom_Post_Types {
    
    public function __construct() {
        add_action('init', array($this, 'register_post_types'));
        add_action('init', array($this, 'register_taxonomies'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_exam_meta'));
        add_action('save_post', array($this, 'save_question_meta'));
    }
    
    public function register_post_types() {
        // Register Exam post type
        $exam_labels = array(
            'name' => __('آزمون‌ها', 'online-exam-payment'),
            'singular_name' => __('آزمون', 'online-exam-payment'),
            'add_new' => __('افزودن آزمون جدید', 'online-exam-payment'),
            'add_new_item' => __('افزودن آزمون جدید', 'online-exam-payment'),
            'edit_item' => __('ویرایش آزمون', 'online-exam-payment'),
            'new_item' => __('آزمون جدید', 'online-exam-payment'),
            'view_item' => __('مشاهده آزمون', 'online-exam-payment'),
            'search_items' => __('جستجو در آزمون‌ها', 'online-exam-payment'),
            'not_found' => __('آزمونی یافت نشد', 'online-exam-payment'),
            'not_found_in_trash' => __('آزمونی در زباله‌دان یافت نشد', 'online-exam-payment'),
            'menu_name' => __('آزمون‌ها', 'online-exam-payment')
        );
        
        $exam_args = array(
            'labels' => $exam_labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => false, // We'll add it to our custom admin menu
            'query_var' => true,
            'rewrite' => array('slug' => 'exam'),
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => null,
            'supports' => array('title', 'editor', 'thumbnail'),
            'menu_icon' => 'dashicons-clipboard'
        );
        
        register_post_type('oep_exam', $exam_args);
        
        // Register Question post type
        $question_labels = array(
            'name' => __('سوالات', 'online-exam-payment'),
            'singular_name' => __('سوال', 'online-exam-payment'),
            'add_new' => __('افزودن سوال جدید', 'online-exam-payment'),
            'add_new_item' => __('افزودن سوال جدید', 'online-exam-payment'),
            'edit_item' => __('ویرایش سوال', 'online-exam-payment'),
            'new_item' => __('سوال جدید', 'online-exam-payment'),
            'view_item' => __('مشاهده سوال', 'online-exam-payment'),
            'search_items' => __('جستجو در سوالات', 'online-exam-payment'),
            'not_found' => __('سوالی یافت نشد', 'online-exam-payment'),
            'not_found_in_trash' => __('سوالی در زباله‌دان یافت نشد', 'online-exam-payment'),
            'menu_name' => __('سوالات', 'online-exam-payment')
        );
        
        $question_args = array(
            'labels' => $question_labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => false, // We'll add it to our custom admin menu
            'query_var' => true,
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => null,
            'supports' => array('title', 'editor'),
            'menu_icon' => 'dashicons-editor-help'
        );
        
        register_post_type('oep_question', $question_args);
    }
    
    public function register_taxonomies() {
        // Register exam categories taxonomy
        $category_labels = array(
            'name' => __('دسته‌بندی آزمون‌ها', 'online-exam-payment'),
            'singular_name' => __('دسته‌بندی', 'online-exam-payment'),
            'search_items' => __('جستجو در دسته‌بندی‌ها', 'online-exam-payment'),
            'all_items' => __('همه دسته‌بندی‌ها', 'online-exam-payment'),
            'parent_item' => __('دسته‌بندی والد', 'online-exam-payment'),
            'parent_item_colon' => __('دسته‌بندی والد:', 'online-exam-payment'),
            'edit_item' => __('ویرایش دسته‌بندی', 'online-exam-payment'),
            'update_item' => __('به‌روزرسانی دسته‌بندی', 'online-exam-payment'),
            'add_new_item' => __('افزودن دسته‌بندی جدید', 'online-exam-payment'),
            'new_item_name' => __('نام دسته‌بندی جدید', 'online-exam-payment'),
            'menu_name' => __('دسته‌بندی‌ها', 'online-exam-payment'),
        );
        
        $category_args = array(
            'hierarchical' => true,
            'labels' => $category_labels,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'exam-category'),
        );
        
        register_taxonomy('oep_exam_category', array('oep_exam'), $category_args);
    }
    
    public function add_meta_boxes() {
        // Exam meta boxes
        add_meta_box(
            'oep_exam_settings',
            __('تنظیمات آزمون', 'online-exam-payment'),
            array($this, 'exam_settings_meta_box'),
            'oep_exam',
            'normal',
            'high'
        );
        
        // Question meta boxes
        add_meta_box(
            'oep_question_options',
            __('گزینه‌های سوال', 'online-exam-payment'),
            array($this, 'question_options_meta_box'),
            'oep_question',
            'normal',
            'high'
        );
        
        add_meta_box(
            'oep_question_exam',
            __('آزمون مربوطه', 'online-exam-payment'),
            array($this, 'question_exam_meta_box'),
            'oep_question',
            'side',
            'default'
        );
    }
    
    public function exam_settings_meta_box($post) {
        wp_nonce_field('oep_exam_meta_nonce', 'oep_exam_meta_nonce');
        
        $price = get_post_meta($post->ID, '_oep_exam_price', true);
        $duration = get_post_meta($post->ID, '_oep_exam_duration', true);
        $pass_score = get_post_meta($post->ID, '_oep_exam_pass_score', true);
        $max_attempts = get_post_meta($post->ID, '_oep_exam_max_attempts', true);
        $show_results = get_post_meta($post->ID, '_oep_exam_show_results', true);
        $shuffle_questions = get_post_meta($post->ID, '_oep_exam_shuffle_questions', true);
        
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="oep_exam_price"><?php _e('قیمت آزمون (تومان)', 'online-exam-payment'); ?></label>
                </th>
                <td>
                    <input type="number" id="oep_exam_price" name="oep_exam_price" value="<?php echo esc_attr($price); ?>" min="0" step="1000" />
                    <p class="description"><?php _e('قیمت آزمون به تومان. برای آزمون رایگان، عدد 0 وارد کنید.', 'online-exam-payment'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oep_exam_duration"><?php _e('مدت زمان آزمون (دقیقه)', 'online-exam-payment'); ?></label>
                </th>
                <td>
                    <input type="number" id="oep_exam_duration" name="oep_exam_duration" value="<?php echo esc_attr($duration); ?>" min="0" />
                    <p class="description"><?php _e('مدت زمان آزمون به دقیقه. برای آزمون بدون محدودیت زمان، عدد 0 وارد کنید.', 'online-exam-payment'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oep_exam_pass_score"><?php _e('حدنصاب قبولی (درصد)', 'online-exam-payment'); ?></label>
                </th>
                <td>
                    <input type="number" id="oep_exam_pass_score" name="oep_exam_pass_score" value="<?php echo esc_attr($pass_score ?: 60); ?>" min="0" max="100" />
                    <p class="description"><?php _e('حدنصاب قبولی به درصد (پیش‌فرض: 60)', 'online-exam-payment'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oep_exam_max_attempts"><?php _e('حداکثر تعداد تلاش', 'online-exam-payment'); ?></label>
                </th>
                <td>
                    <input type="number" id="oep_exam_max_attempts" name="oep_exam_max_attempts" value="<?php echo esc_attr($max_attempts ?: 1); ?>" min="1" />
                    <p class="description"><?php _e('حداکثر تعداد دفعاتی که کاربر می‌تواند در آزمون شرکت کند', 'online-exam-payment'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('نمایش نتایج', 'online-exam-payment'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="oep_exam_show_results" value="1" <?php checked($show_results, 1); ?> />
                        <?php _e('پس از پایان آزمون، پاسخ‌های صحیح به کاربر نمایش داده شود', 'online-exam-payment'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('ترتیب سوالات', 'online-exam-payment'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="oep_exam_shuffle_questions" value="1" <?php checked($shuffle_questions, 1); ?> />
                        <?php _e('سوالات به صورت تصادفی مرتب شوند', 'online-exam-payment'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }
    
    public function question_options_meta_box($post) {
        wp_nonce_field('oep_question_meta_nonce', 'oep_question_meta_nonce');
        
        $option_a = get_post_meta($post->ID, '_oep_question_option_a', true);
        $option_b = get_post_meta($post->ID, '_oep_question_option_b', true);
        $option_c = get_post_meta($post->ID, '_oep_question_option_c', true);
        $option_d = get_post_meta($post->ID, '_oep_question_option_d', true);
        $correct_answer = get_post_meta($post->ID, '_oep_question_correct_answer', true);
        $explanation = get_post_meta($post->ID, '_oep_question_explanation', true);
        
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="oep_question_option_a"><?php _e('گزینه الف', 'online-exam-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="oep_question_option_a" name="oep_question_option_a" value="<?php echo esc_attr($option_a); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oep_question_option_b"><?php _e('گزینه ب', 'online-exam-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="oep_question_option_b" name="oep_question_option_b" value="<?php echo esc_attr($option_b); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oep_question_option_c"><?php _e('گزینه ج', 'online-exam-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="oep_question_option_c" name="oep_question_option_c" value="<?php echo esc_attr($option_c); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oep_question_option_d"><?php _e('گزینه د', 'online-exam-payment'); ?></label>
                </th>
                <td>
                    <input type="text" id="oep_question_option_d" name="oep_question_option_d" value="<?php echo esc_attr($option_d); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oep_question_correct_answer"><?php _e('پاسخ صحیح', 'online-exam-payment'); ?></label>
                </th>
                <td>
                    <select id="oep_question_correct_answer" name="oep_question_correct_answer">
                        <option value=""><?php _e('انتخاب کنید', 'online-exam-payment'); ?></option>
                        <option value="a" <?php selected($correct_answer, 'a'); ?>><?php _e('الف', 'online-exam-payment'); ?></option>
                        <option value="b" <?php selected($correct_answer, 'b'); ?>><?php _e('ب', 'online-exam-payment'); ?></option>
                        <option value="c" <?php selected($correct_answer, 'c'); ?>><?php _e('ج', 'online-exam-payment'); ?></option>
                        <option value="d" <?php selected($correct_answer, 'd'); ?>><?php _e('د', 'online-exam-payment'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="oep_question_explanation"><?php _e('توضیح (اختیاری)', 'online-exam-payment'); ?></label>
                </th>
                <td>
                    <textarea id="oep_question_explanation" name="oep_question_explanation" rows="3" class="large-text"><?php echo esc_textarea($explanation); ?></textarea>
                    <p class="description"><?php _e('توضیح اضافی که پس از پایان آزمون به کاربر نمایش داده می‌شود', 'online-exam-payment'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    public function question_exam_meta_box($post) {
        $exam_id = get_post_meta($post->ID, '_oep_question_exam_id', true);
        $exams = get_posts(array(
            'post_type' => 'oep_exam',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));
        
        ?>
        <label for="oep_question_exam_id"><?php _e('انتخاب آزمون:', 'online-exam-payment'); ?></label>
        <select id="oep_question_exam_id" name="oep_question_exam_id" style="width: 100%;">
            <option value=""><?php _e('انتخاب کنید', 'online-exam-payment'); ?></option>
            <?php foreach ($exams as $exam): ?>
                <option value="<?php echo $exam->ID; ?>" <?php selected($exam_id, $exam->ID); ?>>
                    <?php echo esc_html($exam->post_title); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }
    
    public function save_exam_meta($post_id) {
        if (!isset($_POST['oep_exam_meta_nonce']) || !wp_verify_nonce($_POST['oep_exam_meta_nonce'], 'oep_exam_meta_nonce')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (get_post_type($post_id) !== 'oep_exam') {
            return;
        }
        
        $fields = array(
            'oep_exam_price',
            'oep_exam_duration',
            'oep_exam_pass_score',
            'oep_exam_max_attempts'
        );
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }
        
        // Handle checkboxes
        update_post_meta($post_id, '_oep_exam_show_results', isset($_POST['oep_exam_show_results']) ? 1 : 0);
        update_post_meta($post_id, '_oep_exam_shuffle_questions', isset($_POST['oep_exam_shuffle_questions']) ? 1 : 0);
    }
    
    public function save_question_meta($post_id) {
        if (!isset($_POST['oep_question_meta_nonce']) || !wp_verify_nonce($_POST['oep_question_meta_nonce'], 'oep_question_meta_nonce')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (get_post_type($post_id) !== 'oep_question') {
            return;
        }
        
        $fields = array(
            'oep_question_option_a',
            'oep_question_option_b',
            'oep_question_option_c',
            'oep_question_option_d',
            'oep_question_correct_answer',
            'oep_question_explanation',
            'oep_question_exam_id'
        );
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }
    }
}