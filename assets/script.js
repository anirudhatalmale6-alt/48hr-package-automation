/**
 * 48HoursReady Package Automation - Frontend JS
 */
(function($) {
    'use strict';

    // Step navigation
    var currentStep = 1;
    var totalSteps = 3;

    function goToStep(step) {
        if (step < 1 || step > totalSteps) return;

        // Validate current step before advancing
        if (step > currentStep && !validateStep(currentStep)) {
            return;
        }

        // Update step content
        $('.hr48-step-content').removeClass('active');
        $('#step-' + step).addClass('active');

        // Update step indicators
        $('.hr48-step').each(function() {
            var s = parseInt($(this).data('step'));
            $(this).removeClass('active completed');
            if (s === step) {
                $(this).addClass('active');
            } else if (s < step) {
                $(this).addClass('completed');
            }
        });

        currentStep = step;

        // Scroll to top of form
        $('html, body').animate({
            scrollTop: $('.hr48-intake-wrapper').offset().top - 50
        }, 300);
    }

    function validateStep(step) {
        var valid = true;
        var firstInvalid = null;

        $('#step-' + step).find('input[required], textarea[required], select[required]').each(function() {
            if (!this.value.trim()) {
                valid = false;
                $(this).css('border-color', '#e74c3c');
                if (!firstInvalid) firstInvalid = $(this);
            } else {
                $(this).css('border-color', '#e0e0e0');
            }
        });

        // Email validation for step 1
        if (step === 1) {
            var emailField = $('#email');
            var emailVal = emailField.val().trim();
            if (emailVal && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                valid = false;
                emailField.css('border-color', '#e74c3c');
                if (!firstInvalid) firstInvalid = emailField;
            }
        }

        if (firstInvalid) {
            firstInvalid.focus();
        }

        return valid;
    }

    // Attach navigation events
    $(document).on('click', '.hr48-btn-next', function() {
        var nextStep = parseInt($(this).data('next'));
        goToStep(nextStep);
    });

    $(document).on('click', '.hr48-btn-back', function() {
        var backStep = parseInt($(this).data('back'));
        goToStep(backStep);
    });

    // Clear error styling on focus
    $(document).on('focus', '.hr48-field input, .hr48-field textarea, .hr48-field select', function() {
        $(this).css('border-color', '#091263');
    });

    $(document).on('blur', '.hr48-field input, .hr48-field textarea, .hr48-field select', function() {
        if (this.value.trim()) {
            $(this).css('border-color', '#e0e0e0');
        }
    });

    // Form submission
    $('#hr48-intake-form').on('submit', function(e) {
        e.preventDefault();

        if (!validateStep(3)) return;

        var $btn = $('#hr48-submit-btn');
        var $form = $(this);

        $btn.prop('disabled', true);
        $btn.find('.btn-text').hide();
        $btn.find('.btn-loading').show();

        $.ajax({
            url: hr48Auto.ajaxUrl,
            type: 'POST',
            data: $form.serialize(),
            success: function(response) {
                if (response.success) {
                    // Show success message
                    $form.hide();
                    $('.hr48-steps').hide();
                    $('#hr48-success').show();

                    // Redirect after animation
                    setTimeout(function() {
                        window.location.href = response.data.redirect;
                    }, 3000);
                } else {
                    alert(response.data.message || 'Something went wrong. Please try again.');
                    $btn.prop('disabled', false);
                    $btn.find('.btn-text').show();
                    $btn.find('.btn-loading').hide();
                }
            },
            error: function() {
                alert('Connection error. Please check your internet and try again.');
                $btn.prop('disabled', false);
                $btn.find('.btn-text').show();
                $btn.find('.btn-loading').hide();
            }
        });
    });

})(jQuery);
