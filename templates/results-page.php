<?php
if (!defined('ABSPATH')) exit;

$token = sanitize_text_field($_GET['token'] ?? '');
$submission = null;

if (!empty($token)) {
    $automation = HR48_Package_Automation::get_instance();
    $submission = $automation->get_submission_by_token($token);
}
?>

<div class="hr48-results-wrapper">
    <?php if (!$submission): ?>
        <div class="hr48-results-empty">
            <h2>No Results Found</h2>
            <p>We couldn't find a submission with that token. Please check your link or <a href="<?php echo home_url('/get-started/'); ?>">submit a new request</a>.</p>
        </div>
    <?php elseif ($submission->status === 'pending'): ?>
        <div class="hr48-results-pending">
            <div class="hr48-pending-icon">&#9203;</div>
            <h2>Your Documents Are Being Generated</h2>
            <p>We're working on your Executive Summary and Business Plan for <strong><?php echo esc_html($submission->business_name); ?></strong>.</p>
            <p>This page will automatically refresh. If it doesn't update within a few minutes, please contact us.</p>
            <div class="hr48-loading-bar"><div class="hr48-loading-progress"></div></div>
            <script>setTimeout(function(){ location.reload(); }, 10000);</script>
        </div>
    <?php else: ?>
        <div class="hr48-results-ready">
            <div class="hr48-results-header">
                <div class="hr48-success-icon">&#10003;</div>
                <h2>Your Business Package is Ready!</h2>
                <p>Documents generated for <strong><?php echo esc_html($submission->business_name); ?></strong></p>
                <p class="hr48-results-date">Generated on <?php echo date('F j, Y', strtotime($submission->generated_at)); ?></p>
            </div>

            <div class="hr48-documents-grid">
                <!-- Executive Summary -->
                <div class="hr48-doc-card">
                    <div class="hr48-doc-icon">&#128196;</div>
                    <h3>Executive Summary</h3>
                    <p>A concise 1-page overview of your business — perfect for investors, banks, and partners.</p>
                    <div class="hr48-doc-preview">
                        <?php echo wp_kses_post(nl2br(wp_trim_words(strip_tags($submission->exec_summary), 50))); ?>...
                    </div>
                    <div class="hr48-doc-actions">
                        <a href="<?php echo admin_url('admin-ajax.php?action=hr48_download_pdf&token=' . $token . '&type=exec_summary'); ?>"
                           class="hr48-btn hr48-btn-download" target="_blank">
                            View / Download PDF
                        </a>
                    </div>
                </div>

                <!-- Business Plan -->
                <div class="hr48-doc-card">
                    <div class="hr48-doc-icon">&#128209;</div>
                    <h3>Business Plan</h3>
                    <p>A comprehensive business plan with market analysis, financial projections, and strategy.</p>
                    <div class="hr48-doc-preview">
                        <?php echo wp_kses_post(nl2br(wp_trim_words(strip_tags($submission->business_plan), 50))); ?>...
                    </div>
                    <div class="hr48-doc-actions">
                        <a href="<?php echo admin_url('admin-ajax.php?action=hr48_download_pdf&token=' . $token . '&type=business_plan'); ?>"
                           class="hr48-btn hr48-btn-download" target="_blank">
                            View / Download PDF
                        </a>
                    </div>
                </div>
            </div>

            <div class="hr48-results-footer">
                <p>Need changes or want to upgrade your package? <a href="<?php echo home_url('/contact/'); ?>">Contact us</a></p>
                <p class="hr48-brand">Powered by <strong>48HoursReady.com</strong> — Learn. Structure. Earn.</p>
            </div>
        </div>
    <?php endif; ?>
</div>
