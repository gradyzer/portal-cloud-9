/**
 * ============================================================================
 * Portal Cloud 9 - Import and Export Products
 * ============================================================================
 *
 * Handles product import and export functionality including:
 * - Export products to CSV and Excel (XLSX) formats
 * - Import products from CSV with field mapping and validation
 * - Progress indicators and error reporting during import
 * - Duplicate detection and merge/skip options
 * - Column header mapping for flexible CSV formats
 *
 * Dependencies: jQuery
 *
 * @package Portal_Cloud_9
 * @version 8.6.1
 * @author  Brian Agoi (Gradyzer)
 * @company Gradyzer
 * @license GPL-2.0+
 * ============================================================================
 */

(function($) {
    'use strict';

    // Wait for DOM ready
    $(document).ready(function() {
        
        // Auto-correct AJAX URL if theme uses custom ajax.php
        const ajaxUrl = portalcloud9_ajax.ajax_url;
        
        const nonce = portalcloud9_ajax.nonce;

        /**
         * Export Products - Show Format Selection Modal
         */
        $('#p9-export-btn').on('click', function() {
            // Show export modal
            $('#p9-export-format-modal').fadeIn(300);
        });

        /**
         * Handle Export Format Selection
         */
        $('.p9-export-format-option').on('click', function() {
            const format = $(this).data('format');
            $('#p9-export-format-modal').fadeOut(300);
            exportProducts(format);
        });

        /**
         * Export Products with Selected Format
         */
        function exportProducts(format) {
            const $btn = $('#p9-export-btn');
            const originalText = $btn.html();

            // Disable button and show loading
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Exporting...');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'portcld9_export_products',
                    nonce: nonce,
                    format: format
                },
                success: function(response) {
                    if (response.success) {
                        // Determine MIME type and content
                        let blob;
                        if (response.data.format === 'xlsx') {
                            // Decode base64 for XLSX
                            const binaryString = window.atob(response.data.content);
                            const bytes = new Uint8Array(binaryString.length);
                            for (let i = 0; i < binaryString.length; i++) {
                                bytes[i] = binaryString.charCodeAt(i);
                            }
                            blob = new Blob([bytes], { type: response.data.mime_type });
                        } else {
                            // CSV - use text content directly
                            blob = new Blob([response.data.content], { type: response.data.mime_type + ';charset=utf-8;' });
                        }

                        // Create download link
                        const link = document.createElement('a');
                        const url = URL.createObjectURL(blob);
                        
                        link.setAttribute('href', url);
                        link.setAttribute('download', response.data.filename);
                        link.style.visibility = 'hidden';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);

                        // Show success message
                        showNotification('Success! ' + response.data.count + ' products exported as ' + format.toUpperCase() + '.', 'success');
                    } else {
                        showNotification('Error: ' + response.data, 'error');
                    }
                },
                error: function() {
                    showNotification('An error occurred while exporting products.', 'error');
                },
                complete: function() {
                    // Re-enable button
                    $btn.prop('disabled', false).html(originalText);
                }
            });
        }

        /**
         * Import Products - Show Format Selection Modal
         */
        $('#p9-import-btn').on('click', function() {
            // Show import modal
            $('#p9-import-format-modal').fadeIn(300);
        });

        /**
         * Handle Import Format Selection
         */
        $('.p9-import-format-option').on('click', function() {
            const format = $(this).data('format');
            $('#p9-import-format-modal').fadeOut(300);
            
            // Set accept attribute based on format
            if (format === 'xlsx') {
                $('#p9-import-file-input').attr('accept', '.xlsx');
            } else {
                $('#p9-import-file-input').attr('accept', '.csv,.txt');
            }
            
            // Store selected format
            $('#p9-import-file-input').data('format', format);
            
            // Trigger file picker
            $('#p9-import-file-input').click();
        });

        /**
         * Handle File Selection
         */
        $('#p9-import-file-input').on('change', function(e) {
            const file = e.target.files[0];
            const selectedFormat = $(this).data('format') || 'csv';
            
            if (!file) {
                return;
            }

            // Validate file type based on selected format
            const fileExtension = file.name.split('.').pop().toLowerCase();
            
            if (selectedFormat === 'xlsx') {
                if (fileExtension !== 'xlsx') {
                    showNotification('Please select an Excel (.xlsx) file.', 'error');
                    $(this).val('');
                    return;
                }
            } else {
                const allowedTypes = ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'];
                if (!allowedTypes.includes(file.type) && fileExtension !== 'csv') {
                    showNotification('Please select a CSV file.', 'error');
                    $(this).val('');
                    return;
                }
            }

            // Confirm import
            const formatName = selectedFormat.toUpperCase();
            if (!confirm('Import products from ' + file.name + ' (' + formatName + ')?\n\nExisting products with matching IDs will be updated.\nNew products will be created as drafts.')) {
                $(this).val('');
                return;
            }

            // Upload and import
            importProducts(file);

            // Clear input for next use
            $(this).val('');
        });

        /**
         * Import Products via AJAX
         */
        function importProducts(file) {
            const $importBtn = $('#p9-import-btn');
            const originalText = $importBtn.html();

            // Show loading state
            $importBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Importing...');

            // Create FormData
            const formData = new FormData();
            formData.append('action', 'portcld9_import_products');
            formData.append('nonce', nonce);
            formData.append('file', file);

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        let message = 'Import completed!\n\n';
                        message += 'New products: ' + response.data.imported + '\n';
                        message += 'Updated products: ' + response.data.updated + '\n';
                        message += 'Total processed: ' + response.data.total;

                        if (response.data.errors && response.data.errors.length > 0) {
                            message += '\n\nErrors (' + response.data.errors.length + '):';
                            response.data.errors.slice(0, 5).forEach(function(error) {
                                message += '\n- ' + error;
                            });
                            if (response.data.errors.length > 5) {
                                message += '\n... and ' + (response.data.errors.length - 5) + ' more errors';
                            }
                        }

                        alert(message);

                        // Reload products list
                        if (typeof loadProducts === 'function') {
                            loadProducts(1);
                        } else {
                            location.reload();
                        }
                    } else {
                        showNotification('Import failed: ' + response.data, 'error');
                    }
                },
                error: function() {
                    showNotification('An error occurred while importing products.', 'error');
                },
                complete: function() {
                    // Re-enable button
                    $importBtn.prop('disabled', false).html(originalText);
                }
            });
        }

        /**
         * Show Notification
         */
        function showNotification(message, type) {
            // Check if notification container exists
            let $notification = $('#p9-notification');
            
            if ($notification.length === 0) {
                // Create notification container
                $notification = $('<div id="p9-notification" class="p9-notification"></div>');
                $('body').append($notification);
            }

            // Set message and type
            $notification
                .removeClass('success error')
                .addClass(type)
                .text(message)
                .fadeIn(300);

            // Auto-hide after 5 seconds
            setTimeout(function() {
                $notification.fadeOut(300);
            }, 5000);
        }

        /**
         * Download Sample CSV Template
         */
        $(document).on('click', '#p9-download-template', function(e) {
            e.preventDefault();
            
            // Sample CSV template
            const csvContent = 'ID,Title,Description,Short Description,SKU,Regular Price,Sale Price,Stock Status,Stock Quantity,Categories,Tags,Image URL,Status,Featured,Manage Stock,Sold Individually\n' +
                ',"Sample Product","This is a sample product description","Short description here","SAMPLE-001",29.99,24.99,instock,100,"Category 1|Category 2","Tag 1|Tag 2","https://example.com/image.jpg",publish,no,yes,no\n';

            // Create download
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'products-import-template.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            showNotification('Template downloaded! Use this as a guide for your import file.', 'success');
        });

        /**
         * Close modals on background click or close button
         */
        $('.p9-modal-close, .p9-format-modal').on('click', function(e) {
            if (e.target === this) {
                $('.p9-format-modal').fadeOut(300);
            }
        });

    });

})(jQuery);
