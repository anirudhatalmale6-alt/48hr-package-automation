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

        // Generate documents immediately — uses AI if API key is set, templates otherwise
        $api_key = get_option('hr48_openai_api_key');
        $this->run_generation($wpdb->insert_id, $api_key);

        $results_page = get_page_by_path('package-results');
        $results_url = $results_page ? get_permalink($results_page) : home_url('/package-results/');

        wp_send_json_success([
            'message' => 'Submission received! Your documents are being generated.',
            'token' => $token,
            'redirect' => $results_url . '?token=' . $token,
        ]);
    }

    private function run_generation($submission_id, $api_key = '') {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d", $submission_id
        ));

        if (!$row) return false;

        $use_ai = !empty($api_key);

        if ($use_ai) {
            // AI-powered generation via OpenAI
            $context = $this->build_ai_context($row);

            $exec_summary = $this->call_openai($api_key, $this->get_exec_summary_prompt($context));
            if ($exec_summary) {
                $wpdb->update($this->table_name, ['exec_summary' => $exec_summary], ['id' => $submission_id]);
            }

            $business_plan = $this->call_openai($api_key, $this->get_business_plan_prompt($context));
            if ($business_plan) {
                $wpdb->update($this->table_name, [
                    'business_plan' => $business_plan,
                    'status' => 'generated',
                    'generated_at' => current_time('mysql'),
                ], ['id' => $submission_id]);
            }
        } else {
            // Template-based generation (no API key required)
            $exec_summary = $this->generate_from_template($row, 'exec_summary');
            $wpdb->update($this->table_name, ['exec_summary' => $exec_summary], ['id' => $submission_id]);

            $business_plan = $this->generate_from_template($row, 'business_plan');
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

    /**
     * Generate professional documents from templates using business intake data.
     *
     * @param object $row  The database row with all submission fields.
     * @param string $type Either 'exec_summary' or 'business_plan'.
     * @return string Markdown-formatted document content.
     */
    private function generate_from_template($row, $type) {
        // Prepare data with sensible defaults for empty fields
        $d = (object) [
            'business_name'        => !empty($row->business_name) ? $row->business_name : 'Our Company',
            'owner_name'           => !empty($row->owner_name) ? $row->owner_name : 'the Founder',
            'email'                => !empty($row->email) ? $row->email : '',
            'phone'                => !empty($row->phone) ? $row->phone : '',
            'industry'             => !empty($row->industry) ? $row->industry : 'General Business',
            'business_stage'       => !empty($row->business_stage) ? $row->business_stage : 'Early Stage',
            'business_description' => !empty($row->business_description) ? $row->business_description : 'A growing enterprise delivering value to its customers.',
            'target_market'        => !empty($row->target_market) ? $row->target_market : 'consumers and businesses seeking quality solutions',
            'revenue_model'        => !empty($row->revenue_model) ? $row->revenue_model : 'direct sales and recurring service revenue',
            'funding_needed'       => !empty($row->funding_needed) ? $row->funding_needed : 'To be determined',
            'funding_purpose'      => !empty($row->funding_purpose) ? $row->funding_purpose : 'operational expansion, marketing, and working capital',
            'competitive_advantage'=> !empty($row->competitive_advantage) ? $row->competitive_advantage : 'a commitment to quality, customer service, and innovation',
            'num_employees'        => !empty($row->num_employees) ? $row->num_employees : '1-5',
            'location'             => !empty($row->location) ? $row->location : 'United States',
            'website'              => !empty($row->website) ? $row->website : '',
        ];

        $date = date('F j, Y');

        // Determine stage label for narrative
        $stage_label = strtolower($d->business_stage);
        $stage_narrative = 'an emerging';
        if (strpos($stage_label, 'growth') !== false || strpos($stage_label, 'established') !== false) {
            $stage_narrative = 'a growing';
        } elseif (strpos($stage_label, 'startup') !== false || strpos($stage_label, 'idea') !== false) {
            $stage_narrative = 'an innovative startup';
        } elseif (strpos($stage_label, 'mature') !== false || strpos($stage_label, 'scale') !== false) {
            $stage_narrative = 'a well-established';
        }

        if ($type === 'exec_summary') {
            return $this->template_exec_summary($d, $date, $stage_narrative);
        }

        return $this->template_business_plan($d, $date, $stage_narrative);
    }

    /**
     * Executive Summary template.
     */
    private function template_exec_summary($d, $date, $stage_narrative) {
        $website_line = !empty($d->website) ? " | Website: {$d->website}" : '';
        $phone_line = !empty($d->phone) ? " | Phone: {$d->phone}" : '';

        $md = <<<MD
# Executive Summary

**{$d->business_name}**
*Prepared on {$date}*
*Contact: {$d->owner_name} — {$d->email}{$phone_line}{$website_line}*

---

## Company Overview

{$d->business_name} is {$stage_narrative} enterprise operating in the **{$d->industry}** sector, headquartered in **{$d->location}**. Under the leadership of {$d->owner_name}, the company has been built on a clear vision: to deliver measurable value to its customers through quality products, reliable service, and continuous innovation.

{$d->business_description}

The company currently operates with a lean, focused team of **{$d->num_employees}** employees, maintaining operational efficiency while positioning for strategic growth.

## Problem & Solution

Customers within the {$d->industry} landscape face persistent challenges — including limited access to reliable solutions, fragmented service quality, and a lack of providers who genuinely understand their needs. Many existing alternatives are either too costly, too generic, or fail to deliver consistent results.

**{$d->business_name}** addresses these pain points directly. By combining deep industry knowledge with a customer-first approach, the company delivers solutions that are practical, effective, and tailored to the real-world needs of its audience. The result is higher satisfaction, better retention, and stronger word-of-mouth referrals.

## Market Opportunity

The target market for {$d->business_name} consists of **{$d->target_market}**. This segment represents a substantial and growing opportunity driven by increasing demand for specialized, high-quality offerings in the {$d->industry} space.

Key market drivers include:

- **Growing consumer expectations** for personalized, high-quality experiences
- **Industry modernization** creating gaps that nimble, customer-focused companies can fill
- **Underserved niches** where incumbents have failed to innovate or deliver adequate service
- **Favorable economic trends** supporting spending in the {$d->industry} sector

{$d->business_name} is well-positioned to capture meaningful market share by focusing on the segments most underserved by larger, less agile competitors.

## Revenue Model

The company generates revenue through **{$d->revenue_model}**.

This model is designed for scalability and predictable cash flow, enabling the business to reinvest in growth while maintaining healthy margins. As the customer base expands, unit economics improve, creating a compounding growth effect over time.

## Competitive Advantage

What sets {$d->business_name} apart is **{$d->competitive_advantage}**.

In an industry where many competitors focus solely on price or volume, {$d->business_name} differentiates by delivering genuine value and building trust-based relationships. This strategic positioning translates into:

- Higher customer lifetime value and retention rates
- Stronger brand reputation and organic referral growth
- A defensible market position that is difficult for competitors to replicate

## Team & Operations

Led by **{$d->owner_name}**, the leadership team brings hands-on expertise and a deep understanding of the {$d->industry} market. The current team of {$d->num_employees} is structured for efficiency, with clear roles and accountability across core business functions.

The operational model emphasizes:

- Lean overhead with scalable processes
- Data-informed decision making
- Continuous improvement and customer feedback loops
- Strategic partnerships to extend capabilities without fixed overhead

## Call to Action

{$d->business_name} is seeking **{$d->funding_needed}** in funding to accelerate its next phase of growth. The capital will be deployed toward **{$d->funding_purpose}**, directly supporting the company's path to increased revenue, broader market reach, and long-term profitability.

This is an opportunity to invest in a focused, founder-led company with proven market demand, a clear revenue model, and a realistic plan for scalable growth. We invite prospective partners and investors to schedule a meeting with {$d->owner_name} to discuss terms, review detailed financial projections, and explore how we can grow together.

---

*This Executive Summary was prepared by 48HoursReady.com — Learn. Structure. Earn.*
MD;

        return $md;
    }

    /**
     * Business Plan template.
     */
    private function template_business_plan($d, $date, $stage_narrative) {
        $website_line = !empty($d->website) ? " | {$d->website}" : '';
        $phone_line = !empty($d->phone) ? " | {$d->phone}" : '';

        // Generate realistic financial projections based on funding amount (extract first number from ranges)
        preg_match('/[\$]?([\d,]+)/', $d->funding_needed, $fm);
        $funding_val = !empty($fm[1]) ? intval(str_replace(',', '', $fm[1])) : 100000;
        $base_revenue = max(50000, $funding_val * 0.8);
        if ($base_revenue < 50000) $base_revenue = 100000;

        $yr1_revenue = number_format($base_revenue);
        $yr2_revenue = number_format($base_revenue * 1.75);
        $yr3_revenue = number_format($base_revenue * 2.8);

        $yr1_cogs = number_format($base_revenue * 0.45);
        $yr2_cogs = number_format($base_revenue * 1.75 * 0.40);
        $yr3_cogs = number_format($base_revenue * 2.8 * 0.37);

        $yr1_gross = number_format($base_revenue * 0.55);
        $yr2_gross = number_format($base_revenue * 1.75 * 0.60);
        $yr3_gross = number_format($base_revenue * 2.8 * 0.63);

        $yr1_opex = number_format($base_revenue * 0.40);
        $yr2_opex = number_format($base_revenue * 1.75 * 0.35);
        $yr3_opex = number_format($base_revenue * 2.8 * 0.30);

        $yr1_net = number_format($base_revenue * 0.15);
        $yr2_net = number_format($base_revenue * 1.75 * 0.25);
        $yr3_net = number_format($base_revenue * 2.8 * 0.33);

        $md = <<<MD
# Business Plan

**{$d->business_name}**
*Prepared on {$date}*
*{$d->owner_name} — {$d->email}{$phone_line}{$website_line}*
*Location: {$d->location}*

---

## 1. Executive Summary

{$d->business_name} is {$stage_narrative} company in the **{$d->industry}** sector, founded and led by {$d->owner_name}. The company is positioned to serve **{$d->target_market}** through a focused strategy of delivering high-quality solutions and building long-term customer relationships.

{$d->business_description}

The company generates revenue through **{$d->revenue_model}** and is seeking **{$d->funding_needed}** to fund the next phase of strategic growth. Funds will be allocated toward **{$d->funding_purpose}**, with the goal of achieving sustainable profitability and establishing a leading position within its target market.

This business plan outlines the company's strategy, market opportunity, operational structure, and financial projections over the next three years.

## 2. Company Description

### Legal & Operational Overview

- **Company Name:** {$d->business_name}
- **Industry:** {$d->industry}
- **Stage:** {$d->business_stage}
- **Headquarters:** {$d->location}
- **Team Size:** {$d->num_employees} employees
- **Founded by:** {$d->owner_name}

### Mission Statement

To deliver exceptional value within the {$d->industry} sector by providing reliable, innovative, and customer-centered solutions that address real market needs and create lasting impact for our clients and community.

### Vision Statement

To become a recognized, trusted leader in the {$d->industry} space — known for quality, integrity, and a relentless commitment to the success of every customer we serve.

### Core Values

1. **Customer Obsession** — Every decision starts with the customer's needs
2. **Quality Without Compromise** — We set and maintain the highest standards
3. **Transparency** — We operate with honesty and open communication
4. **Continuous Improvement** — We invest in learning, innovation, and efficiency
5. **Community Impact** — We measure success by the value we create for others

## 3. Market Analysis

### Industry Overview

The {$d->industry} sector continues to demonstrate strong growth fundamentals, driven by evolving consumer demands, technological advancement, and increasing market sophistication. Companies that can deliver specialized, high-quality offerings are particularly well-positioned to outperform.

### Target Market

{$d->business_name} serves **{$d->target_market}**. This customer segment values quality, reliability, and a provider that understands their specific requirements.

### Market Segmentation

| Segment | Description | Priority |
|---------|-------------|----------|
| **Primary** | Core customers within {$d->target_market} who have immediate, recurring needs | High |
| **Secondary** | Adjacent market segments that benefit from the same solutions | Medium |
| **Tertiary** | Broader market participants who may convert over time through referrals | Growth |

### Competitive Landscape

The market includes both established incumbents and emerging competitors. However, many existing players suffer from:

- Lack of specialization or personalization
- Poor customer service and long response times
- Inflexible pricing models
- Inability to adapt quickly to changing customer needs

{$d->business_name} exploits these weaknesses by offering a more responsive, customer-focused alternative with distinct advantages in quality and relationship management.

### SWOT Analysis

| | **Positive** | **Negative** |
|---|---|---|
| **Internal** | **Strengths:** {$d->competitive_advantage}; lean operations; founder expertise | **Weaknesses:** Limited brand awareness at current stage; small team capacity |
| **External** | **Opportunities:** Growing market demand; underserved niches; partnership potential | **Threats:** Competitive pressure; economic fluctuations; regulatory changes |

## 4. Organization & Management

### Leadership Team

**{$d->owner_name}** — *Founder & CEO*
{$d->owner_name} leads all strategic and operational decisions, bringing deep knowledge of the {$d->industry} sector and a hands-on management approach. The founder's vision, industry relationships, and operational expertise form the backbone of the company's competitive position.

### Organizational Structure

The company operates with a flat, agile structure designed for speed and accountability:

- **Executive Leadership** — Strategic direction, partnerships, and fundraising
- **Operations** — Service delivery, quality assurance, and process optimization
- **Sales & Marketing** — Customer acquisition, brand building, and retention
- **Finance & Administration** — Bookkeeping, compliance, and reporting

### Staffing Plan

| Phase | Timeline | Team Size | Key Hires |
|-------|----------|-----------|-----------|
| **Current** | Now | {$d->num_employees} | Core team in place |
| **Phase 2** | Months 1–6 | +2-3 | Sales lead, Operations support |
| **Phase 3** | Months 7–18 | +3-5 | Marketing specialist, Additional delivery staff, Admin |

## 5. Products & Services

### Core Offering

{$d->business_description}

### Value Proposition

Customers choose {$d->business_name} because we deliver:

- **Specialized expertise** — Deep knowledge of the {$d->industry} sector
- **Personalized service** — Tailored solutions rather than one-size-fits-all
- **Reliable results** — Consistent quality backed by proven processes
- **Responsive support** — Fast, accessible communication and problem resolution
- **Fair value** — Competitive pricing aligned with the quality delivered

### Service Delivery Model

1. **Consultation & Discovery** — Understand the customer's specific needs and goals
2. **Solution Design** — Create a tailored plan that addresses identified requirements
3. **Delivery & Execution** — Implement with attention to quality and timeliness
4. **Follow-Up & Support** — Ensure satisfaction and identify opportunities for continued service

## 6. Marketing & Sales Strategy

### Brand Positioning

{$d->business_name} positions itself as a trusted, quality-focused provider in the {$d->industry} space — the go-to choice for customers who value expertise, reliability, and personal service over the cheapest option.

### Marketing Channels

| Channel | Strategy | Expected Impact |
|---------|----------|-----------------|
| **Digital Marketing** | SEO, content marketing, paid search | Primary lead generation |
| **Social Media** | Platform-specific content, community engagement | Brand awareness, trust building |
| **Referral Program** | Incentivized word-of-mouth from satisfied customers | High-quality leads, low CAC |
| **Networking & Partnerships** | Industry events, strategic alliances | Credibility, B2B opportunities |
| **Email Marketing** | Nurture sequences, newsletters, offers | Retention, upselling |

### Sales Process

1. **Lead Generation** — Attract qualified prospects through marketing and referrals
2. **Qualification** — Assess fit and readiness through discovery conversation
3. **Proposal** — Present a clear, compelling offer tailored to the prospect's needs
4. **Close** — Address objections, finalize terms, and onboard the customer
5. **Retain & Upsell** — Deliver exceptional experience and expand the relationship over time

### Customer Retention Strategy

- Regular follow-ups and satisfaction checks
- Loyalty rewards and exclusive offers for repeat customers
- Proactive communication about new services and improvements
- Quarterly feedback surveys to drive continuous improvement

## 7. Financial Projections

### Three-Year Revenue Forecast

| Metric | Year 1 | Year 2 | Year 3 |
|--------|--------|--------|--------|
| **Revenue** | \${$yr1_revenue} | \${$yr2_revenue} | \${$yr3_revenue} |
| **Cost of Goods/Services** | \${$yr1_cogs} | \${$yr2_cogs} | \${$yr3_cogs} |
| **Gross Profit** | \${$yr1_gross} | \${$yr2_gross} | \${$yr3_gross} |
| **Operating Expenses** | \${$yr1_opex} | \${$yr2_opex} | \${$yr3_opex} |
| **Net Income** | \${$yr1_net} | \${$yr2_net} | \${$yr3_net} |

### Key Assumptions

- Revenue growth driven by expanded marketing, referral acceleration, and service expansion
- Gross margins improve as operational efficiency increases with scale
- Operating expenses decline as a percentage of revenue due to fixed cost leverage
- Conservative customer acquisition cost estimates with gradual improvement
- No extraordinary one-time income or expenses included

### Break-Even Analysis

Based on projected fixed costs and average contribution margins, {$d->business_name} anticipates reaching operational break-even within the first **6–12 months** of funded operations. Full return on invested capital is projected within **18–24 months**.

## 8. Funding Requirements

### Capital Needed

{$d->business_name} is seeking **{$d->funding_needed}** to execute the growth strategy outlined in this plan.

### Use of Funds

| Category | Allocation | Purpose |
|----------|------------|---------|
| **Operations & Infrastructure** | 30% | Equipment, systems, workspace, technology |
| **Marketing & Customer Acquisition** | 30% | Digital marketing, brand building, lead generation |
| **Working Capital** | 25% | Payroll, inventory, day-to-day operating expenses |
| **Reserve & Contingency** | 15% | Buffer for unexpected costs and market opportunities |

### Detailed Funding Purpose

{$d->funding_purpose}

### Return on Investment

Investors and lenders can expect:

- Clear path to profitability within 12–18 months
- Conservative, achievable financial projections based on market data
- Experienced leadership with a proven ability to execute
- Scalable business model with expanding margins
- Transparent reporting and regular financial updates

## 9. Implementation Timeline

| Phase | Timeline | Key Milestones |
|-------|----------|----------------|
| **Phase 1: Foundation** | Months 1–3 | Secure funding; finalize operations setup; launch initial marketing campaigns; onboard first wave of customers |
| **Phase 2: Growth** | Months 4–8 | Scale marketing spend; hire key team members; optimize service delivery; establish referral pipeline |
| **Phase 3: Optimization** | Months 9–14 | Refine operations for efficiency; expand service offerings; strengthen brand presence; achieve consistent monthly revenue targets |
| **Phase 4: Expansion** | Months 15–24 | Explore new market segments or geographies; invest in technology and automation; pursue strategic partnerships; prepare for next funding round or reinvestment |
| **Phase 5: Maturity** | Months 25–36 | Solidify market leadership position; maximize profitability; evaluate exit or expansion opportunities; build long-term enterprise value |

---

## Appendix

### Contact Information

- **Business:** {$d->business_name}
- **Owner:** {$d->owner_name}
- **Email:** {$d->email}
- **Location:** {$d->location}

### Disclaimer

This business plan has been prepared based on information provided by the business owner and reasonable market assumptions. Financial projections are forward-looking estimates and actual results may vary based on market conditions, execution, and external factors. This document is intended for planning and presentation purposes and should not be construed as a guarantee of future performance.

---

*This Business Plan was prepared by 48HoursReady.com — Learn. Structure. Earn.*
MD;

        return $md;
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

        $result = $this->run_generation($id, $api_key);
        if ($result) {
            $method = !empty($api_key) ? 'AI' : 'template';
            wp_send_json_success(['message' => "Documents generated successfully via {$method}!"]);
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
        // Determine document type from title
        $is_business_plan = (stripos($title, 'Business Plan') !== false);
        $doc_type = $is_business_plan ? 'Business Plan' : 'Executive Summary';
        $date = date('F j, Y');

        // Parse funding amount for financial projections (extract first number from ranges like "$50,000 - $100,000")
        preg_match('/[\$]?([\d,]+)/', $row->funding_needed, $m);
        $funding_num = !empty($m[1]) ? intval(str_replace(',', '', $m[1])) : 100000;
        if ($funding_num < 10000) $funding_num = 100000;

        // Financial projection calculations
        $base_revenue = $funding_num * 0.8;
        if ($base_revenue < 50000) $base_revenue = 100000;
        $fin = $this->calc_financial_projections($base_revenue);

        // Funding allocation breakdown
        $fund_alloc = $this->calc_funding_allocation($funding_num, $row->funding_purpose);

        // Build the slides
        $slides = [];
        $slides[] = $this->slide_cover($row, $doc_type, $date);
        $slides[] = $this->slide_company_overview($row);
        $slides[] = $this->slide_problem_solution($row);
        $slides[] = $this->slide_target_market($row);
        $slides[] = $this->slide_revenue_model($row);
        $slides[] = $this->slide_competitive_advantage($row);
        $slides[] = $this->slide_team_operations($row);
        $slides[] = $this->slide_financial_projections($fin);
        $slides[] = $this->slide_funding($row, $funding_num, $fund_alloc);
        $slides[] = $this->slide_next_steps($row, $doc_type);

        $slides_html = implode("\n", $slides);
        $total_slides = count($slides);

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>' . esc_html($title) . '</title>';
        $html .= '<style>' . $this->pitch_deck_css() . '</style>';
        $html .= '</head><body>';
        $html .= '<button class="print-btn no-print" onclick="window.print()">';
        $html .= '<span class="print-icon">&#9113;</span> Save as PDF / Print</button>';
        $html .= '<div class="slide-nav no-print">';
        $html .= '<span class="nav-label">' . esc_html($doc_type) . ' &mdash; ' . $total_slides . ' Slides</span>';
        $html .= '</div>';
        $html .= $slides_html;
        $html .= '</body></html>';

        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    /* ------------------------------------------------------------------ */
    /*  PITCH DECK CSS                                                     */
    /* ------------------------------------------------------------------ */
    private function pitch_deck_css() {
        return '
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap");

        :root {
            --cream: #F0ECE3;
            --cream-dark: #E8E3D9;
            --gold: #B8975A;
            --gold-light: #D4B87A;
            --gold-bg: rgba(184,151,90,0.10);
            --dark: #2D2D2D;
            --dark-deep: #333333;
            --charcoal: #1E1E1E;
            --text: #2D2D2D;
            --text-light: #7A7A7A;
            --white: #ffffff;
            --black: #111111;
            --slide-w: 1120px;
            --slide-h: 630px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #D9D4CB;
            color: var(--text);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* ---------- PRINT / PDF ---------- */
        @page {
            size: landscape;
            margin: 0;
        }
        @media print {
            body { background: none; }
            .no-print { display: none !important; }
            .slide {
                width: 100vw !important;
                height: 100vh !important;
                max-width: none !important;
                max-height: none !important;
                margin: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                page-break-after: always;
                page-break-inside: avoid;
                overflow: hidden !important;
            }
            .slide:last-child { page-break-after: auto; }
        }

        /* ---------- SCREEN LAYOUT ---------- */
        .slide {
            width: var(--slide-w);
            height: var(--slide-h);
            max-width: 96vw;
            margin: 40px auto;
            background: var(--cream);
            border-radius: 6px;
            box-shadow: 0 2px 24px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            padding: 48px 60px 44px;
        }

        /* ---------- PRINT BUTTON ---------- */
        .print-btn {
            position: fixed;
            top: 20px;
            right: 24px;
            background: var(--dark);
            color: var(--white);
            border: none;
            padding: 14px 28px;
            border-radius: 50px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 700;
            font-family: "Inter", sans-serif;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            transition: all 0.25s ease;
            letter-spacing: 0.3px;
        }
        .print-btn:hover {
            background: var(--gold);
            box-shadow: 0 4px 24px rgba(184,151,90,0.35);
            transform: translateY(-1px);
        }
        .print-icon { font-size: 18px; }

        .slide-nav {
            position: fixed;
            top: 20px;
            left: 24px;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 700;
            color: var(--dark);
            z-index: 9999;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            letter-spacing: 0.3px;
        }

        /* ---------- SLIDE FOOTER ---------- */
        .slide-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 12px 60px;
            font-size: 11px;
            color: var(--text-light);
            border-top: 1px solid rgba(0,0,0,0.06);
            background: rgba(255,255,255,0.2);
        }
        .slide-footer .brand-mark {
            font-weight: 700;
            color: var(--dark);
            letter-spacing: 0.5px;
        }
        .slide-footer .brand-mark .gold { color: var(--gold); }
        .slide-footer .page-num {
            position: absolute;
            right: 60px;
            font-size: 11px;
            color: var(--text-light);
            font-weight: 500;
        }

        /* ---------- TYPOGRAPHY ---------- */
        .slide-title {
            font-size: 40px;
            font-weight: 900;
            color: var(--black);
            margin-bottom: 6px;
            letter-spacing: -0.8px;
            line-height: 1.15;
        }
        .slide-subtitle {
            font-size: 15px;
            color: var(--gold);
            margin-bottom: 28px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .slide-body { flex: 1; overflow: hidden; }

        /* ---------- COVER SLIDE ---------- */
        .cover-slide {
            background: var(--cream);
            color: var(--black);
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 60px;
        }
        .cover-slide .cover-accent {
            width: 80px;
            height: 4px;
            background: var(--gold);
            margin: 0 auto 32px;
            border-radius: 2px;
        }
        .cover-slide .cover-title {
            font-size: 48px;
            font-weight: 900;
            letter-spacing: -1.5px;
            line-height: 1.1;
            margin-bottom: 12px;
            color: var(--black);
        }
        .cover-slide .cover-type {
            font-size: 16px;
            font-weight: 700;
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: 5px;
            margin-bottom: 10px;
        }
        .cover-slide .cover-industry {
            font-size: 18px;
            font-weight: 400;
            color: var(--text-light);
            margin-bottom: 4px;
        }
        .cover-slide .cover-meta {
            font-size: 14px;
            color: var(--text-light);
            margin-top: 24px;
        }
        .cover-slide .cover-brand {
            position: absolute;
            bottom: 42px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 12px;
            color: var(--text-light);
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .cover-slide .cover-brand .gold { color: var(--gold); }
        .cover-slide .cover-tagline {
            position: absolute;
            bottom: 22px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 11px;
            color: var(--gold);
            font-weight: 600;
            letter-spacing: 1.5px;
        }
        .cover-slide .cover-corner-tl,
        .cover-slide .cover-corner-br {
            position: absolute;
            width: 100px;
            height: 100px;
            border: 2px solid var(--gold);
            opacity: 0.2;
        }
        .cover-slide .cover-corner-tl {
            top: 24px; left: 24px;
            border-right: none; border-bottom: none;
            border-radius: 4px 0 0 0;
        }
        .cover-slide .cover-corner-br {
            bottom: 24px; right: 24px;
            border-left: none; border-top: none;
            border-radius: 0 0 4px 0;
        }

        /* ---------- CARDS ---------- */
        .card-grid { display: flex; gap: 20px; flex-wrap: wrap; }
        .card {
            flex: 1;
            min-width: 200px;
            background: var(--white);
            border-radius: 6px;
            padding: 22px 24px;
            border: 1px solid rgba(0,0,0,0.08);
        }
        .card-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--gold);
            margin-bottom: 8px;
        }
        .card-value {
            font-size: 17px;
            font-weight: 800;
            color: var(--black);
            line-height: 1.4;
        }
        .card-desc {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 6px;
            line-height: 1.45;
        }

        /* ---------- TWO-COLUMN ---------- */
        .two-col { display: flex; gap: 32px; height: 100%; }
        .col { flex: 1; }
        .col-header {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 2px solid;
        }
        .col-header.problem { color: var(--dark); border-color: var(--dark); }
        .col-header.solution { color: var(--gold); border-color: var(--gold); }

        /* ---------- BULLET ITEMS ---------- */
        .bullet-list { list-style: none; padding: 0; }
        .bullet-list li {
            position: relative;
            padding: 8px 0 8px 30px;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text);
        }
        .bullet-list li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 14px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--dark);
        }
        .bullet-list.gold li::before { background: var(--gold); }
        .bullet-list.red li::before { background: #c0392b; }

        .check-list { list-style: none; padding: 0; }
        .check-list li {
            position: relative;
            padding: 7px 0 7px 32px;
            font-size: 14px;
            line-height: 1.5;
        }
        .check-list li::before {
            content: "\2713";
            position: absolute;
            left: 0;
            top: 6px;
            width: 22px;
            height: 22px;
            background: var(--gold);
            color: var(--white);
            border-radius: 50%;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        /* ---------- DATA TABLE ---------- */
        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 6px;
            overflow: hidden;
            font-size: 14px;
            border: 1px solid rgba(0,0,0,0.08);
        }
        .data-table thead th {
            background: var(--dark-deep);
            color: var(--white);
            padding: 14px 18px;
            font-weight: 700;
            text-align: left;
            font-size: 12px;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }
        .data-table tbody td {
            padding: 12px 18px;
            background: var(--white);
            border-bottom: 1px solid rgba(0,0,0,0.06);
            color: var(--text);
        }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table .row-highlight td {
            background: var(--gold-bg);
            font-weight: 800;
            color: var(--black);
        }

        /* ---------- PROGRESS BARS ---------- */
        .progress-item { margin-bottom: 16px; }
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }
        .progress-bar {
            height: 12px;
            background: rgba(0,0,0,0.06);
            border-radius: 6px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.6s ease;
        }
        .progress-fill.gold { background: linear-gradient(90deg, var(--gold) 0%, var(--gold-light) 100%); }
        .progress-fill.dark { background: linear-gradient(90deg, var(--dark) 0%, #555555 100%); }

        /* ---------- METRIC CARDS ---------- */
        .metric-row { display: flex; gap: 16px; margin-bottom: 20px; }
        .metric-card {
            flex: 1;
            background: var(--dark-deep);
            border-radius: 6px;
            padding: 18px 20px;
            text-align: center;
        }
        .metric-card.accent { background: var(--dark); }
        .metric-number {
            font-size: 28px;
            font-weight: 900;
            color: var(--white);
            letter-spacing: -0.5px;
        }
        .metric-card.accent .metric-number { color: var(--gold-light); }
        .metric-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--gold);
            margin-top: 4px;
        }

        /* ---------- HIGHLIGHT BOX ---------- */
        .highlight-box {
            background: var(--dark-deep);
            color: var(--white);
            border-radius: 6px;
            padding: 24px 28px;
            margin-top: 16px;
        }
        .highlight-box .hb-title {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--gold);
            margin-bottom: 8px;
        }
        .highlight-box .hb-text {
            font-size: 15px;
            line-height: 1.6;
            font-weight: 400;
            color: rgba(255,255,255,0.9);
        }

        /* ---------- CLOSING SLIDE ---------- */
        .closing-slide {
            background: var(--cream);
            color: var(--black);
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        .closing-slide .closing-title {
            font-size: 38px;
            font-weight: 900;
            margin-bottom: 10px;
            letter-spacing: -0.8px;
            color: var(--black);
        }
        .closing-slide .closing-sub {
            font-size: 17px;
            color: var(--text-light);
            margin-bottom: 32px;
            font-weight: 400;
        }
        .closing-slide .contact-grid {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 32px;
        }
        .closing-slide .contact-item {
            background: var(--dark-deep);
            border: none;
            border-radius: 6px;
            padding: 16px 24px;
            min-width: 180px;
        }
        .closing-slide .contact-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--gold);
            font-weight: 800;
            margin-bottom: 6px;
        }
        .closing-slide .contact-value {
            font-size: 15px;
            font-weight: 500;
            color: var(--white);
        }
        .closing-slide .closing-brand {
            font-size: 13px;
            color: var(--text-light);
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-top: 20px;
        }
        .closing-slide .closing-brand .gold { color: var(--gold); }

        /* ---------- TAG PILL ---------- */
        .tag-pill {
            display: inline-block;
            background: var(--gold-bg);
            color: var(--gold);
            font-size: 11px;
            font-weight: 800;
            padding: 5px 16px;
            border-radius: 20px;
            margin-bottom: 16px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        /* ---------- SECTION NUMBER ---------- */
        .section-num {
            font-size: 56px;
            font-weight: 900;
            color: rgba(0,0,0,0.04);
            position: absolute;
            top: 36px;
            right: 56px;
            line-height: 1;
        }

        /* ---------- BAR CHART (INFOGRAPHIC) ---------- */
        .bar-chart {
            display: flex;
            align-items: flex-end;
            gap: 20px;
            height: 160px;
            padding-top: 10px;
        }
        .bar-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
            justify-content: flex-end;
        }
        .bar-fill {
            width: 100%;
            max-width: 100px;
            background: var(--dark-deep);
            border-radius: 4px 4px 0 0;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 30px;
        }
        .bar-fill.accent { background: var(--dark); }
        .bar-value {
            color: var(--gold-light);
            font-size: 13px;
            font-weight: 800;
            letter-spacing: -0.3px;
        }
        .bar-label {
            margin-top: 8px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ---------- FLOW ARROW ---------- */
        .flow-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        .flow-arrow {
            color: var(--gold);
            font-size: 18px;
            font-weight: 900;
        }
        .flow-box {
            background: var(--dark-deep);
            color: var(--white);
            padding: 10px 18px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
        }

        /* ---------- RESPONSIVE ---------- */
        @media (max-width: 1200px) {
            .slide { width: 96vw; height: auto; min-height: 500px; padding: 36px 40px 40px; }
            .cover-slide .cover-title { font-size: 36px; }
        }
        @media (max-width: 768px) {
            .slide { padding: 28px 24px 32px; }
            .two-col { flex-direction: column; gap: 20px; }
            .card-grid { flex-direction: column; }
            .metric-row { flex-direction: column; }
            .bar-chart { flex-direction: column; height: auto; }
            .cover-slide .cover-title { font-size: 28px; }
            .slide-title { font-size: 28px; }
            .closing-slide .contact-grid { flex-direction: column; align-items: center; }
        }
        ';
    }

    /* ------------------------------------------------------------------ */
    /*  FINANCIAL PROJECTION CALCULATOR                                    */
    /* ------------------------------------------------------------------ */
    private function calc_financial_projections($base_revenue) {
        return [
            'yr1' => [
                'revenue'  => $base_revenue,
                'cogs'     => $base_revenue * 0.45,
                'gross'    => $base_revenue * 0.55,
                'opex'     => $base_revenue * 0.40,
                'net'      => $base_revenue * 0.15,
            ],
            'yr2' => [
                'revenue'  => $base_revenue * 1.75,
                'cogs'     => $base_revenue * 1.75 * 0.40,
                'gross'    => $base_revenue * 1.75 * 0.60,
                'opex'     => $base_revenue * 1.75 * 0.35,
                'net'      => $base_revenue * 1.75 * 0.25,
            ],
            'yr3' => [
                'revenue'  => $base_revenue * 2.8,
                'cogs'     => $base_revenue * 2.8 * 0.37,
                'gross'    => $base_revenue * 2.8 * 0.63,
                'opex'     => $base_revenue * 2.8 * 0.30,
                'net'      => $base_revenue * 2.8 * 0.33,
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  FUNDING ALLOCATION CALCULATOR                                      */
    /* ------------------------------------------------------------------ */
    private function calc_funding_allocation($funding_num, $funding_purpose) {
        $purpose = strtolower($funding_purpose);
        $alloc = [];

        // Default sensible allocation
        $alloc['Product Development']   = 30;
        $alloc['Marketing & Sales']     = 25;
        $alloc['Operations & Staffing'] = 25;
        $alloc['Working Capital']       = 20;

        // Adjust if keywords detected
        if (strpos($purpose, 'market') !== false || strpos($purpose, 'advertis') !== false) {
            $alloc['Marketing & Sales'] = 35;
            $alloc['Product Development'] = 25;
            $alloc['Operations & Staffing'] = 22;
            $alloc['Working Capital'] = 18;
        }
        if (strpos($purpose, 'hire') !== false || strpos($purpose, 'staff') !== false || strpos($purpose, 'team') !== false) {
            $alloc['Operations & Staffing'] = 35;
            $alloc['Product Development'] = 25;
            $alloc['Marketing & Sales'] = 22;
            $alloc['Working Capital'] = 18;
        }
        if (strpos($purpose, 'equip') !== false || strpos($purpose, 'inventory') !== false) {
            $alloc['Product Development'] = 40;
            $alloc['Operations & Staffing'] = 20;
            $alloc['Marketing & Sales'] = 22;
            $alloc['Working Capital'] = 18;
        }

        return $alloc;
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE FOOTER HELPER                                                */
    /* ------------------------------------------------------------------ */
    private function slide_footer($slide_num, $total = 10) {
        return '<div class="slide-footer">'
            . '<span class="brand-mark">Powered by <span class="gold">48HoursReady</span>.com</span>'
            . '<span class="page-num">' . $slide_num . ' / ' . $total . '</span>'
            . '</div>';
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE 1: COVER                                                     */
    /* ------------------------------------------------------------------ */
    private function slide_cover($row, $doc_type, $date) {
        $location = !empty($row->location) ? esc_html($row->location) : '';
        $html  = '<div class="slide cover-slide">';
        $html .= '<div class="cover-corner-tl"></div>';
        $html .= '<div class="cover-corner-br"></div>';
        $html .= '<div class="cover-accent"></div>';
        $html .= '<div class="cover-type">' . esc_html($doc_type) . '</div>';
        $html .= '<div class="cover-title">' . esc_html($row->business_name) . '</div>';
        $html .= '<div class="cover-industry">' . esc_html($row->industry) . '</div>';
        if ($location) {
            $html .= '<div class="cover-industry">' . $location . '</div>';
        }
        $html .= '<div class="cover-meta">' . $date . '</div>';
        $html .= '<div class="cover-brand">Powered by <span class="gold">48HoursReady</span>.com</div>';
        $html .= '<div class="cover-tagline">Pitch Deck Ready. GPT Verified.</div>';
        $html .= '</div>';
        return $html;
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE 2: COMPANY OVERVIEW                                          */
    /* ------------------------------------------------------------------ */
    private function slide_company_overview($row) {
        $stage_map = [
            'idea'        => 'Idea Stage',
            'startup'     => 'Startup',
            'growing'     => 'Growth Stage',
            'established' => 'Established',
        ];
        $stage_label = isset($stage_map[$row->business_stage]) ? $stage_map[$row->business_stage] : ucfirst($row->business_stage);

        $desc = esc_html($row->business_description);
        // Truncate description if too long for slide
        if (strlen($desc) > 380) {
            $desc = substr($desc, 0, 377) . '...';
        }

        $html  = '<div class="slide">';
        $html .= '<span class="section-num">02</span>';
        $html .= '<div class="slide-title">Company Overview</div>';
        $html .= '<div class="slide-subtitle">A snapshot of who we are and what we do</div>';
        $html .= '<div class="slide-body">';
        $html .= '<div class="card-grid">';

        $html .= '<div class="card"><div class="card-label">Business Name</div>';
        $html .= '<div class="card-value">' . esc_html($row->business_name) . '</div></div>';

        $html .= '<div class="card"><div class="card-label">Industry</div>';
        $html .= '<div class="card-value">' . esc_html($row->industry) . '</div></div>';

        $html .= '<div class="card"><div class="card-label">Stage</div>';
        $html .= '<div class="card-value">' . esc_html($stage_label) . '</div></div>';

        $html .= '<div class="card"><div class="card-label">Team Size</div>';
        $html .= '<div class="card-value">' . esc_html($row->num_employees) . ' employees</div></div>';

        $html .= '</div>'; // card-grid

        $html .= '<div class="highlight-box" style="margin-top:20px;">';
        $html .= '<div class="hb-title">About the Business</div>';
        $html .= '<div class="hb-text">' . $desc . '</div>';
        $html .= '</div>';

        $html .= '</div>'; // slide-body
        $html .= $this->slide_footer(2);
        $html .= '</div>';
        return $html;
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE 3: PROBLEM & SOLUTION                                        */
    /* ------------------------------------------------------------------ */
    private function slide_problem_solution($row) {
        $industry = esc_html($row->industry);

        $html  = '<div class="slide">';
        $html .= '<span class="section-num">03</span>';
        $html .= '<div class="slide-title">Problem &amp; Solution</div>';
        $html .= '<div class="slide-subtitle">Identifying the gap and how we fill it</div>';
        $html .= '<div class="slide-body">';
        $html .= '<div class="two-col">';

        // Problem column
        $html .= '<div class="col">';
        $html .= '<div class="col-header problem">The Problem</div>';
        $html .= '<ul class="bullet-list">';
        $html .= '<li>Customers in the ' . $industry . ' sector face limited access to reliable, specialized solutions</li>';
        $html .= '<li>Existing providers deliver fragmented, one-size-fits-all service</li>';
        $html .= '<li>Current market options are often overpriced relative to value delivered</li>';
        $html .= '<li>Slow response times and poor customer experience are the norm</li>';
        $html .= '</ul>';
        $html .= '</div>';

        // Solution column
        $html .= '<div class="col">';
        $html .= '<div class="col-header solution">Our Solution</div>';
        $html .= '<ul class="check-list">';
        $html .= '<li>Deep ' . $industry . ' expertise combined with a customer-first approach</li>';
        $html .= '<li>Tailored solutions designed for real-world customer needs</li>';
        $html .= '<li>Competitive pricing with transparent, predictable costs</li>';
        $html .= '<li>Fast, responsive service with dedicated support</li>';
        $html .= '</ul>';
        $html .= '</div>';

        $html .= '</div>'; // two-col
        $html .= '</div>'; // slide-body
        $html .= $this->slide_footer(3);
        $html .= '</div>';
        return $html;
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE 4: TARGET MARKET                                             */
    /* ------------------------------------------------------------------ */
    private function slide_target_market($row) {
        $target = esc_html($row->target_market);
        // Truncate if needed
        if (strlen($target) > 200) {
            $target = substr($target, 0, 197) . '...';
        }

        $html  = '<div class="slide">';
        $html .= '<span class="section-num">04</span>';
        $html .= '<div class="slide-title">Target Market</div>';
        $html .= '<div class="slide-subtitle">Who we serve and why they choose us</div>';
        $html .= '<div class="slide-body">';

        $html .= '<div class="highlight-box" style="margin-bottom:20px;margin-top:0;">';
        $html .= '<div class="hb-title">Core Customer Profile</div>';
        $html .= '<div class="hb-text">' . $target . '</div>';
        $html .= '</div>';

        $html .= '<div class="card-grid">';

        $html .= '<div class="card"><div class="card-label">Primary Segment</div>';
        $html .= '<div class="card-value">Core Customers</div>';
        $html .= '<div class="card-desc">Customers with immediate, recurring needs who form the revenue backbone</div></div>';

        $html .= '<div class="card"><div class="card-label">Secondary Segment</div>';
        $html .= '<div class="card-value">Adjacent Markets</div>';
        $html .= '<div class="card-desc">Related segments that benefit from the same solutions and expand reach</div></div>';

        $html .= '<div class="card"><div class="card-label">Growth Segment</div>';
        $html .= '<div class="card-value">Referral Pipeline</div>';
        $html .= '<div class="card-desc">Broader audience converting over time through word-of-mouth and partnerships</div></div>';

        $html .= '</div>'; // card-grid
        $html .= '</div>'; // slide-body
        $html .= $this->slide_footer(4);
        $html .= '</div>';
        return $html;
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE 5: REVENUE MODEL                                             */
    /* ------------------------------------------------------------------ */
    private function slide_revenue_model($row) {
        $revenue = esc_html($row->revenue_model);
        if (strlen($revenue) > 280) {
            $revenue = substr($revenue, 0, 277) . '...';
        }

        $html  = '<div class="slide">';
        $html .= '<span class="section-num">05</span>';
        $html .= '<div class="slide-title">Revenue Model</div>';
        $html .= '<div class="slide-subtitle">How we generate and grow revenue</div>';
        $html .= '<div class="slide-body">';

        $html .= '<div class="card" style="margin-bottom:24px;">';
        $html .= '<div class="card-label">Revenue Strategy</div>';
        $html .= '<div class="card-value" style="font-size:15px;line-height:1.6;">' . $revenue . '</div>';
        $html .= '</div>';

        $html .= '<div class="card-grid">';

        $html .= '<div class="card"><div class="card-label">Scalability</div>';
        $html .= '<div class="card-value">High</div>';
        $html .= '<div class="card-desc">Revenue model designed to scale with customer base growth while improving unit economics</div></div>';

        $html .= '<div class="card"><div class="card-label">Cash Flow</div>';
        $html .= '<div class="card-value">Predictable</div>';
        $html .= '<div class="card-desc">Structured for consistent, recurring revenue streams enabling confident reinvestment</div></div>';

        $html .= '<div class="card"><div class="card-label">Growth Effect</div>';
        $html .= '<div class="card-value">Compounding</div>';
        $html .= '<div class="card-desc">Expanding customer base drives improving margins and accelerating returns over time</div></div>';

        $html .= '</div>'; // card-grid
        $html .= '</div>'; // slide-body
        $html .= $this->slide_footer(5);
        $html .= '</div>';
        return $html;
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE 6: COMPETITIVE ADVANTAGE                                     */
    /* ------------------------------------------------------------------ */
    private function slide_competitive_advantage($row) {
        $advantage = esc_html($row->competitive_advantage);
        if (strlen($advantage) > 350) {
            $advantage = substr($advantage, 0, 347) . '...';
        }

        $html  = '<div class="slide">';
        $html .= '<span class="section-num">06</span>';
        $html .= '<div class="slide-title">Competitive Advantage</div>';
        $html .= '<div class="slide-subtitle">What sets us apart in the marketplace</div>';
        $html .= '<div class="slide-body">';

        $html .= '<div class="highlight-box" style="margin-bottom:20px;margin-top:0;">';
        $html .= '<div class="hb-title">Key Differentiator</div>';
        $html .= '<div class="hb-text">' . $advantage . '</div>';
        $html .= '</div>';

        $html .= '<div class="card-grid">';

        $html .= '<div class="card" style="border-left:3px solid var(--gold);">';
        $html .= '<div class="card-label">Customer Lifetime Value</div>';
        $html .= '<div class="card-value">Above Average</div>';
        $html .= '<div class="card-desc">Trust-based relationships drive higher retention and repeat business</div></div>';

        $html .= '<div class="card" style="border-left:3px solid var(--gold);">';
        $html .= '<div class="card-label">Brand Reputation</div>';
        $html .= '<div class="card-value">Growing Organically</div>';
        $html .= '<div class="card-desc">Quality-driven approach generates strong referral growth</div></div>';

        $html .= '<div class="card" style="border-left:3px solid var(--gold);">';
        $html .= '<div class="card-label">Market Position</div>';
        $html .= '<div class="card-value">Defensible</div>';
        $html .= '<div class="card-desc">Unique combination of expertise and service that competitors struggle to replicate</div></div>';

        $html .= '</div>'; // card-grid
        $html .= '</div>'; // slide-body
        $html .= $this->slide_footer(6);
        $html .= '</div>';
        return $html;
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE 7: TEAM & OPERATIONS                                         */
    /* ------------------------------------------------------------------ */
    private function slide_team_operations($row) {
        $html  = '<div class="slide">';
        $html .= '<span class="section-num">07</span>';
        $html .= '<div class="slide-title">Team &amp; Operations</div>';
        $html .= '<div class="slide-subtitle">The people and processes driving our success</div>';
        $html .= '<div class="slide-body">';

        $html .= '<div class="two-col">';

        // Left: Leadership
        $html .= '<div class="col">';
        $html .= '<div class="col-header" style="color:var(--dark);border-color:var(--dark);">Leadership</div>';
        $html .= '<div class="card" style="margin-bottom:16px;">';
        $html .= '<div class="card-label">Founder &amp; Lead</div>';
        $html .= '<div class="card-value">' . esc_html($row->owner_name) . '</div>';
        $html .= '<div class="card-desc">Hands-on expertise and deep understanding of the ' . esc_html($row->industry) . ' market</div>';
        $html .= '</div>';

        $html .= '<div class="metric-row">';
        $html .= '<div class="metric-card"><div class="metric-number">' . esc_html($row->num_employees) . '</div>';
        $html .= '<div class="metric-label">Team Members</div></div>';
        $html .= '<div class="metric-card accent"><div class="metric-number" style="font-size:22px;">' . esc_html($row->location) . '</div>';
        $html .= '<div class="metric-label">Headquarters</div></div>';
        $html .= '</div>';
        $html .= '</div>';

        // Right: Operational Model
        $html .= '<div class="col">';
        $html .= '<div class="col-header" style="color:var(--gold);border-color:var(--gold);">Operational Model</div>';
        $html .= '<ul class="check-list">';
        $html .= '<li>Lean overhead with scalable processes</li>';
        $html .= '<li>Data-informed decision making</li>';
        $html .= '<li>Continuous improvement &amp; feedback loops</li>';
        $html .= '<li>Strategic partnerships to extend reach</li>';
        $html .= '<li>Clear roles and accountability across all functions</li>';
        $html .= '</ul>';
        $html .= '</div>';

        $html .= '</div>'; // two-col
        $html .= '</div>'; // slide-body
        $html .= $this->slide_footer(7);
        $html .= '</div>';
        return $html;
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE 8: FINANCIAL PROJECTIONS                                     */
    /* ------------------------------------------------------------------ */
    private function slide_financial_projections($fin) {
        $yr1 = $fin['yr1'];
        $yr2 = $fin['yr2'];
        $yr3 = $fin['yr3'];

        $f = function($n) { return '$' . number_format($n); };

        // Calculate bar heights as percentage of max revenue (yr3)
        $max_rev = max($yr1['revenue'], $yr2['revenue'], $yr3['revenue']);
        $bar1_pct = round(($yr1['revenue'] / $max_rev) * 100);
        $bar2_pct = round(($yr2['revenue'] / $max_rev) * 100);
        $bar3_pct = 100;

        $html  = '<div class="slide">';
        $html .= '<span class="section-num">08</span>';
        $html .= '<div class="slide-title">Financial Projections</div>';
        $html .= '<div class="slide-subtitle">Three-year outlook based on current trajectory and growth plan</div>';
        $html .= '<div class="slide-body">';

        $html .= '<div class="two-col">';

        // Left: Bar chart infographic
        $html .= '<div class="col">';
        $html .= '<div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:2px;color:var(--gold);margin-bottom:14px;">Revenue Growth</div>';
        $html .= '<div class="bar-chart">';
        $html .= '<div class="bar-col"><div class="bar-fill" style="height:' . $bar1_pct . '%;"><span class="bar-value">' . $f($yr1['revenue']) . '</span></div><div class="bar-label">Year 1</div></div>';
        $html .= '<div class="bar-col"><div class="bar-fill" style="height:' . $bar2_pct . '%;"><span class="bar-value">' . $f($yr2['revenue']) . '</span></div><div class="bar-label">Year 2</div></div>';
        $html .= '<div class="bar-col"><div class="bar-fill accent" style="height:' . $bar3_pct . '%;"><span class="bar-value">' . $f($yr3['revenue']) . '</span></div><div class="bar-label">Year 3</div></div>';
        $html .= '</div>';
        $html .= '</div>';

        // Right: Table
        $html .= '<div class="col">';
        $html .= '<table class="data-table">';
        $html .= '<thead><tr><th>Metric</th><th>Year 1</th><th>Year 2</th><th>Year 3</th></tr></thead>';
        $html .= '<tbody>';
        $html .= '<tr><td>Revenue</td><td>' . $f($yr1['revenue']) . '</td><td>' . $f($yr2['revenue']) . '</td><td>' . $f($yr3['revenue']) . '</td></tr>';
        $html .= '<tr><td>COGS</td><td>' . $f($yr1['cogs']) . '</td><td>' . $f($yr2['cogs']) . '</td><td>' . $f($yr3['cogs']) . '</td></tr>';
        $html .= '<tr><td>Gross Profit</td><td>' . $f($yr1['gross']) . '</td><td>' . $f($yr2['gross']) . '</td><td>' . $f($yr3['gross']) . '</td></tr>';
        $html .= '<tr><td>OpEx</td><td>' . $f($yr1['opex']) . '</td><td>' . $f($yr2['opex']) . '</td><td>' . $f($yr3['opex']) . '</td></tr>';
        $html .= '<tr class="row-highlight"><td>Net Profit</td><td>' . $f($yr1['net']) . '</td><td>' . $f($yr2['net']) . '</td><td>' . $f($yr3['net']) . '</td></tr>';
        $html .= '</tbody></table>';
        $html .= '</div>';

        $html .= '</div>'; // two-col
        $html .= '</div>'; // slide-body
        $html .= $this->slide_footer(8);
        $html .= '</div>';
        return $html;
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE 9: FUNDING & USE OF FUNDS                                    */
    /* ------------------------------------------------------------------ */
    private function slide_funding($row, $funding_num, $fund_alloc) {
        $purpose = esc_html($row->funding_purpose);
        if (strlen($purpose) > 220) {
            $purpose = substr($purpose, 0, 217) . '...';
        }

        $colors = ['gold', 'dark', 'gold', 'dark'];
        $i = 0;

        $html  = '<div class="slide">';
        $html .= '<span class="section-num">09</span>';
        $html .= '<div class="slide-title">Funding &amp; Use of Funds</div>';
        $html .= '<div class="slide-subtitle">Investment required and strategic allocation plan</div>';
        $html .= '<div class="slide-body">';

        $html .= '<div class="two-col">';

        // Left: funding ask - large bold number in dark box
        $html .= '<div class="col">';
        $html .= '<div class="metric-card accent" style="margin-bottom:20px;padding:28px 20px;">';
        $html .= '<div class="metric-number" style="font-size:36px;font-weight:900;">$' . number_format($funding_num) . '</div>';
        $html .= '<div class="metric-label">Funding Requested</div></div>';

        $html .= '<div class="card"><div class="card-label">Purpose</div>';
        $html .= '<div class="card-desc" style="font-size:14px;color:var(--text);">' . $purpose . '</div></div>';
        $html .= '</div>';

        // Right: allocation bars
        $html .= '<div class="col">';
        $html .= '<div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:2px;color:var(--gold);margin-bottom:18px;">Allocation Breakdown</div>';

        foreach ($fund_alloc as $label => $pct) {
            $color = $colors[$i % count($colors)];
            $amount = number_format($funding_num * $pct / 100);
            $html .= '<div class="progress-item">';
            $html .= '<div class="progress-label"><span>' . esc_html($label) . '</span><span>' . $pct . '% ($' . $amount . ')</span></div>';
            $html .= '<div class="progress-bar"><div class="progress-fill ' . $color . '" style="width:' . $pct . '%;"></div></div>';
            $html .= '</div>';
            $i++;
        }

        $html .= '</div>';
        $html .= '</div>'; // two-col
        $html .= '</div>'; // slide-body
        $html .= $this->slide_footer(9);
        $html .= '</div>';
        return $html;
    }

    /* ------------------------------------------------------------------ */
    /*  SLIDE 10: NEXT STEPS / CONTACT                                     */
    /* ------------------------------------------------------------------ */
    private function slide_next_steps($row, $doc_type) {
        $html  = '<div class="slide closing-slide">';
        $html .= '<div class="cover-corner-tl"></div>';
        $html .= '<div class="cover-corner-br"></div>';

        $html .= '<div class="tag-pill">Next Steps</div>';
        $html .= '<div class="closing-title">Let\'s build the standard together.</div>';
        $html .= '<div class="closing-sub">' . esc_html($row->business_name) . ' is ready for the next chapter. We invite you to connect.</div>';

        $html .= '<div class="contact-grid">';

        $html .= '<div class="contact-item">';
        $html .= '<div class="contact-label">Contact</div>';
        $html .= '<div class="contact-value">' . esc_html($row->owner_name) . '</div></div>';

        $html .= '<div class="contact-item">';
        $html .= '<div class="contact-label">Email</div>';
        $html .= '<div class="contact-value">' . esc_html($row->email) . '</div></div>';

        if (!empty($row->phone)) {
            $html .= '<div class="contact-item">';
            $html .= '<div class="contact-label">Phone</div>';
            $html .= '<div class="contact-value">' . esc_html($row->phone) . '</div></div>';
        }

        if (!empty($row->website)) {
            $html .= '<div class="contact-item">';
            $html .= '<div class="contact-label">Website</div>';
            $html .= '<div class="contact-value">' . esc_html($row->website) . '</div></div>';
        }

        $html .= '</div>'; // contact-grid

        $html .= '<div class="closing-brand">Powered by <span class="gold">48HoursReady</span>.com &mdash; Pitch Deck Ready. GPT Verified.</div>';
        $html .= '</div>';
        return $html;
    }

    private function markdown_to_html($text) {
        // Basic markdown to HTML conversion
        $text = esc_html($text);

        // --- Markdown tables ---
        // Match table blocks: header row, separator row, then data rows
        $text = preg_replace_callback(
            '/^(\|.+\|)\n(\|[\s\-\|:]+\|)\n((?:\|.+\|\n?)+)/m',
            function ($match) {
                $header_line = trim($match[1]);
                $body_lines  = trim($match[3]);

                // Parse header cells
                $headers = array_map('trim', explode('|', trim($header_line, '|')));
                $html = '<table><thead><tr>';
                foreach ($headers as $h) {
                    $html .= '<th>' . $h . '</th>';
                }
                $html .= '</tr></thead><tbody>';

                // Parse body rows
                foreach (explode("\n", $body_lines) as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    $cells = array_map('trim', explode('|', trim($line, '|')));
                    $html .= '<tr>';
                    foreach ($cells as $cell) {
                        $html .= '<td>' . $cell . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                return $html;
            },
            $text
        );

        // --- Horizontal rules ---
        $text = preg_replace('/^---+$/m', '<hr>', $text);

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
        $text = preg_replace('/<p>\s*<table>/', '<table>', $text);
        $text = preg_replace('/<\/table>\s*<\/p>/', '</table>', $text);
        $text = preg_replace('/<p>\s*<hr>/', '<hr>', $text);
        $text = preg_replace('/<hr>\s*<\/p>/', '<hr>', $text);

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

        // Handle regeneration — uses AI if key is set, otherwise templates
        if (isset($_GET['regenerate']) && check_admin_referer('hr48_regen')) {
            $id = intval($_GET['regenerate']);
            $api_key = get_option('hr48_openai_api_key');
            $this->run_generation($id, $api_key);
            $method = !empty($api_key) ? 'AI' : 'template';
            echo '<div class="updated"><p>Documents regenerated via ' . esc_html($method) . '.</p></div>';
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
