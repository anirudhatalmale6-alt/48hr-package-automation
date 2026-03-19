<?php
/**
 * Plugin Name: 48HoursReady Package Automation
 * Description: AI-powered intake form that generates Executive Summaries and Business Plans
 * Version: 1.0.0
 * Author: 48HoursReady
 */

if (!defined('ABSPATH')) exit;

class HR48_Package_Automation {

    private static $instance = null;
    private $table_name;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'hr48_submissions';

        add_action('init', [$this, 'register_post_type']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_hr48_submit_intake', [$this, 'handle_submission']);
        add_action('wp_ajax_nopriv_hr48_submit_intake', [$this, 'handle_submission']);
        add_action('wp_ajax_hr48_generate_docs', [$this, 'generate_documents']);
        add_action('wp_ajax_hr48_download_pdf', [$this, 'download_pdf']);
        add_action('wp_ajax_nopriv_hr48_download_pdf', [$this, 'download_pdf']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_shortcode('hr48_intake_form', [$this, 'render_intake_form']);
        add_shortcode('hr48_results', [$this, 'render_results_page']);

        register_activation_hook(__FILE__, [$this, 'create_table']);
    }

    public function create_table() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL UNIQUE,
            business_name VARCHAR(255) NOT NULL,
            owner_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50),
            industry VARCHAR(255),
            business_stage VARCHAR(50),
            business_description TEXT,
            target_market TEXT,
            revenue_model TEXT,
            funding_needed VARCHAR(100),
            funding_purpose TEXT,
            competitive_advantage TEXT,
            num_employees VARCHAR(50),
            location VARCHAR(255),
            website VARCHAR(255),
            package_type VARCHAR(50) DEFAULT 'starter',
            exec_summary TEXT,
            business_plan TEXT,
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            generated_at DATETIME NULL
        ) $charset;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function register_post_type() {
        // Not needed for MVP, but could register CPT later
    }

    public function register_rest_routes() {
        register_rest_route('hr48-auto/v1', '/settings', [
            'methods' => 'GET',
            'callback' => function() {
                return [
                    'openai_key_set' => !empty(get_option('hr48_openai_api_key')),
                    'submissions_count' => $this->get_submission_count(),
                ];
            },
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);

        register_rest_route('hr48-auto/v1', '/settings', [
            'methods' => 'POST',
            'callback' => function($request) {
                $params = $request->get_json_params();
                if (isset($params['openai_api_key'])) {
                    update_option('hr48_openai_api_key', sanitize_text_field($params['openai_api_key']));
                }
                return ['success' => true];
            },
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
    }

    private function get_submission_count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }

    public function enqueue_assets() {
        if (!is_page()) return;
        global $post;
        if (!$post) return;

        if (has_shortcode($post->post_content, 'hr48_intake_form') ||
            has_shortcode($post->post_content, 'hr48_results')) {
            wp_enqueue_style('hr48-auto-css', plugin_dir_url(__FILE__) . 'assets/style.css', [], '1.0.0');
            wp_enqueue_script('hr48-auto-js', plugin_dir_url(__FILE__) . 'assets/script.js', ['jquery'], '1.0.0', true);
            wp_localize_script('hr48-auto-js', 'hr48Auto', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('hr48_intake_nonce'),
            ]);
        }
    }

    public function render_intake_form($atts) {
        $atts = shortcode_atts(['package' => 'starter'], $atts);
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/intake-form.php';
        return ob_get_clean();
    }

    public function render_results_page($atts) {
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/results-page.php';
        return ob_get_clean();
    }

    public function handle_submission() {
        check_ajax_referer('hr48_intake_nonce', 'nonce');

        global $wpdb;

        $required = ['business_name', 'owner_name', 'email', 'business_description'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error(['message' => 'Please fill in all required fields.']);
            }
        }

        $token = bin2hex(random_bytes(32));

        $data = [
            'token'                => $token,
            'business_name'        => sanitize_text_field($_POST['business_name']),
            'owner_name'           => sanitize_text_field($_POST['owner_name']),
            'email'                => sanitize_email($_POST['email']),
            'phone'                => sanitize_text_field($_POST['phone'] ?? ''),
            'industry'             => sanitize_text_field($_POST['industry'] ?? ''),
            'business_stage'       => sanitize_text_field($_POST['business_stage'] ?? ''),
            'business_description' => sanitize_textarea_field($_POST['business_description']),
            'target_market'        => sanitize_textarea_field($_POST['target_market'] ?? ''),
            'revenue_model'        => sanitize_textarea_field($_POST['revenue_model'] ?? ''),
            'funding_needed'       => sanitize_text_field($_POST['funding_needed'] ?? ''),
            'funding_purpose'      => sanitize_textarea_field($_POST['funding_purpose'] ?? ''),
            'competitive_advantage'=> sanitize_textarea_field($_POST['competitive_advantage'] ?? ''),
            'num_employees'        => sanitize_text_field($_POST['num_employees'] ?? ''),
            'location'             => sanitize_text_field($_POST['location'] ?? ''),
            'website'              => esc_url_raw($_POST['website'] ?? ''),
            'package_type'         => sanitize_text_field($_POST['package_type'] ?? 'starter'),
            'status'               => 'pending',
        ];

        $inserted = $wpdb->insert($this->table_name, $data);

        if ($inserted === false) {
            wp_send_json_error(['message' => 'Database error. Please try again.']);
        }

        // Try to generate immediately if API key is set
        $api_key = get_option('hr48_openai_api_key');
        if (!empty($api_key)) {
            $this->run_generation($wpdb->insert_id, $api_key);
        }

        $results_page = get_page_by_path('package-results');
        $results_url = $results_page ? get_permalink($results_page) : home_url('/package-results/');

        wp_send_json_success([
            'message' => 'Submission received! Your documents are being generated.',
            'token' => $token,
            'redirect' => $results_url . '?token=' . $token,
        ]);
    }

    private function run_generation($submission_id, $api_key) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d", $submission_id
        ));

        if (!$row) return false;

        // Build context for AI
        $context = $this->build_ai_context($row);

        // Generate Executive Summary
        $exec_summary = $this->call_openai($api_key, $this->get_exec_summary_prompt($context));
        if ($exec_summary) {
            $wpdb->update($this->table_name, ['exec_summary' => $exec_summary], ['id' => $submission_id]);
        }

        // Generate Business Plan
        $business_plan = $this->call_openai($api_key, $this->get_business_plan_prompt($context));
        if ($business_plan) {
            $wpdb->update($this->table_name, [
                'business_plan' => $business_plan,
                'status' => 'generated',
                'generated_at' => current_time('mysql'),
            ], ['id' => $submission_id]);
        }

        // Send email notification
        $this->send_notification_email($row, $exec_summary, $business_plan);

        return true;
    }

    private function build_ai_context($row) {
        $fields = [
            'Business Name' => $row->business_name,
            'Owner' => $row->owner_name,
            'Industry' => $row->industry,
            'Business Stage' => $row->business_stage,
            'Description' => $row->business_description,
            'Target Market' => $row->target_market,
            'Revenue Model' => $row->revenue_model,
            'Funding Needed' => $row->funding_needed,
            'Funding Purpose' => $row->funding_purpose,
            'Competitive Advantage' => $row->competitive_advantage,
            'Employees' => $row->num_employees,
            'Location' => $row->location,
            'Website' => $row->website,
        ];

        $context = "";
        foreach ($fields as $label => $value) {
            if (!empty($value)) {
                $context .= "{$label}: {$value}\n";
            }
        }
        return $context;
    }

    private function get_exec_summary_prompt($context) {
        return [
            [
                'role' => 'system',
                'content' => 'You are an expert business consultant working for 48HoursReady, a company that helps entrepreneurs create bank-ready business documentation. Generate a professional, compelling 1-page Executive Summary based on the provided business information. Use clear, concise language suitable for investors and banks. Format with sections: Company Overview, Problem & Solution, Market Opportunity, Revenue Model, Competitive Advantage, Financial Highlights, and Call to Action. Use Markdown formatting.'
            ],
            [
                'role' => 'user',
                'content' => "Generate a professional Executive Summary for the following business:\n\n{$context}"
            ]
        ];
    }

    private function get_business_plan_prompt($context) {
        return [
            [
                'role' => 'system',
                'content' => 'You are an expert business consultant working for 48HoursReady, a company that helps entrepreneurs create bank-ready business documentation. Generate a comprehensive Business Plan based on the provided business information. Include these sections: 1) Executive Summary, 2) Company Description, 3) Market Analysis, 4) Organization & Management, 5) Products/Services, 6) Marketing & Sales Strategy, 7) Financial Projections (3-year overview with realistic estimates), 8) Funding Requirements, 9) Implementation Timeline. Make it professional, detailed, and suitable for bank presentations. Use Markdown formatting with clear headings.'
            ],
            [
                'role' => 'user',
                'content' => "Generate a comprehensive Business Plan for the following business:\n\n{$context}"
            ]
        ];
    }

    private function call_openai($api_key, $messages) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'max_tokens' => 4000,
                'temperature' => 0.7,
            ]),
        ]);

        if (is_wp_error($response)) {
            error_log('HR48 OpenAI Error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['choices'][0]['message']['content'] ?? false;
    }

    private function send_notification_email($row, $exec_summary, $business_plan) {
        $admin_email = get_option('admin_email');
        $subject = "[48HoursReady] New Package Generated: {$row->business_name}";
        $body = "A new business package has been generated.\n\n";
        $body .= "Business: {$row->business_name}\n";
        $body .= "Owner: {$row->owner_name}\n";
        $body .= "Email: {$row->email}\n";
        $body .= "Package: {$row->package_type}\n\n";
        $body .= "The Executive Summary and Business Plan have been generated and are available for download.\n";

        wp_mail($admin_email, $subject, $body);

        // Also email the client
        $client_subject = "Your Business Package is Ready! - 48HoursReady";
        $client_body = "Hi {$row->owner_name},\n\n";
        $client_body .= "Great news! Your Executive Summary and Business Plan for \"{$row->business_name}\" have been generated.\n\n";
        $client_body .= "You can view and download your documents at any time using your unique link.\n\n";
        $client_body .= "Thank you for choosing 48HoursReady!\n";
        $client_body .= "— The 48HoursReady Team";

        wp_mail($row->email, $client_subject, $client_body);
    }

    public function generate_documents() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $id = intval($_POST['submission_id'] ?? 0);
        $api_key = get_option('hr48_openai_api_key');

        if (empty($api_key)) {
            wp_send_json_error(['message' => 'OpenAI API key not set. Go to Settings > 48HR Automation.']);
        }

        $result = $this->run_generation($id, $api_key);
        if ($result) {
            wp_send_json_success(['message' => 'Documents generated successfully!']);
        } else {
            wp_send_json_error(['message' => 'Generation failed. Check error log.']);
        }
    }

    public function download_pdf() {
        $token = sanitize_text_field($_GET['token'] ?? '');
        $type = sanitize_text_field($_GET['type'] ?? 'exec_summary');

        if (empty($token)) {
            wp_die('Invalid request.');
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE token = %s", $token
        ));

        if (!$row) {
            wp_die('Submission not found.');
        }

        $content = ($type === 'business_plan') ? $row->business_plan : $row->exec_summary;
        $title = ($type === 'business_plan')
            ? "Business Plan - {$row->business_name}"
            : "Executive Summary - {$row->business_name}";

        if (empty($content)) {
            wp_die('Documents are still being generated. Please check back shortly.');
        }

        // Generate HTML-to-PDF using inline HTML
        $this->output_pdf_html($title, $content, $row);
    }

    private function output_pdf_html($title, $markdown_content, $row) {
        // Convert markdown to HTML
        $html_content = $this->markdown_to_html($markdown_content);

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        $html .= '<title>' . esc_html($title) . '</title>';
        $html .= '<style>
            @media print {
                body { margin: 0; padding: 20px 40px; }
                .no-print { display: none; }
            }
            body {
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 800px;
                margin: 0 auto;
                padding: 40px;
                background: #fff;
            }
            .header {
                text-align: center;
                border-bottom: 3px solid #091263;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }
            .header h1 {
                color: #091263;
                font-size: 28px;
                margin: 0;
            }
            .header .brand {
                color: #009D45;
                font-size: 14px;
                margin-top: 5px;
            }
            .header .date {
                color: #666;
                font-size: 12px;
            }
            h2 { color: #091263; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
            h3 { color: #333; }
            .content { margin-top: 20px; }
            table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
            th { background: #091263; color: #fff; }
            .print-btn {
                position: fixed; top: 20px; right: 20px;
                background: #091263; color: #fff; border: none;
                padding: 12px 24px; border-radius: 6px;
                cursor: pointer; font-size: 16px;
                z-index: 1000;
            }
            .print-btn:hover { background: #009D45; }
            .footer {
                margin-top: 40px;
                text-align: center;
                border-top: 2px solid #091263;
                padding-top: 15px;
                color: #666;
                font-size: 12px;
            }
        </style></head><body>';
        $html .= '<button class="print-btn no-print" onclick="window.print()">Save as PDF / Print</button>';
        $html .= '<div class="header">';
        $html .= '<h1>' . esc_html($title) . '</h1>';
        $html .= '<div class="brand">Powered by 48HoursReady.com</div>';
        $html .= '<div class="date">Generated: ' . date('F j, Y') . '</div>';
        $html .= '</div>';
        $html .= '<div class="content">' . $html_content . '</div>';
        $html .= '<div class="footer">';
        $html .= '<p>Prepared for ' . esc_html($row->owner_name) . ' | ' . esc_html($row->business_name) . '</p>';
        $html .= '<p>Powered by <strong>48HoursReady.com</strong> — Learn. Structure. Earn.</p>';
        $html .= '</div>';
        $html .= '</body></html>';

        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function markdown_to_html($text) {
        // Basic markdown to HTML conversion
        $text = esc_html($text);

        // Headers
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);

        // Bold and italic
        $text = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        // Lists
        $text = preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $text);

        // Numbered lists
        $text = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $text);

        // Paragraphs
        $text = preg_replace('/\n\n/', '</p><p>', $text);
        $text = '<p>' . $text . '</p>';

        // Clean up
        $text = str_replace('<p></p>', '', $text);
        $text = str_replace('<p><h', '<h', $text);
        $text = str_replace('</h1></p>', '</h1>', $text);
        $text = str_replace('</h2></p>', '</h2>', $text);
        $text = str_replace('</h3></p>', '</h3>', $text);
        $text = preg_replace('/<p>\s*<ul>/', '<ul>', $text);
        $text = preg_replace('/<\/ul>\s*<\/p>/', '</ul>', $text);

        return $text;
    }

    public function add_admin_menu() {
        add_options_page(
            '48HR Package Automation',
            '48HR Automation',
            'manage_options',
            'hr48-automation',
            [$this, 'render_admin_page']
        );

        add_menu_page(
            'Package Submissions',
            'Submissions',
            'manage_options',
            'hr48-submissions',
            [$this, 'render_submissions_page'],
            'dashicons-clipboard',
            30
        );
    }

    public function render_admin_page() {
        $api_key = get_option('hr48_openai_api_key', '');

        if (isset($_POST['hr48_save_settings']) && check_admin_referer('hr48_settings')) {
            update_option('hr48_openai_api_key', sanitize_text_field($_POST['openai_api_key']));
            $api_key = sanitize_text_field($_POST['openai_api_key']);
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        echo '<div class="wrap">';
        echo '<h1>48HoursReady Package Automation Settings</h1>';
        echo '<form method="post">';
        wp_nonce_field('hr48_settings');
        echo '<table class="form-table">';
        echo '<tr><th>OpenAI API Key</th><td>';
        echo '<input type="password" name="openai_api_key" value="' . esc_attr($api_key) . '" class="regular-text" />';
        echo '<p class="description">Enter your OpenAI API key. Uses gpt-4o-mini model (~$0.02 per document).</p>';
        echo '</td></tr>';
        echo '</table>';
        echo '<input type="hidden" name="hr48_save_settings" value="1" />';
        submit_button('Save Settings');
        echo '</form>';
        echo '</div>';
    }

    public function render_submissions_page() {
        global $wpdb;

        // Handle regeneration
        if (isset($_GET['regenerate']) && check_admin_referer('hr48_regen')) {
            $id = intval($_GET['regenerate']);
            $api_key = get_option('hr48_openai_api_key');
            if (!empty($api_key)) {
                $this->run_generation($id, $api_key);
                echo '<div class="updated"><p>Documents regenerated.</p></div>';
            } else {
                echo '<div class="error"><p>Set your OpenAI API key first.</p></div>';
            }
        }

        $submissions = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT 50");

        echo '<div class="wrap">';
        echo '<h1>Package Submissions</h1>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>ID</th><th>Business</th><th>Owner</th><th>Email</th><th>Package</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>';
        echo '<tbody>';

        if (empty($submissions)) {
            echo '<tr><td colspan="8">No submissions yet.</td></tr>';
        }

        foreach ($submissions as $sub) {
            $status_badge = $sub->status === 'generated'
                ? '<span style="color:green;font-weight:bold;">Generated</span>'
                : '<span style="color:orange;">Pending</span>';

            $regen_url = wp_nonce_url(admin_url('admin.php?page=hr48-submissions&regenerate=' . $sub->id), 'hr48_regen');

            echo '<tr>';
            echo '<td>' . $sub->id . '</td>';
            echo '<td><strong>' . esc_html($sub->business_name) . '</strong></td>';
            echo '<td>' . esc_html($sub->owner_name) . '</td>';
            echo '<td>' . esc_html($sub->email) . '</td>';
            echo '<td>' . esc_html($sub->package_type) . '</td>';
            echo '<td>' . $status_badge . '</td>';
            echo '<td>' . date('M j, Y', strtotime($sub->created_at)) . '</td>';
            echo '<td>';
            if ($sub->status === 'generated') {
                $dl_base = admin_url('admin-ajax.php?action=hr48_download_pdf&token=' . $sub->token);
                echo '<a href="' . $dl_base . '&type=exec_summary" target="_blank">Exec Summary</a> | ';
                echo '<a href="' . $dl_base . '&type=business_plan" target="_blank">Business Plan</a> | ';
            }
            echo '<a href="' . $regen_url . '">Regenerate</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function get_submission_by_token($token) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE token = %s", $token
        ));
    }
}

HR48_Package_Automation::get_instance();
