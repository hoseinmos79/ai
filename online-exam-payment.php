<?php
/**
 * Plugin Name: آزمون آنلاین با پرداخت
 * Plugin URI: https://example.com
 * Description: افزونه آزمون آنلاین با امکان پرداخت از طریق زرین‌پال و کارت به کارت
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: online-exam-payment
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('OEP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OEP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('OEP_VERSION', '1.0.0');

// Main plugin class
class OnlineExamPayment {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Include required files
        $this->include_files();
        
        // Initialize components
        $this->init_components();
    }
    
    private function include_files() {
        require_once OEP_PLUGIN_PATH . 'includes/class-custom-post-types.php';
        require_once OEP_PLUGIN_PATH . 'includes/class-admin-panel.php';
        require_once OEP_PLUGIN_PATH . 'includes/class-payment-handler.php';
        require_once OEP_PLUGIN_PATH . 'includes/class-exam-engine.php';
        require_once OEP_PLUGIN_PATH . 'includes/class-user-panel.php';
        require_once OEP_PLUGIN_PATH . 'includes/class-shortcodes.php';
        require_once OEP_PLUGIN_PATH . 'includes/class-question-importer.php';
    }
    
    private function init_components() {
        new OEP_Custom_Post_Types();
        new OEP_Admin_Panel();
        new OEP_Payment_Handler();
        new OEP_Exam_Engine();
        new OEP_User_Panel();
        new OEP_Shortcodes();
        new OEP_Question_Importer();
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('online-exam-payment', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function activate() {
        // Create database tables if needed
        $this->create_tables();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create exam results table
        $table_name = $wpdb->prefix . 'oep_exam_results';
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            exam_id bigint(20) NOT NULL,
            score float NOT NULL,
            total_questions int(11) NOT NULL,
            correct_answers int(11) NOT NULL,
            time_spent int(11) NOT NULL,
            passed tinyint(1) NOT NULL DEFAULT 0,
            answers longtext,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY exam_id (exam_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Create transactions table
        $table_name = $wpdb->prefix . 'oep_transactions';
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            exam_id bigint(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            payment_method varchar(50) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            transaction_id varchar(255),
            zarinpal_authority varchar(255),
            zarinpal_ref_id varchar(255),
            card_transfer_info longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY exam_id (exam_id),
            KEY status (status)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
}

// Initialize the plugin
new OnlineExamPayment();