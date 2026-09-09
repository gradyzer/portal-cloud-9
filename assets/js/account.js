/**
 * ============================================================================
 * Portal Cloud 9 - Account Settings Handler
 * ============================================================================
 * 
 * Handles all account management functionality including:
 * - Profile information updates (name, email, bio)
 * - Password changes with strength validation
 * - Avatar/profile picture uploads
 * - Billing and shipping address management
 * - Account data export (GDPR compliance)
 * - Account deletion requests
 * - Tab navigation and state persistence
 * - Form change tracking and navigation warnings
 * 
 * Dependencies: jQuery
 * 
 * @package Portal_Cloud_9
 * @version 8.3.6
 * @author Brian Agoi (Gradyzer)
 * @company Gradyzer
 * @license GPL-2.0+
 * ============================================================================
 */

(function($) {
    "use strict";
    
    /**
     * Main Account Manager object
     * Handles all account-related operations
     */
    const AccountManager = {
        /**
         * Flag to track if form has unsaved changes
         * Used to warn user before navigation
         */
        formChanged: false,
        
        /**
         * Initialize the account manager
         * Called on document ready if account form exists
         */
        init: function() {
            this.cacheElements();
            this.bindEvents();
            this.initPasswordStrength();
            this.restoreFormState();
            this.initTabScrollDetection();
        },
        
        /**
         * Cache jQuery selectors for better performance
         * Stores references to frequently accessed DOM elements
         */
        cacheElements: function() {
            this.$form = $("#p9-account-form");
            this.$saveBtn = $("#p9-save-account");
            this.$resetBtn = $("#p9-reset-form");
            this.$exportBtn = $("#p9-export-data");
            this.$deleteBtn = $("#p9-delete-account");
            this.$avatarBtn = $("#p9-avatar-upload-btn");
            this.$avatarInput = $("#p9-avatar-input");
            this.$avatarPreview = $("#p9-avatar-preview");
            this.$newPassword = $("#p9-new-password");
            this.$confirmPassword = $("#p9-confirm-password");
            this.$currentPassword = $("#p9-current-password");
            this.$sameAsBilling = $("#p9-same-as-billing");
            this.$shippingFields = $("#p9-shipping-fields");
            this.$messagesContainer = $("#p9-account-messages");
        },
        
        /**
         * Bind all event listeners
         * Sets up click, change, and input handlers for all interactive elements
         */
        bindEvents: function() {
            // Form submission
            this.$form.on("submit", this.handleSubmit.bind(this));
            
            // Button clicks
            this.$resetBtn.on("click", this.resetForm.bind(this));
            this.$exportBtn.on("click", this.exportData.bind(this));
            this.$deleteBtn.on("click", this.deleteAccount.bind(this));
            this.$avatarBtn.on("click", () => this.$avatarInput.click());
            
            // File upload
            this.$avatarInput.on("change", this.handleAvatarUpload.bind(this));
            
            // Password fields
            this.$newPassword.on("input", this.checkPasswordStrength.bind(this));
            this.$confirmPassword.on("input", this.validatePasswordMatch.bind(this));
            
            // Tab navigation
            $(".p9-tab-btn").on("click", this.switchTab.bind(this));
            
            // Password visibility toggle
            $(".p9-password-toggle").on("click", this.togglePasswordVisibility.bind(this));
            
            // Shipping/billing sync
            this.$sameAsBilling.on("change", this.toggleShippingFields.bind(this));
            
            // Track changes
            this.$form.find("input, textarea, select").on("change input", this.trackFormChanges.bind(this));
            
            // Prevent navigation with unsaved changes
            $(window).on("beforeunload", this.preventNavigationIfChanged.bind(this));
        },
        
        /**
         * Switch between account tabs (Profile, Password, Addresses, etc.)
         * 
         * @param {Event} event - Click event from tab button
         */
        switchTab: function(event) {
            const $clickedTab = $(event.currentTarget);
            const tabName = $clickedTab.data("tab");
            
            // Update active states for tabs
            $(".p9-tab-btn").removeClass("active");
            $clickedTab.addClass("active");
            
            // Update active states for content
            $(".p9-tab-content").removeClass("active");
            $(`.p9-tab-content[data-content="${tabName}"]`).addClass("active");
            
            // Save active tab to localStorage for persistence
            localStorage.setItem("portcld9_active_tab", tabName);
            
            // Scroll the clicked tab into view (for mobile horizontal scroll)
            $clickedTab[0].scrollIntoView({
                behavior: "smooth",
                block: "nearest",
                inline: "center"
            });
            
            // Scroll page to top smoothly
            $("html, body").animate({ scrollTop: 0 }, 300);
        },
        
        /**
         * Handle form submission via AJAX
         * Validates form, shows loading state, submits data, handles response
         * 
         * @param {Event} event - Form submit event
         */
        handleSubmit: function(event) {
            event.preventDefault();
            
            // Validate before submitting
            if (!this.validateForm()) {
                return;
            }
            
            const originalButtonText = this.$saveBtn.text();
            
            // Show loading spinner
            this.$saveBtn.prop("disabled", true).html(`
                <svg class="p9-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" opacity="0.25"></circle>
                    <path d="M12 2a10 10 0 0 1 10 10" opacity="0.75"></path>
                </svg>
                <span>Saving...</span>
            `);
            
            const formData = this.$form.serialize();
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: "POST",
                data: formData,
                success: (response) => {
                    if (response.success) {
                        // Show success message
                        this.showNotice(
                            response.data.message || "Account updated successfully!", 
                            "success"
                        );
                        
                        // Clear password fields for security
                        this.$currentPassword.val("");
                        this.$newPassword.val("");
                        this.$confirmPassword.val("");
                        $("#p9-password-strength").hide();
                        
                        // Mark form as saved
                        this.formChanged = false;
                        this.saveFormState();
                        
                        // Update display name in header if changed
                        if (response.data.display_name) {
                            $(".p9-account-header-info h1").text(response.data.display_name);
                        }
                    } else {
                        // Show error message
                        this.showNotice(
                            response.data || "Failed to update account. Please try again.", 
                            "error"
                        );
                    }
                },
                error: (xhr, status, error) => {
                    console.error("Portal Cloud 9: Account update failed", {
                        status: xhr.status,
                        error: error,
                        response: xhr.responseText
                    });
                    
                    let errorMessage = "An error occurred. Please try again.";
                    
                    try {
                        const errorData = JSON.parse(xhr.responseText);
                        if (errorData.data) {
                            errorMessage = errorData.data;
                        }
                    } catch (parseError) {
                        if (xhr.responseText && xhr.status !== 0) {
                            errorMessage = "Server error. Please contact support if this persists.";
                        }
                    }
                    
                    this.showNotice(errorMessage, "error");
                },
                complete: () => {
                    // Restore button to original state
                    this.$saveBtn.prop("disabled", false).html(`
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        <span>${originalButtonText}</span>
                    `);
                }
            });
        },
        
        /**
         * Validate form data before submission
         * Checks required fields, email format, password requirements
         * 
         * @returns {boolean} True if form is valid, false otherwise
         */
        validateForm: function() {
            let isValid = true;
            const errors = [];
            
            // Validate display name
            if (!$("#p9-display-name").val().trim()) {
                errors.push("Display name is required");
                isValid = false;
            }
            
            // Validate email
            const email = $("#p9-email").val().trim();
            if (!email || !this.isValidEmail(email)) {
                errors.push("Valid email address is required");
                isValid = false;
            }
            
            // Validate password fields if any are filled
            const newPassword = this.$newPassword.val();
            const confirmPassword = this.$confirmPassword.val();
            const currentPassword = this.$currentPassword.val();
            
            if (newPassword || confirmPassword) {
                // Require current password to change password
                if (!currentPassword) {
                    errors.push("Current password is required to change password");
                    isValid = false;
                }
                
                // Check passwords match
                if (newPassword !== confirmPassword) {
                    errors.push("New passwords do not match");
                    isValid = false;
                }
                
                // Check minimum length
                if (newPassword.length < 8) {
                    errors.push("New password must be at least 8 characters long");
                    isValid = false;
                }
            }
            
            // Validate bio length
            const bio = $("#p9-bio").val();
            if (bio && bio.length > 500) {
                errors.push("Bio must not exceed 500 characters");
                isValid = false;
            }
            
            // Display errors if any
            if (!isValid) {
                this.showNotice(errors.join("<br>"), "error");
            }
            
            return isValid;
        },
        
        /**
         * Validate email format using regex
         * 
         * @param {string} email - Email address to validate
         * @returns {boolean} True if valid email format
         */
        isValidEmail: function(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },
        
        /**
         * Initialize password strength indicator
         * Creates the strength meter HTML if it doesn't exist
         */
        initPasswordStrength: function() {
            if (!$("#p9-password-strength").length) {
                const strengthHTML = `
                    <div class="p9-password-strength" id="p9-password-strength" style="display:none;">
                        <div class="p9-strength-bar">
                            <div class="p9-strength-fill"></div>
                        </div>
                        <span class="p9-strength-text"></span>
                    </div>
                `;
                this.$confirmPassword.closest(".p9-form-group").after(strengthHTML);
            }
        },
        
        /**
         * Check and display password strength as user types
         * Updates visual indicator based on password complexity
         */
        checkPasswordStrength: function() {
            const password = this.$newPassword.val();
            const strength = this.calculateStrength(password);
            const $strengthContainer = $("#p9-password-strength");
            const $strengthFill = $(".p9-strength-fill");
            const $strengthText = $(".p9-strength-text");
            
            if (password.length > 0) {
                // Show and update strength indicator
                $strengthContainer.show();
                $strengthFill
                    .css("width", strength.percent + "%")
                    .removeClass("weak medium strong")
                    .addClass(strength.class);
                $strengthText.text(strength.text);
            } else {
                // Hide when password is empty
                $strengthContainer.hide();
            }
        },
        
        /**
         * Calculate password strength based on various criteria
         * 
         * @param {string} password - Password to analyze
         * @returns {Object} Strength object with class, text, and percent
         */
        calculateStrength: function(password) {
            let score = 0;
            const missingRequirements = [];
            
            // Check length (25 points)
            if (password.length >= 8) {
                score += 25;
            } else {
                missingRequirements.push("8+ characters");
            }
            
            // Check lowercase (25 points)
            if (/[a-z]/.test(password)) {
                score += 25;
            } else {
                missingRequirements.push("lowercase letter");
            }
            
            // Check uppercase (25 points)
            if (/[A-Z]/.test(password)) {
                score += 25;
            } else {
                missingRequirements.push("uppercase letter");
            }
            
            // Check numbers (25 points)
            if (/[0-9]/.test(password)) {
                score += 25;
            } else {
                missingRequirements.push("number");
            }
            
            // Bonus for special characters (10 points)
            if (/[^A-Za-z0-9]/.test(password)) {
                score += 10;
            }
            
            // Determine strength level
            let result = {
                class: "weak",
                text: "Weak",
                percent: Math.min(score, 100)
            };
            
            if (score >= 70) {
                result.class = "strong";
                result.text = "Strong password";
            } else if (score >= 40) {
                result.class = "medium";
                result.text = "Medium password";
            } else if (missingRequirements.length > 0) {
                result.text = "Weak - Add: " + missingRequirements.join(", ");
            }
            
            return result;
        },
        
        /**
         * Validate that password and confirm password match
         * Sets custom validity message for browser validation
         */
        validatePasswordMatch: function() {
            const newPassword = this.$newPassword.val();
            const confirmPassword = this.$confirmPassword.val();
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.$confirmPassword[0].setCustomValidity("Passwords do not match");
            } else {
                this.$confirmPassword[0].setCustomValidity("");
            }
        },
        
        /**
         * Toggle password visibility between text and password type
         * Updates eye icon to reflect current state
         * 
         * @param {Event} event - Click event from toggle button
         */
        togglePasswordVisibility: function(event) {
            const $toggleButton = $(event.currentTarget);
            const targetInputId = $toggleButton.data("target");
            const $targetInput = $("#" + targetInputId);
            const $openEyeIcon = $toggleButton.find(".p9-eye-open");
            const $closedEyeIcon = $toggleButton.find(".p9-eye-closed");
            
            if ($targetInput.attr("type") === "password") {
                // Show password
                $targetInput.attr("type", "text");
                $openEyeIcon.hide();
                $closedEyeIcon.show();
            } else {
                // Hide password
                $targetInput.attr("type", "password");
                $openEyeIcon.show();
                $closedEyeIcon.hide();
            }
        },
        
        /**
         * Handle avatar/profile picture file selection
         * Validates file type and size before upload
         * 
         * @param {Event} event - Change event from file input
         */
        handleAvatarUpload: function(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            // Validate file type
            if (!file.type.match("image.*")) {
                this.showNotice("Please select a valid image file", "error");
                return;
            }
            
            // Validate file size (2MB maximum)
            if (file.size > 2 * 1024 * 1024) {
                this.showNotice("Image size must be less than 2MB", "error");
                return;
            }
            
            // Show preview
            const reader = new FileReader();
            reader.onload = (e) => {
                this.$avatarPreview.attr("src", e.target.result);
            };
            reader.readAsDataURL(file);
            
            // Upload to server
            this.uploadAvatar(file);
        },
        
        /**
         * Upload avatar to server via AJAX
         * Uses FormData for file upload
         * 
         * @param {File} file - Avatar image file to upload
         */
        uploadAvatar: function(file) {
            const formData = new FormData();
            formData.append("action", "portalcloud9_upload_avatar");
            formData.append("nonce", portalcloud9_ajax.nonce);
            formData.append("avatar", file);
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: "POST",
                data: formData,
                processData: false,  // Don't process the FormData
                contentType: false,  // Let browser set content type
                success: (response) => {
                    if (response.success) {
                        this.showNotice("Avatar updated successfully!", "success");
                        
                        // Update all avatar instances throughout the page
                        const newAvatarUrl = response.data.url;
                        $(".portalcloud9-topbar-avatar img, .portalcloud9-avatar-ring img").attr("src", newAvatarUrl);
                        $(".portalcloud9-user-info .portalcloud9-avatar img").attr("src", newAvatarUrl);
                        
                        // Replace Gravatar images in specific contexts
                        $('img[src*="gravatar"]').each(function() {
                            const $img = $(this);
                            if ($img.closest(".portalcloud9-topbar-avatar, .portalcloud9-user-info, .portalcloud9-sidebar-header").length) {
                                $img.attr("src", newAvatarUrl);
                            }
                        });
                    } else {
                        this.showNotice(response.data || "Failed to upload avatar", "error");
                    }
                },
                error: () => {
                    this.showNotice("An error occurred while uploading", "error");
                }
            });
        },
        
        /**
         * Toggle shipping fields visibility and sync with billing
         * When "Same as Billing" is checked, copies billing info to shipping
         */
        toggleShippingFields: function() {
            if (this.$sameAsBilling.is(":checked")) {
                // Copy billing address to shipping
                $("#p9-shipping-address-1").val($("#p9-billing-address-1").val());
                $("#p9-shipping-address-2").val($("#p9-billing-address-2").val());
                $("#p9-shipping-city").val($("#p9-billing-city").val());
                $("#p9-shipping-state").val($("#p9-billing-state").val());
                $("#p9-shipping-postcode").val($("#p9-billing-postcode").val());
                $("#p9-shipping-country").val($("#p9-billing-country").val());
                
                // Hide shipping fields
                this.$shippingFields.hide();
            } else {
                // Show shipping fields for separate address
                this.$shippingFields.show();
            }
        },
        
        /**
         * Reset form to original state
         * Reloads page after confirmation
         * 
         * @param {Event} event - Click event from reset button
         */
        resetForm: function(event) {
            event.preventDefault();
            
            if (confirm("Are you sure you want to reset all changes? This will reload the page.")) {
                location.reload();
            }
        },
        
        /**
         * Export user data as JSON file
         * Provides GDPR-compliant data export functionality
         * 
         * @param {Event} event - Click event from export button
         */
        exportData: function(event) {
            event.preventDefault();
            
            const $exportButton = $(event.currentTarget);
            const originalButtonText = $exportButton.text();
            
            // Show loading state
            $exportButton.prop("disabled", true).text("Exporting...");
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: "POST",
                data: {
                    action: "portalcloud9_export_user_data",
                    nonce: portalcloud9_ajax.nonce
                },
                success: (response) => {
                    if (response.success) {
                        // Create downloadable JSON file
                        const jsonString = JSON.stringify(response.data, null, 2);
                        const blob = new Blob([jsonString], { type: "application/json" });
                        const url = window.URL.createObjectURL(blob);
                        const downloadLink = document.createElement("a");
                        const date = new Date().toISOString().split("T")[0];
                        
                        downloadLink.href = url;
                        downloadLink.download = `portalcloud9-account-${date}.json`;
                        document.body.appendChild(downloadLink);
                        downloadLink.click();
                        document.body.removeChild(downloadLink);
                        window.URL.revokeObjectURL(url);
                        
                        this.showNotice("Data exported successfully!", "success");
                    } else {
                        this.showNotice("Failed to export data", "error");
                    }
                },
                error: () => {
                    this.showNotice("An error occurred during export", "error");
                },
                complete: () => {
                    // Restore button
                    $exportButton.prop("disabled", false).text(originalButtonText);
                }
            });
        },
        
        /**
         * Handle account deletion request
         * Requires multiple confirmations for safety
         * 
         * @param {Event} event - Click event from delete button
         */
        deleteAccount: function(event) {
            event.preventDefault();
            
            const displayName = $("#p9-display-name").val();
            
            // First confirmation
            if (!confirm("⚠️ WARNING: This will permanently delete your account and ALL data.\n\nThis action CANNOT be undone!\n\nAre you absolutely sure?")) {
                return;
            }
            
            // Require typing display name
            const userConfirmation = prompt(`To confirm deletion, please type your display name:\n"${displayName}"`);
            
            if (userConfirmation !== displayName) {
                this.showNotice("Account deletion cancelled - name did not match", "error");
                return;
            }
            
            // Final confirmation
            if (!confirm("This is your FINAL confirmation.\n\nClick OK to permanently delete your account.")) {
                return;
            }
            
            // Submit deletion request
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: "POST",
                data: {
                    action: "portalcloud9_delete_user_account",
                    nonce: portalcloud9_ajax.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice("Account deletion request submitted. An administrator will review your request.", "success");
                    } else {
                        this.showNotice(response.data || "Failed to process deletion request", "error");
                    }
                },
                error: () => {
                    this.showNotice("An error occurred", "error");
                }
            });
        },
        
        /**
         * Display notice message to user
         * Auto-dismisses after 5 seconds
         * 
         * @param {string} message - Message to display (can include HTML)
         * @param {string} type - Notice type: 'success' or 'error'
         */
        showNotice: function(message, type = "success") {
            const $notice = $(`
                <div class="p9-notice p9-${type}">
                    ${message}
                </div>
            `);
            
            this.$messagesContainer.append($notice);
            
            // Auto-remove after 5 seconds with fade effect
            setTimeout(() => {
                $notice.fadeOut(400, function() {
                    $(this).remove();
                });
            }, 5000);
        },
        
        /**
         * Track that form has been modified
         * Sets flag to warn user about unsaved changes
         */
        trackFormChanges: function() {
            this.formChanged = true;
        },
        
        /**
         * Prevent navigation if form has unsaved changes
         * Shows browser confirmation dialog
         * 
         * @param {Event} event - Beforeunload event
         * @returns {string|undefined} Warning message or undefined
         */
        preventNavigationIfChanged: function(event) {
            if (this.formChanged) {
                event.preventDefault();
                event.returnValue = "You have unsaved changes. Are you sure you want to leave?";
                return event.returnValue;
            }
        },
        
        /**
         * Save form state to localStorage
         * Preserves form data for recovery
         */
        saveFormState: function() {
            const formData = this.$form.serializeArray();
            localStorage.setItem("portcld9_account_form", JSON.stringify(formData));
        },
        
        /**
         * Restore previously active tab from localStorage
         * Maintains user's place when returning to page
         */
        restoreFormState: function() {
            const savedActiveTab = localStorage.getItem("portcld9_active_tab");
            if (savedActiveTab) {
                $(`.p9-tab-btn[data-tab="${savedActiveTab}"]`).click();
            }
        },
        
        /**
         * Initialize tab scroll detection
         * Adds class to wrapper if tabs are horizontally scrollable
         * Useful for showing scroll indicators on mobile
         */
        initTabScrollDetection: function() {
            const $tabsWrapper = $(".p9-account-tabs-wrapper");
            const $tabs = $(".p9-account-tabs");
            
            if (!$tabs.length) return;
            
            /**
             * Check if tabs are scrollable
             */
            const checkIfScrollable = () => {
                const hasScroll = $tabs[0].scrollWidth > $tabs[0].clientWidth;
                $tabsWrapper.toggleClass("has-scroll", hasScroll);
            };
            
            // Check on load and resize
            checkIfScrollable();
            $(window).on("resize", checkIfScrollable);
            
            // Scroll active tab into view on page load
            setTimeout(() => {
                const $activeTab = $(".p9-tab-btn.active");
                if ($activeTab.length) {
                    $activeTab[0].scrollIntoView({
                        behavior: "smooth",
                        block: "nearest",
                        inline: "center"
                    });
                }
            }, 100);
        }
    };
    
    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        if ($("#p9-account-form").length) {
            AccountManager.init();
        }
    });
    
    /**
     * Expose to global scope for debugging and external access
     */
    window.PortalCloud9Account = AccountManager;
    
})(jQuery);

/**
 * Add spinner animation styles
 * Used for loading states on buttons
 */
const spinnerStyles = `
    <style>
        @keyframes p9-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .p9-spinner {
            animation: p9-spin 1s linear infinite;
        }
    </style>
`;
document.head.insertAdjacentHTML("beforeend", spinnerStyles);
