/**
 * ============================================================================
 * Portal Cloud 9 - Edit Product Handler
 * ============================================================================
 *
 * Handles all edit product form functionality including:
 * - Pre-population of existing product data
 * - Image replacement and gallery management
 * - Category, attribute and variation editing
 * - Pricing, stock and shipping field updates
 * - AJAX product update submission and error handling
 * - Dirty-state detection to warn on unsaved changes
 *
 * Dependencies: jQuery
 *
 * @package Portal_Cloud_9
 * @version 8.6.0
 * @author  Brian Agoi (Gradyzer)
 * @company Gradyzer
 * @license GPL-2.0+
 * ============================================================================
 */

jQuery(function($) {
    'use strict';

    // Check if portalcloud9_edit_data object is defined
    if (typeof portalcloud9_edit_data === 'undefined') {
        console.error('portalcloud9_edit_data object not defined');
        return;
    }

    const namespace = '.p9Edit';
    const form = $('#p9-edit-product-form');
    
    if (!form.length) return;

    let featuredImageId = parseInt(portalcloud9_edit_data.image_id) || 0;
    let galleryImageIds = Array.isArray(portalcloud9_edit_data.gallery_ids) ? 
                         portalcloud9_edit_data.gallery_ids.map(id => parseInt(id)) : [];

    // Initialize featured image functionality
    function initFeaturedImage() {
        const $fileInput = $('#p9-product-image');
        const $uploadTrigger = $('#p9-upload-trigger');
        const $uploadArea = $('#p9-upload-area');
        const $imagePreview = $('#p9-image-preview');
        const $previewImage = $('#p9-preview-image');
        const $progress = $('#p9-upload-progress');
        const $progressFill = $('#p9-progress-fill');
        const $progressText = $('#p9-progress-text');
        
        function updateProgress(percent) {
            $progress.show();
            $progressFill.css('width', percent + '%');
            $progressText.text(percent + '%');
        }
        
        // Load existing image if available
        // Check both JavaScript data and actual DOM state
        const $previewImg = $('#p9-preview-image');
        const existingImgSrc = $.trim($previewImg.attr('src') || '');
        
        console.log('🔍 Checking featured image...');
        console.log('  - featuredImageId:', featuredImageId);
        console.log('  - image_url from data:', portalcloud9_edit_data.image_url);
        console.log('  - existingImgSrc from DOM:', existingImgSrc);
        
        const hasImage = (featuredImageId && portalcloud9_edit_data.image_url && portalcloud9_edit_data.image_url !== '') || 
                        (existingImgSrc && existingImgSrc !== '');
        
        if (hasImage) {
            // Image exists - ensure preview is shown
            if (portalcloud9_edit_data.image_url && portalcloud9_edit_data.image_url !== '') {
                $previewImage.attr('src', portalcloud9_edit_data.image_url);
                console.log('✅ Set image src to:', portalcloud9_edit_data.image_url);
            }
            
            // Force the preview to show
            $imagePreview.attr('style', '').css('display', 'block').show();
            $uploadArea.attr('style', '').css('display', 'none').hide();
            
            console.log('📷 Featured image preview SHOWN');
        } else {
            // No image - ensure upload area is visible
            $uploadArea.attr('style', '').css('display', 'block').show();
            $imagePreview.attr('style', '').css('display', 'none').hide();
            
            console.log('📷 No featured image - upload area SHOWN');
        }
        
        // Trigger file input
        $uploadTrigger
            .off(namespace)
            .on('click' + namespace, function(e) {
                e.preventDefault();
                $fileInput.trigger('click');
            });
        
        // File input change
        $fileInput
            .off(namespace)
            .on('change' + namespace, function(e) {
                const file = e.target.files?.[0];
                if (!file) return;
                
                // Validate file type
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPG, PNG, GIF, WebP)');
                    return;
                }
                
                // Validate file size (10MB)
                if (file.size > 10485760) {
                    alert('File size must be less than 10MB');
                    return;
                }
                
                // Prepare form data
                const formData = new FormData();
                formData.append('action', 'portcld9_upload_image');
                formData.append('nonce', portalcloud9_edit_data.nonce);
                formData.append('file', file);
                formData.append('image_context', 'featured'); // tells PHP the upload context
                
                updateProgress(0);
                
                let hasRealProgress = false;
                let progressInterval = null;
                
                $.ajax({
                    url: portalcloud9_edit_data.ajax_url,
                    method: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    xhr: function() {
                        const xhr = $.ajaxSettings.xhr();
                        if (xhr.upload) {
                            xhr.upload.addEventListener('progress', function(e) {
                                if (e.lengthComputable) {
                                    hasRealProgress = true;
                                    updateProgress(Math.round((e.loaded / e.total) * 100));
                                }
                            });
                        }
                        return xhr;
                    },
                    beforeSend: function() {
                        // Fake progress for visual feedback
                        progressInterval = setInterval(function() {
                            if (hasRealProgress) return;
                            const current = parseInt($progressText.text()) || 0;
                            if (current < 95) {
                                updateProgress(current + 3);
                            }
                        }, 120);
                    }
                })
                .done(function(response) {
                    if (response?.success) {
                        console.log('✅ Featured image uploaded:', response.data);
                        featuredImageId = response.data.id;
                        
                        $previewImage.attr('src', response.data.url);
                        $imagePreview.show();
                        $uploadArea.hide();
                        $('#p9-featured-image-id').val(featuredImageId);
                        
                        updateProgress(100);
                    } else {
                        alert(response.data || 'Upload failed');
                    }
                })
                .fail(function() {
                    alert('Upload error - please try again');
                })
                .always(function() {
                    clearInterval(progressInterval);
                    setTimeout(function() {
                        $progress.hide();
                        $progressFill.css('width', '0%');
                        $progressText.text('0%');
                    }, 350);
                });
            });
        
        // Remove image
        $(document)
            .off('click' + namespace, '#p9-remove-image')
            .on('click' + namespace, '#p9-remove-image', function(e) {
                e.preventDefault();
                featuredImageId = 0;
                $previewImage.attr('src', '');
                $imagePreview.hide();
                $uploadArea.show();
                $('#p9-featured-image-id').val('');
                $fileInput.val('');
            });
        
        // Drag and drop
        $uploadTrigger.parent()
            .off('dragover dragleave drop')
            .on('dragover', function(e) {
                e.preventDefault();
                $uploadTrigger.addClass('dragover');
            })
            .on('dragleave', function(e) {
                e.preventDefault();
                $uploadTrigger.removeClass('dragover');
            })
            .on('drop', function(e) {
                e.preventDefault();
                $uploadTrigger.removeClass('dragover');
                
                const files = e.originalEvent.dataTransfer.files;
                if (files.length) {
                    $fileInput[0].files = files;
                    $fileInput.trigger('change');
                }
            });
    }

    console.log('🔧 Edit Product initialized - Image ID:', featuredImageId, 'Gallery IDs:', galleryImageIds);
    console.log('📊 portalcloud9_edit_data:', portalcloud9_edit_data);

    // Navigation event handlers
    $(document)
        .off('click' + namespace, '#p9-view-products')
        .on('click' + namespace, '#p9-view-products', function() {
            window.location.href = portalcloud9_edit_data.products_url || '/user-portal/products/';
        });

    // Modal close handlers
    $(document)
        .off('click' + namespace, '.p9-modal-close, .p9-modal')
        .on('click' + namespace, '.p9-modal-close, .p9-modal', function(e) {
            if ($(e.target).is('.p9-modal-close') || $(e.target).is('.p9-modal')) {
                $('#p9-product-success-modal').fadeOut();
            }
        });

    // Hide checkbox inputs style
    if (!$('#p9-hide-checkboxes-style').length) {
        $('<style id="p9-hide-checkboxes-style">\n' +
          '      #p9-edit-product-form input[type="checkbox"][name^="product_cat"],\n' +
          '      #p9-edit-product-form input[type="checkbox"][name^="product_tag"] {\n' +
          '        position: absolute;\n' +
          '        left: -9999px;\n' +
          '        width: 0;\n' +
          '        height: 0;\n' +
          '        opacity: 0;\n' +
          '      }\n' +
          '    </style>').appendTo('head');
    }

    $(document).ready(function() {
        // Initialize rich text editor
        function initEditor() {
            const editor = $('#p9-description');
            const textarea = $('textarea[name="product_description"]');
            
            function syncEditor() {
                textarea.val(editor.html());
                editor.toggleClass('empty', $.trim(editor.text()) === '');
            }
            
            // Initialize empty editor if needed
            if ($.trim(editor.text()) === '') {
                editor.html('');
            }
            
            syncEditor();
            
            // Sync on input
            $(document)
                .off('input' + namespace, '#p9-description')
                .on('input' + namespace, '#p9-description', syncEditor);
            
            // Focus/blur handling
            editor
                .off('focus' + namespace)
                .on('focus' + namespace, function() {
                    editor.removeClass('empty');
                });
            
            editor
                .off('blur' + namespace)
                .on('blur' + namespace, syncEditor);
            
            // Toolbar buttons
            $(document)
                .off('click' + namespace, '.p9-editor-toolbar button')
                .on('click' + namespace, '.p9-editor-toolbar button', function(e) {
                    e.preventDefault();
                    const command = $(this).data('command');
                    
                    editor.focus();
                    
                    if (command === 'createLink') {
                        const url = prompt('Enter URL');
                        if (url) {
                            document.execCommand(command, false, url);
                        }
                    } else {
                        document.execCommand(command, false, null);
                    }
                    
                    syncEditor();
                });
        }

        // Initialize category/tag selection pills
        function initTermSelection() {
            const $selectedCategories = $('#p9-selected-categories');
            const $selectedTags = $('#p9-selected-tags');
            
            function createPillHtml(termName, termValue) {
                return `<span class="p9-term-pill" data-value="${termValue}">
                            <span class="p9-term-name">${termName}</span>
                            <button type="button" class="p9-term-remove" aria-label="Remove">×</button>
                        </span>`;
            }
            
            // Global function to rebuild pills
            window.rebuildPills = function() {
                const categoriesHtml = $('.p9-terms-list.p9-categories input:checked, .p9-categories.p9-terms-list input:checked')
                    .map(function() {
                        return createPillHtml($(this).siblings('span').text(), this.value);
                    })
                    .get()
                    .join('');
                
                const tagsHtml = $('.p9-terms-list.p9-tags input:checked, .p9-tags.p9-terms-list input:checked')
                    .map(function() {
                        return createPillHtml($(this).siblings('span').text(), this.value);
                    })
                    .get()
                    .join('');
                
                $selectedCategories.html(categoriesHtml);
                $selectedTags.html(tagsHtml);
            };
            
            // Initial render
            rebuildPills();
            
            // Checkbox change handling
            $(document)
                .off('change' + namespace, '.p9-terms-list input[type="checkbox"]')
                .on('change' + namespace, '.p9-terms-list input[type="checkbox"]', function() {
                    $(this).closest('.p9-term-item').toggleClass('term-selected', this.checked);
                    rebuildPills();
                });
            
            // Remove pill handling
            $(document)
                .off('click' + namespace, '.p9-term-remove')
                .on('click' + namespace, '.p9-term-remove', function(e) {
                    e.stopPropagation();
                    const value = $(this).closest('.p9-term-pill').data('value');
                    $(`.p9-terms-list input[value="${value}"]`)
                        .prop('checked', false)
                        .trigger('change');
                });
        }

        // Initialize gallery image upload
        function initGallery() {
            const $galleryInput = $('#p9-gallery-input');
            const $galleryTrigger = $('#p9-gallery-trigger');
            const $galleryPreview = $('#p9-gallery-preview');
            
            function updateGalleryIds() {
                const idsString = galleryImageIds.join(',');
                $('#p9-gallery-ids').val(idsString);
                console.log('📸 Gallery IDs updated in hidden input:', idsString);
                console.log('📸 Current gallery array:', galleryImageIds);
            }
            
            function uploadGalleryImage(file, index) {
                // Validate file type
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    alert('Please select valid image files (JPG, PNG, GIF, WebP)');
                    return;
                }
                
                // Validate file size
                if (file.size > 10485760) {
                    alert('Each file must be less than 10MB');
                    return;
                }
                
                // Create preview element
                const itemId = 'gallery-item-' + Date.now() + '-' + index;
                const $item = $(`
                    <div class="p9-gallery-item" id="${itemId}">
                        <img src="" alt="Gallery image">
                        <div class="p9-gallery-progress">
                            <div class="p9-gallery-progress-fill" style="width:0%"></div>
                        </div>
                        <button type="button" class="p9-gallery-remove" style="display:none">×</button>
                    </div>
                `);
                
                $galleryPreview.append($item).show();
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    $item.find('img').attr('src', e.target.result);
                };
                reader.readAsDataURL(file);
                
                // Prepare upload
                const formData = new FormData();
                formData.append('action', 'portcld9_upload_image');
                formData.append('nonce', portalcloud9_edit_data.nonce);
                formData.append('file', file);
                formData.append('image_context', 'gallery'); // tells PHP the upload context
                
                console.log('📤 Uploading gallery image:', file.name);
                
                $.ajax({
                    url: portalcloud9_edit_data.ajax_url,
                    method: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    xhr: function() {
                        const xhr = $.ajaxSettings.xhr();
                        if (xhr.upload) {
                            xhr.upload.addEventListener('progress', function(e) {
                                if (e.lengthComputable) {
                                    const percent = Math.round((e.loaded / e.total) * 100);
                                    $item.find('.p9-gallery-progress-fill').css('width', percent + '%');
                                    console.log(`📊 Gallery upload progress: ${percent}%`);
                                }
                            });
                        }
                        return xhr;
                    }
                })
                .done(function(response) {
                    if (response?.success) {
                        console.log('✅ Gallery image uploaded:', response.data);
                        galleryImageIds.push(response.data.id);
                        updateGalleryIds();
                        
                        $item.find('img').attr('src', response.data.url);
                        $item.attr('data-image-id', response.data.id);
                        $item.find('.p9-gallery-progress').fadeOut(300);
                        $item.find('.p9-gallery-remove')
                            .fadeIn()
                            .attr('data-image-id', response.data.id);
                    } else {
                        console.error('❌ Gallery upload failed:', response.data);
                        alert(response.data || 'Upload failed');
                        $item.remove();
                    }
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('❌ Gallery upload error:', errorThrown);
                    alert('Upload error - please try again');
                    $item.remove();
                });
            }
            
            // Trigger gallery upload
            $galleryTrigger
                .off(namespace)
                .on('click' + namespace, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (galleryImageIds.length >= 4) {
                        alert('Maximum 4 gallery images allowed');
                        return;
                    }
                    
                    $galleryInput[0].click();
                });
            
            // Gallery file input change
            $galleryInput
                .off(namespace)
                .on('change' + namespace, function(e) {
                    const files = Array.from(e.target.files || []);
                    if (!files.length) return;
                    
                    const remainingSlots = 4 - galleryImageIds.length;
                    if (files.length > remainingSlots) {
                        alert(`You can only add ${remainingSlots} more image(s). Maximum is 4 images.`);
                        return;
                    }
                    
                    files.forEach(function(file, index) {
                        uploadGalleryImage(file, index);
                    });
                    
                    $(this).val('');
                });
            
            // Remove gallery image
            $galleryPreview
                .off('click' + namespace, '.p9-gallery-remove')
                .on('click' + namespace, '.p9-gallery-remove', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    
                    const $button = $(this);
                    const imageId = parseInt($button.data('image-id'));
                    const $item = $button.closest('.p9-gallery-item');
                    
                    if (!imageId || isNaN(imageId)) {
                        console.error('❌ Invalid image ID for removal:', $button.attr('data-image-id'));
                        return;
                    }
                    
                    console.log('🗑️ Removing gallery image ID:', imageId);
                    console.log('📊 Gallery IDs before removal:', JSON.stringify(galleryImageIds));
                    
                    // Remove from array
                    const indexToRemove = galleryImageIds.indexOf(imageId);
                    if (indexToRemove > -1) {
                        galleryImageIds.splice(indexToRemove, 1);
                        console.log('✅ Removed from array at index:', indexToRemove);
                    } else {
                        console.warn('⚠️ Image ID not found in array:', imageId);
                    }
                    
                    console.log('📊 Gallery IDs after removal:', JSON.stringify(galleryImageIds));
                    
                    // Update hidden input
                    updateGalleryIds();
                    
                    // Remove DOM element with animation
                    $item.fadeOut(300, function() {
                        $(this).remove();
                        
                        // Hide gallery preview if no more images in the container
                        const remainingItems = $galleryPreview.find('.p9-gallery-item').length;
                        console.log('📊 Remaining gallery items:', remainingItems);
                        
                        if (remainingItems === 0) {
                            $galleryPreview.hide();
                            console.log('👁️ Gallery preview hidden (no items left)');
                        }
                        
                        console.log('✅ Gallery item removed from DOM');
                    });
                });
            
            // Gallery drag and drop
            $galleryTrigger.parent()
                .off('dragover dragleave drop')
                .on('dragover', function(e) {
                    e.preventDefault();
                    $galleryTrigger.addClass('dragover');
                })
                .on('dragleave', function(e) {
                    e.preventDefault();
                    $galleryTrigger.removeClass('dragover');
                })
                .on('drop', function(e) {
                    e.preventDefault();
                    $galleryTrigger.removeClass('dragover');
                    
                    if (galleryImageIds.length >= 4) {
                        alert('Maximum 4 gallery images allowed');
                        return;
                    }
                    
                    const files = Array.from(e.originalEvent.dataTransfer.files);
                    const remainingSlots = 4 - galleryImageIds.length;
                    
                    if (files.length > remainingSlots) {
                        alert(`You can only add ${remainingSlots} more image(s).`);
                        return;
                    }
                    
                    files.forEach(function(file, index) {
                        uploadGalleryImage(file, index);
                    });
                });
        }

        // Initialize form submission
        function initFormSubmission() {
            function submitForm(status) {
                const editor = $('#p9-description');
                $('textarea[name="product_description"]').val(editor.html());
                
                const formData = new FormData(form[0]);
                formData.append('action', 'portcld9_update_product');
                formData.append('nonce', portalcloud9_edit_data.nonce);
                // product_id is already in form from hidden input, don't append again
                formData.append('status', status);
                
                // Add selected categories
                const selectedCategories = [];
                $('.p9-terms-list.p9-categories input:checked, .p9-categories.p9-terms-list input:checked').each(function() {
                    selectedCategories.push(this.value);
                });
                
                // Add selected tags
                const selectedTags = [];
                $('.p9-terms-list.p9-tags input:checked, .p9-tags.p9-terms-list input:checked').each(function() {
                    selectedTags.push(this.value);
                });
                
                selectedCategories.forEach(function(catId) {
                    formData.append('product_cat[]', catId);
                });
                
                selectedTags.forEach(function(tagId) {
                    formData.append('product_tag[]', tagId);
                });
                
                // Add featured image if exists
                if (featuredImageId) {
                    formData.set('image_id', featuredImageId);
                }
                
                // Add gallery images if exist
                if (galleryImageIds.length > 0) {
                    formData.set('gallery_ids', galleryImageIds.join(','));
                } else {
                    formData.set('gallery_ids', '');
                }
                
                const $updateBtn = $('#p9-update-product');
                const $draftBtn = $('#p9-save-draft');
                
                $updateBtn.prop('disabled', true).text('Updating…');
                $draftBtn.prop('disabled', true).text('Saving…');
                
                $.ajax({
                    url: portalcloud9_edit_data.ajax_url,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false
                })
                .done(function(response) {
                    if (response?.success) {
                        $('#p9-success-message').text(
                            status === 'draft' ? 
                            'Product saved as draft successfully!' : 
                            'Your product has been updated successfully!'
                        );
                        
                        $('#p9-view-new-product').attr('href', response.data.product_url || '#');
                        $('#p9-edit-new-product').attr('href', response.data.edit_url || window.location.href);
                        $('#p9-product-success-modal').fadeIn();
                    } else {
                        alert(response.data || 'Failed to update product');
                    }
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Update failed:', jqXHR, textStatus, errorThrown);
                    console.log('Response:', jqXHR.responseText);
                    alert('Request failed - please check your connection and try again');
                })
                .always(function() {
                    $updateBtn.prop('disabled', false).text('✅ Update Product');
                    $draftBtn.prop('disabled', false).text('📝 Save as Draft');
                });
            }
            
            // Button click handlers
            $('#p9-update-product, #p9-save-draft')
                .off(namespace)
                .on('click' + namespace, function(e) {
                    e.preventDefault();
                    const status = this.id === 'p9-update-product' ? 'publish' : 'draft';
                    submitForm(status);
                });
            
            // Form submit handler
            form
                .off('submit' + namespace)
                .on('submit' + namespace, function(e) {
                    e.preventDefault();
                    submitForm('publish');
                });
        }

        // Initialize add category functionality
        $('#p9-add-category-btn')
            .off(namespace)
            .on('click' + namespace, function() {
                const categoryName = $('#p9-new-category-name').val().trim();
                
                if (!categoryName) return;
                
                $.post(portalcloud9_edit_data.ajax_url, {
                    action: 'portcld9_add_category',
                    nonce: portalcloud9_edit_data.nonce,
                    category_name: categoryName
                })
                .done(function(response) {
                    if (!response?.success) {
                        alert(response?.data || 'Failed to create category');
                        return;
                    }
                    
                    const category = response.data.category;
                    const $existingCheckbox = $(`.p9-terms-list.p9-categories input[value="${category.term_id}"]`);
                    
                    if ($existingCheckbox.length) {
                        $existingCheckbox
                            .prop('checked', true)
                            .trigger('change');
                    } else {
                        const $newItem = $(`
                            <label class="p9-term-item term-selected">
                                <input type="checkbox" name="product_cat[]" value="${category.term_id}" checked>
                                <span>${category.name}</span>
                            </label>
                        `);
                        
                        $('.p9-terms-list.p9-categories, .p9-categories.p9-terms-list').append($newItem);
                        $newItem.find('input').trigger('change');
                    }
                    
                    $('#p9-new-category-name').val('');
                });
            });

        // Initialize create tags functionality
        $('#p9-create-tags-btn')
            .off(namespace)
            .on('click' + namespace, function() {
                const tags = $('#p9-new-tags').val().trim();
                
                if (!tags) return;
                
                $.post(portalcloud9_edit_data.ajax_url, {
                    action: 'portcld9_create_tags',
                    nonce: portalcloud9_edit_data.nonce,
                    tags: tags
                })
                .done(function(response) {
                    if (!response?.success) {
                        alert(response?.data || 'Failed to create tags');
                        return;
                    }
                    
                    const $tagsContainer = $('.p9-terms-list.p9-tags, .p9-tags.p9-terms-list');
                    
                    response.data.tags.forEach(function(tag) {
                        const $existingCheckbox = $(`.p9-terms-list.p9-tags input[value="${tag.term_id}"]`);
                        
                        if ($existingCheckbox.length) {
                            $existingCheckbox
                                .prop('checked', true)
                                .trigger('change');
                        } else {
                            const $newItem = $(`
                                <label class="p9-term-item term-selected">
                                    <input type="checkbox" name="product_tag[]" value="${tag.term_id}" checked>
                                    <span>${tag.name}</span>
                                </label>
                            `);
                            
                            $tagsContainer.append($newItem);
                            $newItem.find('input').trigger('change');
                        }
                    });
                    
                    $('#p9-new-tags').val('');
                });
            });

        // Initialize all components
        initEditor();
        initTermSelection();
        initFeaturedImage();
        initGallery();
        initFormSubmission();
        
        console.log('✅ Portal Cloud 9 Edit Product initialized with gallery progress (700x700 WebP)');
    });
});