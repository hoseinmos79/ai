<?php
/**
 * Question Importer for Online Exam Payment Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEP_Question_Importer {
    
    public function __construct() {
        // This class is instantiated when needed
    }
    
    public function import_from_file($file, $exam_id) {
        // Validate file
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return array(
                'success' => false,
                'message' => __('فایل آپلود نشده است.', 'online-exam-payment')
            );
        }
        
        // Check file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            return array(
                'success' => false,
                'message' => __('حجم فایل نباید بیشتر از 5 مگابایت باشد.', 'online-exam-payment')
            );
        }
        
        // Get file extension
        $file_info = pathinfo($file['name']);
        $extension = strtolower($file_info['extension']);
        
        // Check supported formats
        if (!in_array($extension, array('txt', 'doc', 'docx'))) {
            return array(
                'success' => false,
                'message' => __('فرمت فایل پشتیبانی نمی‌شود. فرمت‌های مجاز: .txt, .doc, .docx', 'online-exam-payment')
            );
        }
        
        // Extract content based on file type
        $content = $this->extract_file_content($file['tmp_name'], $extension);
        
        if ($content === false) {
            return array(
                'success' => false,
                'message' => __('خطا در خواندن محتوای فایل.', 'online-exam-payment')
            );
        }
        
        // Parse questions from content
        $questions = $this->parse_questions($content);
        
        if (empty($questions)) {
            return array(
                'success' => false,
                'message' => __('هیچ سوال معتبری در فایل یافت نشد.', 'online-exam-payment')
            );
        }
        
        // Save questions to database
        $saved_count = $this->save_questions($questions, $exam_id);
        
        return array(
            'success' => true,
            'count' => $saved_count,
            'message' => sprintf(__('%d سوال با موفقیت وارد شد.', 'online-exam-payment'), $saved_count)
        );
    }
    
    private function extract_file_content($file_path, $extension) {
        switch ($extension) {
            case 'txt':
                return file_get_contents($file_path);
                
            case 'doc':
                return $this->extract_doc_content($file_path);
                
            case 'docx':
                return $this->extract_docx_content($file_path);
                
            default:
                return false;
        }
    }
    
    private function extract_doc_content($file_path) {
        // Basic .doc file extraction (limited support)
        // This is a simplified approach and may not work with all .doc files
        $content = file_get_contents($file_path);
        
        if ($content === false) {
            return false;
        }
        
        // Remove binary characters and extract readable text
        $content = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/', '', $content);
        $content = str_replace(array("\r\n", "\r"), "\n", $content);
        
        // Clean up extra whitespace
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        $content = trim($content);
        
        return $content;
    }
    
    private function extract_docx_content($file_path) {
        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            return false;
        }
        
        $zip = new ZipArchive();
        
        if ($zip->open($file_path) !== TRUE) {
            return false;
        }
        
        // Extract document.xml from the docx file
        $xml_content = $zip->getFromName('word/document.xml');
        $zip->close();
        
        if ($xml_content === false) {
            return false;
        }
        
        // Parse XML and extract text
        $xml = simplexml_load_string($xml_content);
        
        if ($xml === false) {
            return false;
        }
        
        // Register namespaces
        $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        
        // Extract text from paragraphs
        $paragraphs = $xml->xpath('//w:p');
        $content = '';
        
        foreach ($paragraphs as $paragraph) {
            $text_nodes = $paragraph->xpath('.//w:t');
            $paragraph_text = '';
            
            foreach ($text_nodes as $text_node) {
                $paragraph_text .= (string)$text_node;
            }
            
            if (!empty(trim($paragraph_text))) {
                $content .= trim($paragraph_text) . "\n";
            }
        }
        
        return trim($content);
    }
    
    private function parse_questions($content) {
        $questions = array();
        
        // Clean up content
        $content = str_replace(array("\r\n", "\r"), "\n", $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // Split content into blocks (questions)
        $blocks = preg_split('/\n\s*\n/', $content);
        
        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) {
                continue;
            }
            
            $question = $this->parse_single_question($block);
            if ($question !== false) {
                $questions[] = $question;
            }
        }
        
        return $questions;
    }
    
    private function parse_single_question($block) {
        $lines = explode("\n", $block);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines); // Remove empty lines
        
        if (count($lines) < 5) { // Question + 4 options minimum
            return false;
        }
        
        $question_title = '';
        $options = array('a' => '', 'b' => '', 'c' => '', 'd' => '');
        $correct_answer = '';
        $explanation = '';
        
        $current_line = 0;
        
        // Extract question title (first line or lines until we find an option)
        while ($current_line < count($lines)) {
            $line = $lines[$current_line];
            
            // Check if this line starts an option
            if (preg_match('/^[الف|ا|a|A|1]\)?\s*[\)\.\-\:]?\s*(.+)$/u', $line) ||
                preg_match('/^[ب|b|B|2]\)?\s*[\)\.\-\:]?\s*(.+)$/u', $line) ||
                preg_match('/^[ج|c|C|3]\)?\s*[\)\.\-\:]?\s*(.+)$/u', $line) ||
                preg_match('/^[د|d|D|4]\)?\s*[\)\.\-\:]?\s*(.+)$/u', $line)) {
                break;
            }
            
            $question_title .= ($question_title ? ' ' : '') . $line;
            $current_line++;
        }
        
        if (empty($question_title)) {
            return false;
        }
        
        // Extract options
        $option_patterns = array(
            'a' => '/^[\*]?[الف|ا|a|A|1]\)?\s*[\)\.\-\:]?\s*(.+)$/u',
            'b' => '/^[\*]?[ب|b|B|2]\)?\s*[\)\.\-\:]?\s*(.+)$/u',
            'c' => '/^[\*]?[ج|c|C|3]\)?\s*[\)\.\-\:]?\s*(.+)$/u',
            'd' => '/^[\*]?[د|d|D|4]\)?\s*[\)\.\-\:]?\s*(.+)$/u'
        );
        
        while ($current_line < count($lines)) {
            $line = $lines[$current_line];
            
            foreach ($option_patterns as $option_key => $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $options[$option_key] = trim($matches[1]);
                    
                    // Check if this option is marked as correct with *
                    if (strpos($line, '*') === 0) {
                        $correct_answer = $option_key;
                    }
                    
                    break;
                }
            }
            
            $current_line++;
        }
        
        // If no correct answer found with *, try to find it in other ways
        if (empty($correct_answer)) {
            // Look for patterns like "پاسخ: الف" or "صحیح: ب"
            $remaining_lines = array_slice($lines, $current_line);
            foreach ($remaining_lines as $line) {
                if (preg_match('/(?:پاسخ|صحیح|جواب)[\s\:]*([الف|ا|ب|ج|د|a|b|c|d|A|B|C|D|1|2|3|4])/u', $line, $matches)) {
                    $correct_answer = $this->normalize_option_letter($matches[1]);
                    break;
                }
            }
        }
        
        // Extract explanation if available
        $explanation_start = $current_line;
        while ($explanation_start < count($lines)) {
            $line = $lines[$explanation_start];
            if (preg_match('/(?:توضیح|پاسخ|تبصره)[\s\:]/u', $line)) {
                $explanation = trim(substr($line, strpos($line, ':') + 1));
                // Add remaining lines to explanation
                for ($i = $explanation_start + 1; $i < count($lines); $i++) {
                    $explanation .= ' ' . trim($lines[$i]);
                }
                break;
            }
            $explanation_start++;
        }
        
        // Validate that we have all required parts
        if (empty($question_title) || 
            empty($options['a']) || empty($options['b']) || 
            empty($options['c']) || empty($options['d']) || 
            empty($correct_answer)) {
            return false;
        }
        
        return array(
            'title' => $question_title,
            'options' => $options,
            'correct_answer' => $correct_answer,
            'explanation' => $explanation
        );
    }
    
    private function normalize_option_letter($letter) {
        $letter = strtolower(trim($letter));
        
        $mapping = array(
            'الف' => 'a', 'ا' => 'a', 'a' => 'a', '1' => 'a',
            'ب' => 'b', 'b' => 'b', '2' => 'b',
            'ج' => 'c', 'c' => 'c', '3' => 'c',
            'د' => 'd', 'd' => 'd', '4' => 'd'
        );
        
        return isset($mapping[$letter]) ? $mapping[$letter] : '';
    }
    
    private function save_questions($questions, $exam_id) {
        $saved_count = 0;
        
        foreach ($questions as $question_data) {
            // Create question post
            $post_data = array(
                'post_title' => $question_data['title'],
                'post_content' => '', // We store the question text in title for simplicity
                'post_status' => 'publish',
                'post_type' => 'oep_question',
                'post_author' => get_current_user_id()
            );
            
            $question_id = wp_insert_post($post_data);
            
            if ($question_id && !is_wp_error($question_id)) {
                // Save question meta
                update_post_meta($question_id, '_oep_question_option_a', $question_data['options']['a']);
                update_post_meta($question_id, '_oep_question_option_b', $question_data['options']['b']);
                update_post_meta($question_id, '_oep_question_option_c', $question_data['options']['c']);
                update_post_meta($question_id, '_oep_question_option_d', $question_data['options']['d']);
                update_post_meta($question_id, '_oep_question_correct_answer', $question_data['correct_answer']);
                update_post_meta($question_id, '_oep_question_explanation', $question_data['explanation']);
                update_post_meta($question_id, '_oep_question_exam_id', $exam_id);
                
                $saved_count++;
            }
        }
        
        return $saved_count;
    }
    
    public static function get_sample_format() {
        ob_start();
        ?>
        <div class="oep-import-instructions">
            <h4><?php _e('فرمت مورد انتظار:', 'online-exam-payment'); ?></h4>
            <p><?php _e('فایل باید شامل سوالات با فرمت زیر باشد:', 'online-exam-payment'); ?></p>
            
            <div class="oep-format-example">
                <h5><?php _e('نمونه فرمت صحیح:', 'online-exam-payment'); ?></h5>
                <pre style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px; direction: ltr; text-align: left;">
کدام یک از موارد زیر یک زبان برنامه‌نویسی است؟

الف) HTML
ب) CSS  
*ج) JavaScript
د) XML

توضیح: JavaScript یک زبان برنامه‌نویسی است در حالی که بقیه زبان‌های نشانه‌گذاری هستند.

---

سوال دوم: متغیرها در JavaScript با کدام کلمه کلیدی تعریف می‌شوند؟

1) function
*2) var
3) return  
4) if

---

کدام عملگر برای مقایسه مقدار و نوع داده استفاده می‌شود؟

a) ==
b) !=
*c) ===
d) !==
                </pre>
            </div>
            
            <div class="oep-format-rules">
                <h5><?php _e('قوانین فرمت:', 'online-exam-payment'); ?></h5>
                <ul>
                    <li><?php _e('هر سوال باید در یک بلوک جداگانه باشد (با خط خالی از سوال بعدی جدا شود)', 'online-exam-payment'); ?></li>
                    <li><?php _e('سوال در خط اول هر بلوک نوشته شود', 'online-exam-payment'); ?></li>
                    <li><?php _e('گزینه‌ها با حروف الف، ب، ج، د یا a، b، c، d یا اعداد 1، 2، 3، 4 شروع شوند', 'online-exam-payment'); ?></li>
                    <li><?php _e('پاسخ صحیح با علامت * در ابتدای خط مشخص شود', 'online-exam-payment'); ?></li>
                    <li><?php _e('توضیح پاسخ (اختیاری) با کلمه "توضیح:" شروع شود', 'online-exam-payment'); ?></li>
                    <li><?php _e('از خط تیره (---) برای جداسازی سوالات استفاده کنید', 'online-exam-payment'); ?></li>
                </ul>
            </div>
            
            <div class="oep-supported-formats">
                <h5><?php _e('فرمت‌های پشتیبانی شده:', 'online-exam-payment'); ?></h5>
                <ul>
                    <li><strong>.txt</strong> - <?php _e('فایل متنی ساده', 'online-exam-payment'); ?></li>
                    <li><strong>.docx</strong> - <?php _e('فایل ورد جدید (توصیه می‌شود)', 'online-exam-payment'); ?></li>
                    <li><strong>.doc</strong> - <?php _e('فایل ورد قدیمی (پشتیبانی محدود)', 'online-exam-payment'); ?></li>
                </ul>
            </div>
            
            <div class="oep-import-tips">
                <h5><?php _e('نکات مهم:', 'online-exam-payment'); ?></h5>
                <ul>
                    <li><?php _e('حداکثر حجم فایل: 5 مگابایت', 'online-exam-payment'); ?></li>
                    <li><?php _e('برای بهترین نتیجه از فرمت .docx استفاده کنید', 'online-exam-payment'); ?></li>
                    <li><?php _e('اطمینان حاصل کنید که تمام سوالات دارای 4 گزینه و یک پاسخ صحیح هستند', 'online-exam-payment'); ?></li>
                    <li><?php _e('قبل از آپلود نهایی، فایل را با چند سوال نمونه تست کنید', 'online-exam-payment'); ?></li>
                </ul>
            </div>
        </div>
        
        <style>
        .oep-import-instructions {
            direction: rtl;
            text-align: right;
            font-family: 'Tahoma', 'Arial', sans-serif;
        }
        
        .oep-format-example pre {
            direction: rtl;
            text-align: right;
            font-family: 'Tahoma', 'Courier New', monospace;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .oep-format-rules ul,
        .oep-supported-formats ul,
        .oep-import-tips ul {
            list-style-type: disc;
            padding-right: 20px;
        }
        
        .oep-format-rules li,
        .oep-supported-formats li,
        .oep-import-tips li {
            margin-bottom: 5px;
            line-height: 1.5;
        }
        
        .oep-import-instructions h4,
        .oep-import-instructions h5 {
            color: #333;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        
        .oep-import-instructions h4 {
            font-size: 16px;
        }
        
        .oep-import-instructions h5 {
            font-size: 14px;
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
}