<?php
/**
 * Question Importer for Online Exam Payment Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEP_Question_Importer {
    
    public function __construct() {
        // Constructor
    }
    
    public function import_from_file($file, $exam_id) {
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return array(
                'success' => false,
                'message' => __('خطا در آپلود فایل', 'online-exam-payment')
            );
        }
        
        $exam_id = intval($exam_id);
        if (!$exam_id) {
            return array(
                'success' => false,
                'message' => __('شناسه آزمون معتبر نیست', 'online-exam-payment')
            );
        }
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = array('txt', 'doc', 'docx');
        
        if (!in_array($file_extension, $allowed_extensions)) {
            return array(
                'success' => false,
                'message' => __('فرمت فایل پشتیبانی نمی‌شود. فقط فایل‌های txt، doc و docx مجاز هستند.', 'online-exam-payment')
            );
        }
        
        // Move uploaded file
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['path'] . '/' . uniqid() . '.' . $file_extension;
        
        if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
            return array(
                'success' => false,
                'message' => __('خطا در ذخیره فایل', 'online-exam-payment')
            );
        }
        
        try {
            $content = $this->extract_file_content($temp_file, $file_extension);
            unlink($temp_file); // Clean up temp file
            
            if (empty($content)) {
                return array(
                    'success' => false,
                    'message' => __('محتوای فایل خالی است', 'online-exam-payment')
                );
            }
            
            $questions = $this->parse_questions($content);
            
            if (empty($questions)) {
                return array(
                    'success' => false,
                    'message' => __('هیچ سوال معتبری در فایل یافت نشد', 'online-exam-payment')
                );
            }
            
            $imported_count = $this->save_questions($questions, $exam_id);
            
            return array(
                'success' => true,
                'count' => $imported_count,
                'message' => sprintf(__('%d سوال با موفقیت وارد شد', 'online-exam-payment'), $imported_count)
            );
            
        } catch (Exception $e) {
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            
            return array(
                'success' => false,
                'message' => __('خطا در پردازش فایل: ', 'online-exam-payment') . $e->getMessage()
            );
        }
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
                throw new Exception(__('فرمت فایل پشتیبانی نمی‌شود', 'online-exam-payment'));
        }
    }
    
    private function extract_doc_content($file_path) {
        // For .doc files, we'll try to read as text (limited support)
        // In a production environment, you might want to use a library like PHPWord
        $content = file_get_contents($file_path);
        
        // Try to extract readable text from .doc file
        // This is a basic approach and may not work for all .doc files
        $content = preg_replace('/[^\x20-\x7E\x0A\x0D\x09\xD8-\xDB\xDC-\xDF\xE0-\xEF\xF0-\xF7\xF8-\xFF]/', '', $content);
        $content = str_replace(array("\r\n", "\r"), "\n", $content);
        
        return $content;
    }
    
    private function extract_docx_content($file_path) {
        // Extract content from .docx file
        $zip = new ZipArchive();
        
        if ($zip->open($file_path) !== TRUE) {
            throw new Exception(__('نمی‌توان فایل Word را باز کرد', 'online-exam-payment'));
        }
        
        $xml_content = $zip->getFromName('word/document.xml');
        $zip->close();
        
        if ($xml_content === false) {
            throw new Exception(__('نمی‌توان محتوای فایل Word را خواند', 'online-exam-payment'));
        }
        
        // Parse XML and extract text
        $xml = simplexml_load_string($xml_content);
        if ($xml === false) {
            throw new Exception(__('خطا در پردازش محتوای فایل Word', 'online-exam-payment'));
        }
        
        // Register namespaces
        $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        
        // Extract text from paragraphs
        $paragraphs = $xml->xpath('//w:p');
        $content = '';
        
        foreach ($paragraphs as $paragraph) {
            $paragraph->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $texts = $paragraph->xpath('.//w:t');
            
            $paragraph_text = '';
            foreach ($texts as $text) {
                $paragraph_text .= (string) $text;
            }
            
            if (!empty(trim($paragraph_text))) {
                $content .= trim($paragraph_text) . "\n";
            }
        }
        
        return $content;
    }
    
    private function parse_questions($content) {
        $questions = array();
        $lines = explode("\n", $content);
        $current_question = null;
        $current_options = array();
        $correct_answer = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line)) {
                continue;
            }
            
            // Check if this is a question line (doesn't start with option markers)
            if (!preg_match('/^[*]?[الف|آ|ا|a|A|ب|b|B|ج|c|C|د|d|D]\s*[\)\.]/', $line)) {
                // Save previous question if exists
                if ($current_question && count($current_options) >= 4) {
                    $questions[] = array(
                        'question' => $current_question,
                        'options' => $current_options,
                        'correct_answer' => $correct_answer
                    );
                }
                
                // Start new question
                $current_question = $line;
                $current_options = array();
                $correct_answer = '';
                continue;
            }
            
            // Parse option lines
            $is_correct = false;
            if (strpos($line, '*') === 0) {
                $is_correct = true;
                $line = substr($line, 1);
            }
            
            // Extract option letter and text
            if (preg_match('/^([الف|آ|ا|a|A|ب|b|B|ج|c|C|د|d|D])\s*[\)\.](.+)$/', $line, $matches)) {
                $option_letter = $this->normalize_option_letter($matches[1]);
                $option_text = trim($matches[2]);
                
                $current_options[$option_letter] = $option_text;
                
                if ($is_correct) {
                    $correct_answer = $option_letter;
                }
            }
        }
        
        // Don't forget the last question
        if ($current_question && count($current_options) >= 4) {
            $questions[] = array(
                'question' => $current_question,
                'options' => $current_options,
                'correct_answer' => $correct_answer
            );
        }
        
        return $questions;
    }
    
    private function normalize_option_letter($letter) {
        $letter = strtolower($letter);
        
        $mapping = array(
            'الف' => 'a',
            'آ' => 'a',
            'ا' => 'a',
            'a' => 'a',
            'ب' => 'b',
            'b' => 'b',
            'ج' => 'c',
            'c' => 'c',
            'د' => 'd',
            'd' => 'd'
        );
        
        return isset($mapping[$letter]) ? $mapping[$letter] : $letter;
    }
    
    private function save_questions($questions, $exam_id) {
        $imported_count = 0;
        
        foreach ($questions as $question_data) {
            if (empty($question_data['question']) || empty($question_data['correct_answer'])) {
                continue;
            }
            
            // Create question post
            $post_data = array(
                'post_title' => wp_trim_words($question_data['question'], 10),
                'post_content' => $question_data['question'],
                'post_type' => 'oep_question',
                'post_status' => 'publish'
            );
            
            $question_id = wp_insert_post($post_data);
            
            if ($question_id && !is_wp_error($question_id)) {
                // Save question meta
                update_post_meta($question_id, '_oep_question_exam_id', $exam_id);
                update_post_meta($question_id, '_oep_question_option_a', $question_data['options']['a'] ?? '');
                update_post_meta($question_id, '_oep_question_option_b', $question_data['options']['b'] ?? '');
                update_post_meta($question_id, '_oep_question_option_c', $question_data['options']['c'] ?? '');
                update_post_meta($question_id, '_oep_question_option_d', $question_data['options']['d'] ?? '');
                update_post_meta($question_id, '_oep_question_correct_answer', $question_data['correct_answer']);
                
                $imported_count++;
            }
        }
        
        return $imported_count;
    }
    
    /**
     * Get sample format for question import
     */
    public function get_sample_format() {
        return array(
            'format' => 'text',
            'sample' => "سوال نمونه: کدام گزینه صحیح است؟\nالف) گزینه اول\nب) گزینه دوم\n*ج) گزینه سوم (پاسخ صحیح)\nد) گزینه چهارم\n\nسوال دوم: این سوال دوم است؟\nا) پاسخ اول\n*ب) پاسخ صحیح\nج) پاسخ سوم\nد) پاسخ چهارم",
            'instructions' => array(
                __('هر سوال در یک خط جداگانه نوشته شود', 'online-exam-payment'),
                __('گزینه‌ها با حروف الف، ب، ج، د یا a، b، c، d مشخص شوند', 'online-exam-payment'),
                __('پاسخ صحیح با علامت * در ابتدای خط مشخص شود', 'online-exam-payment'),
                __('بین سوالات یک خط خالی قرار دهید', 'online-exam-payment'),
                __('فرمت‌های پشتیبانی شده: .txt، .doc، .docx', 'online-exam-payment')
            )
        );
    }
}