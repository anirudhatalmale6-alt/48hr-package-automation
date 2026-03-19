<?php if (!defined('ABSPATH')) exit; ?>

<div class="hr48-intake-wrapper">
    <div class="hr48-intake-header">
        <h2>Start Your Business Package</h2>
        <p>Fill in your business details below. Our AI will generate a professional Executive Summary and Business Plan tailored to your business — ready in minutes.</p>
    </div>

    <form id="hr48-intake-form" class="hr48-form">
        <input type="hidden" name="action" value="hr48_submit_intake" />
        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('hr48_intake_nonce'); ?>" />

        <!-- Step indicator -->
        <div class="hr48-steps">
            <div class="hr48-step active" data-step="1">
                <span class="hr48-step-num">1</span>
                <span class="hr48-step-label">Basic Info</span>
            </div>
            <div class="hr48-step" data-step="2">
                <span class="hr48-step-num">2</span>
                <span class="hr48-step-label">Business Details</span>
            </div>
            <div class="hr48-step" data-step="3">
                <span class="hr48-step-num">3</span>
                <span class="hr48-step-label">Financials</span>
            </div>
        </div>

        <!-- Step 1: Basic Information -->
        <div class="hr48-step-content active" id="step-1">
            <h3>Tell us about yourself</h3>

            <div class="hr48-field-row">
                <div class="hr48-field">
                    <label for="business_name">Business Name <span class="required">*</span></label>
                    <input type="text" id="business_name" name="business_name" required placeholder="e.g., Miami Fresh Juices LLC" />
                </div>
                <div class="hr48-field">
                    <label for="owner_name">Your Full Name <span class="required">*</span></label>
                    <input type="text" id="owner_name" name="owner_name" required placeholder="e.g., Jean-Pierre Laurent" />
                </div>
            </div>

            <div class="hr48-field-row">
                <div class="hr48-field">
                    <label for="email">Email Address <span class="required">*</span></label>
                    <input type="email" id="email" name="email" required placeholder="your@email.com" />
                </div>
                <div class="hr48-field">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" placeholder="+1 (555) 000-0000" />
                </div>
            </div>

            <div class="hr48-field-row">
                <div class="hr48-field">
                    <label for="location">Business Location</label>
                    <input type="text" id="location" name="location" placeholder="City, State/Country" />
                </div>
                <div class="hr48-field">
                    <label for="website">Website (if any)</label>
                    <input type="url" id="website" name="website" placeholder="https://www.example.com" />
                </div>
            </div>

            <div class="hr48-nav-buttons">
                <span></span>
                <button type="button" class="hr48-btn hr48-btn-next" data-next="2">Continue</button>
            </div>
        </div>

        <!-- Step 2: Business Details -->
        <div class="hr48-step-content" id="step-2">
            <h3>Describe your business</h3>

            <div class="hr48-field-row">
                <div class="hr48-field">
                    <label for="industry">Industry / Sector</label>
                    <select id="industry" name="industry">
                        <option value="">Select your industry...</option>
                        <option value="Agriculture & Food">Agriculture & Food</option>
                        <option value="Construction & Real Estate">Construction & Real Estate</option>
                        <option value="Education & Training">Education & Training</option>
                        <option value="Fashion & Beauty">Fashion & Beauty</option>
                        <option value="Financial Services">Financial Services</option>
                        <option value="Healthcare & Wellness">Healthcare & Wellness</option>
                        <option value="Hospitality & Tourism">Hospitality & Tourism</option>
                        <option value="Manufacturing">Manufacturing</option>
                        <option value="Media & Entertainment">Media & Entertainment</option>
                        <option value="Professional Services">Professional Services</option>
                        <option value="Retail & E-commerce">Retail & E-commerce</option>
                        <option value="Technology & Software">Technology & Software</option>
                        <option value="Transportation & Logistics">Transportation & Logistics</option>
                        <option value="Non-Profit & Social Enterprise">Non-Profit & Social Enterprise</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="hr48-field">
                    <label for="business_stage">Business Stage</label>
                    <select id="business_stage" name="business_stage">
                        <option value="">Select stage...</option>
                        <option value="Idea Stage">Idea Stage — Just getting started</option>
                        <option value="Startup">Startup — Less than 1 year</option>
                        <option value="Early Growth">Early Growth — 1-3 years</option>
                        <option value="Established">Established — 3+ years</option>
                        <option value="Scaling">Scaling — Ready to expand</option>
                    </select>
                </div>
            </div>

            <div class="hr48-field">
                <label for="business_description">Business Description <span class="required">*</span></label>
                <textarea id="business_description" name="business_description" required rows="4" placeholder="What does your business do? What products or services do you offer? What problem are you solving?"></textarea>
            </div>

            <div class="hr48-field">
                <label for="target_market">Target Market</label>
                <textarea id="target_market" name="target_market" rows="3" placeholder="Who are your ideal customers? What demographics, location, or needs do they have?"></textarea>
            </div>

            <div class="hr48-field-row">
                <div class="hr48-field">
                    <label for="competitive_advantage">What Makes You Different?</label>
                    <textarea id="competitive_advantage" name="competitive_advantage" rows="3" placeholder="What's your unique advantage over competitors?"></textarea>
                </div>
                <div class="hr48-field">
                    <label for="num_employees">Number of Employees</label>
                    <select id="num_employees" name="num_employees">
                        <option value="">Select...</option>
                        <option value="Just me">Just me (solo)</option>
                        <option value="2-5">2-5 employees</option>
                        <option value="6-20">6-20 employees</option>
                        <option value="21-50">21-50 employees</option>
                        <option value="50+">50+ employees</option>
                    </select>
                </div>
            </div>

            <div class="hr48-nav-buttons">
                <button type="button" class="hr48-btn hr48-btn-back" data-back="1">Back</button>
                <button type="button" class="hr48-btn hr48-btn-next" data-next="3">Continue</button>
            </div>
        </div>

        <!-- Step 3: Financials & Package -->
        <div class="hr48-step-content" id="step-3">
            <h3>Financial details</h3>

            <div class="hr48-field">
                <label for="revenue_model">Revenue Model</label>
                <textarea id="revenue_model" name="revenue_model" rows="3" placeholder="How does (or will) your business make money? Describe your pricing and revenue streams."></textarea>
            </div>

            <div class="hr48-field-row">
                <div class="hr48-field">
                    <label for="funding_needed">Funding Needed</label>
                    <select id="funding_needed" name="funding_needed">
                        <option value="">Select amount...</option>
                        <option value="Under $10,000">Under $10,000</option>
                        <option value="$10,000 - $50,000">$10,000 - $50,000</option>
                        <option value="$50,000 - $100,000">$50,000 - $100,000</option>
                        <option value="$100,000 - $500,000">$100,000 - $500,000</option>
                        <option value="$500,000+">$500,000+</option>
                        <option value="Not seeking funding">Not seeking funding</option>
                    </select>
                </div>
                <div class="hr48-field">
                    <label for="package_type">Package</label>
                    <select id="package_type" name="package_type">
                        <option value="starter">Business Starter — $50</option>
                        <option value="ready">Business Ready — $199</option>
                        <option value="priority">Priority 2-Languages — $249</option>
                    </select>
                </div>
            </div>

            <div class="hr48-field">
                <label for="funding_purpose">What will the funding be used for?</label>
                <textarea id="funding_purpose" name="funding_purpose" rows="3" placeholder="Equipment, inventory, marketing, hiring, expansion..."></textarea>
            </div>

            <div class="hr48-submit-section">
                <div class="hr48-nav-buttons">
                    <button type="button" class="hr48-btn hr48-btn-back" data-back="2">Back</button>
                    <button type="submit" class="hr48-btn hr48-btn-submit" id="hr48-submit-btn">
                        <span class="btn-text">Generate My Business Package</span>
                        <span class="btn-loading" style="display:none;">Generating... Please wait</span>
                    </button>
                </div>
                <p class="hr48-disclaimer">By submitting, you agree to our terms of service. Your documents will be generated using AI and delivered to your email.</p>
            </div>
        </div>
    </form>

    <!-- Success message (hidden by default) -->
    <div id="hr48-success" class="hr48-success" style="display:none;">
        <div class="hr48-success-icon">&#10003;</div>
        <h2>Your Business Package is Being Generated!</h2>
        <p>We're creating your Executive Summary and Business Plan right now. This usually takes about 30 seconds.</p>
        <p>You'll be redirected to your results page momentarily...</p>
        <div class="hr48-loading-bar"><div class="hr48-loading-progress"></div></div>
    </div>
</div>
