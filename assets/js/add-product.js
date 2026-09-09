/**
 * ============================================================================
 * Portal Cloud 9 - Add Product Handler
 * ============================================================================
 *
 * Handles all add product form functionality including:
 * - Product image upload and gallery management
 * - Category and attribute selection
 * - Variable product and variation builder
 * - Pricing, stock and shipping field validation
 * - AJAX product submission and error handling
 * - Live character count for descriptions
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

    // Check if portalcloud9_ajax object is defined
    if (typeof portalcloud9_ajax === 'undefined') {
        console.error('portalcloud9_ajax object not defined');
        return;
    }

    const namespace = '.p9Add';
    const form = $('#p9-add-product-form');
    
    if (!form.length) return;

    let featuredImageId = 0;
    let galleryImageIds = [];

    // Event handlers for modal and navigation
    $(document)
        .off('click' + namespace, '#p9-add-new-product')
        .on('click' + namespace, '#p9-add-new-product', function() {
            $('#p9-product-success-modal').fadeOut();
        });

    $(document)
        .off('click' + namespace, '#p9-view-products')
        .on('click' + namespace, '#p9-view-products', function() {
            window.location.href = portalcloud9_ajax.products_url || '/user-portal/products/';
        });

    $(document)
        .off('click' + namespace, '.p9-modal-close')
        .on('click' + namespace, '.p9-modal-close', function() {
            $('#p9-product-success-modal').fadeOut();
        });

    // Hide checkbox inputs visually but keep them accessible
    $('<style>\n' +
      '    #p9-add-product-form input[type="checkbox"][name^="product_cat"],\n' +
      '    #p9-add-product-form input[type="checkbox"][name^="product_tag"] {\n' +
      '      position: absolute;\n' +
      '      left: -9999px;\n' +
      '      width: 0;\n' +
      '      height: 0;\n' +
      '    }\n' +
      '  </style>').appendTo('head');

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
                .on('blur' + namespace, function() {
                    syncEditor();
                    editor.toggleClass('empty', $.trim(editor.text()) === '');
                });
            
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
            
            // Initial render
            rebuildPills();
        }

        // Initialize featured image upload
        function initFeaturedImage() {
            const $fileInput = $('#p9-product-image');
            const $uploadTrigger = $('#p9-upload-trigger');
            const $progress = $('#p9-upload-progress');
            const $progressFill = $('#p9-progress-fill');
            const $progressText = $('#p9-progress-text');
            
            function updateProgress(percent) {
                $progress.show();
                $progressFill.css('width', percent + '%');
                $progressText.text(percent + '%');
            }
            
            function resetProgress() {
                $progress.hide();
                $progressFill.css('width', '0%');
                $progressText.text('0%');
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
                    formData.append('nonce', portalcloud9_ajax.nonce);
                    formData.append('file', file);
                    formData.append('image_context', 'featured'); // tells PHP the upload context
                    
                    updateProgress(0);
                    
                    let hasRealProgress = false;
                    let progressInterval = null;
                    
                    $.ajax({
                        url: portalcloud9_ajax.ajax_url,
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
                            
                            $('#p9-image-preview')
                                .show()
                                .find('img')
                                .attr('src', response.data.url);
                            
                            $('#p9-upload-area').hide();
                            $('#p9-image-id').val(featuredImageId);
                            
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
                        setTimeout(resetProgress, 350);
                    });
                });
            
            // Remove image
            $(document)
                .off('click' + namespace, '#p9-remove-image')
                .on('click' + namespace, '#p9-remove-image', function() {
                    featuredImageId = 0;
                    $('#p9-image-preview')
                        .hide()
                        .find('img')
                        .attr('src', '');
                    
                    $('#p9-upload-area').show();
                    $('#p9-image-id').val('');
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

        // Initialize gallery image upload
        function initGallery() {
            const $galleryInput = $('#p9-gallery-input');
            const $galleryTrigger = $('#p9-gallery-trigger');
            const $galleryPreview = $('#p9-gallery-preview');
            
            function updateGalleryIds() {
                $('#p9-gallery-ids').val(galleryImageIds.join(','));
                console.log('📸 Gallery IDs updated:', galleryImageIds);
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
                formData.append('nonce', portalcloud9_ajax.nonce);
                formData.append('file', file);
                formData.append('image_context', 'gallery'); // tells PHP the upload context
                
                $.ajax({
                    url: portalcloud9_ajax.ajax_url,
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
            $(document)
                .off('click' + namespace, '.p9-gallery-remove')
                .on('click' + namespace, '.p9-gallery-remove', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const imageId = parseInt($(this).attr('data-image-id'));
                    const $item = $(this).closest('.p9-gallery-item');
                    
                    console.log('🗑️ Removing gallery image ID:', imageId);
                    
                    galleryImageIds = galleryImageIds.filter(function(id) {
                        return id !== imageId;
                    });
                    
                    updateGalleryIds();
                    
                    $item.fadeOut(300, function() {
                        $(this).remove();
                        if (galleryImageIds.length === 0) {
                            $galleryPreview.hide();
                        }
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
                formData.append('action', 'portcld9_save_product');
                formData.append('nonce', portalcloud9_ajax.nonce);
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
                }
                
                const $buttons = $('#p9-publish-product, #p9-save-draft').prop('disabled', true);
                $buttons.filter('#p9-publish-product').text('Publishing…');
                $buttons.filter('#p9-save-draft').text('Saving…');
                
                $.ajax({
                    url: portalcloud9_ajax.ajax_url,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false
                })
                .done(function(response) {
                    $buttons
                        .prop('disabled', false)
                        .filter('#p9-publish-product').text('✅ Publish Product').end()
                        .filter('#p9-save-draft').text('📝 Save as Draft');
                    
                    if (response?.success) {
                        // Update success modal
                        $('#p9-success-message').text('Your product has been created successfully!');
                        $('#p9-view-new-product').attr('href', response.data.product_url);
                        $('#p9-edit-new-product').attr('href', response.data.edit_url);
                        
                        // Show success modal
                        $('#p9-product-success-modal').fadeIn();
                        
                        // Reset form
                        form[0].reset();
                        featuredImageId = 0;
                        galleryImageIds = [];
                        
                        // Reset UI
                        $('#p9-gallery-ids').val('');
                        $('#p9-image-preview').hide().find('img').attr('src', '');
                        $('#p9-upload-area').show();
                        $('#p9-image-id').val('');
                        $('#p9-gallery-preview').hide().empty();
                        $('#p9-product-image').val('');
                        
                        // Rebuild pills
                        rebuildPills();
                    } else {
                        alert(response.data || 'Failed to save product');
                    }
                })
                .fail(function() {
                    alert('Request failed - please check your connection');
                })
                .always(function() {
                    $buttons
                        .prop('disabled', false)
                        .filter('#p9-publish-product').text('✅ Publish Product').end()
                        .filter('#p9-save-draft').text('📝 Save as Draft');
                });
            }
            
            // Button click handlers
            $('#p9-publish-product, #p9-save-draft')
                .off(namespace)
                .on('click' + namespace, function(e) {
                    e.preventDefault();
                    const status = this.id === 'p9-publish-product' ? 'publish' : 'draft';
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
                
                $.post(portalcloud9_ajax.ajax_url, {
                    action: 'portcld9_add_category',
                    nonce: portalcloud9_ajax.nonce,
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
                
                $.post(portalcloud9_ajax.ajax_url, {
                    action: 'portcld9_create_tags',
                    nonce: portalcloud9_ajax.nonce,
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
        
        console.log('✅ Portal Cloud 9 Add Product initialized with gallery progress');
    });
});