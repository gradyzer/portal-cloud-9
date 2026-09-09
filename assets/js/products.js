/**
 * ============================================================================
 * Portal Cloud 9 - Products Tab
 * ============================================================================
 * Single consolidated script. Renders list/grid view from AJAX data.
 *
 * AJAX response fields: id, title, image, regular_price_html, sale_price_html,
 *                       status, category_name, stock_class, stock_text, sku
 *
 * @package Portal_Cloud_9
 * @version 8.6.0
 * @author  Brian Agoi (Gradyzer)
 * @license GPL-2.0+
 * ============================================================================
 */
jQuery(function ($) {

    'use strict';

    /* ── Guard ────────────────────────────────────────────────────────────── */
    if (!$('#p9-products-container').length) return;

    /* ── State ────────────────────────────────────────────────────────────── */
    const ajaxUrl   = portalcloud9_ajax.ajax_url;
    const nonce     = portalcloud9_ajax.nonce;
    const perPage   = parseInt($('.p9-products-wrap').data('per-page')) || 15;
    // Grid view removed — list only.
    let   currentPage = 1;
    let   selectedIds = new Set();

    /* ══════════════════════════════════════════════════════════════════════
       LOAD
    ══════════════════════════════════════════════════════════════════════ */
    function loadProducts(page) {
        page = page || 1;
        currentPage = page;
        $('#p9-loading').show();
        $.post(ajaxUrl, {
            action   : 'portcld9_load_products',
            nonce    : nonce,
            page     : page,
            per_page : perPage,
            search   : $('#p9-search-input').val().trim(),
            category : $('#p9-cat-filter').val(),
            status   : $('#p9-status-filter').val(),
            stock    : $('#p9-stock-filter').val()
        }, function (res) {
            $('#p9-loading').hide();
            if (!res.success) {
                $('#p9-products-container').html(
                    '<p class="p9-load-error">Could not load products. Please refresh.</p>'
                );
                return;
            }
            selectedIds.clear();
            renderCards(res.data.products);
            renderPagination(res.data.pagination);
            updateBulkBar();
        }, 'json');
    }

    /* ══════════════════════════════════════════════════════════════════════
       RENDER — LIST VIEW card (matches Image 1 desktop / Image 2 mobile)
       Structure: [checkbox] [80x80 image] [info: title + prices + meta] [actions]
    ══════════════════════════════════════════════════════════════════════ */
    function cardListHTML(p) {
        var onSale       = !!p.sale_price_html;
        var activePrice  = onSale ? p.sale_price_html : p.regular_price_html;
        var oldPrice     = onSale
            ? '<span class="p9-product-price-old">' + p.regular_price_html + '</span>'
            : '';
        var editUrl      = '/user-portal/edit-product/' + p.id + '/';

        return '<div class="p9-product-card" data-id="' + p.id + '">' +

            /* checkbox */
            '<div class="p9-product-checkbox-wrapper">' +
                '<input type="checkbox" class="p9-product-checkbox" value="' + p.id + '">' +
            '</div>' +

            /* thumbnail */
            '<div class="p9-product-image">' +
                '<img src="' + p.image + '" alt="" loading="lazy">' +
            '</div>' +

            /* info block */
            '<div class="p9-product-info">' +
                '<h3 class="p9-product-title">' + p.title + '</h3>' +
                '<div class="p9-product-pricing">' +
                    oldPrice +
                    '<span class="p9-product-price">' + activePrice + '</span>' +
                '</div>' +
                '<div class="p9-product-meta">' +
                    '<span>' + (p.category_name || 'Uncategorized') + '</span>' +
                    '<span>' + p.stock_text + '</span>' +
                    '<span>SKU: ' + (p.sku || '\u2014') + '</span>' +
                '</div>' +
            '</div>' +

            /* actions */
            '<div class="p9-product-actions">' +
                '<a href="' + editUrl + '" class="p9-btn-action p9-btn-edit">Edit</a>' +
                '<button class="p9-btn-action p9-btn-delete" data-id="' + p.id + '">Delete</button>' +
            '</div>' +

        '</div>';
    }

    
    /* ══════════════════════════════════════════════════════════════════════
       RENDER — write to DOM
    ══════════════════════════════════════════════════════════════════════ */
    function renderCards(list) {
        var $c = $('#p9-products-container');

        if (!list || list.length === 0) {
            $c.addClass('list')
              .html('<p class="p9-no-products">No products found.</p>');
            return;
        }

        var html = '';
        list.forEach(function (p) {
            html += cardListHTML(p);
        });

        $c.addClass('list').html(html);
    }

    /* ══════════════════════════════════════════════════════════════════════
       PAGINATION
    ══════════════════════════════════════════════════════════════════════ */
    function renderPagination(pg) {
        var $pag = $('#p9-pagination');
        if (!pg || pg.total_pages <= 1) { $pag.html(''); return; }

        var nav = '<div class="p9-info">Page ' + pg.current_page + ' of ' + pg.total_pages + '</div>' +
                  '<div class="p9-pages">';

        if (pg.current_page > 1) {
            nav += '<button class="p9-page" data-page="' + (pg.current_page - 1) + '">&larr;</button>';
        }
        for (var i = 1; i <= pg.total_pages; i++) {
            nav += '<button class="p9-page' + (i === pg.current_page ? ' active' : '') +
                   '" data-page="' + i + '">' + i + '</button>';
        }
        if (pg.current_page < pg.total_pages) {
            nav += '<button class="p9-page" data-page="' + (pg.current_page + 1) + '">&rarr;</button>';
        }
        nav += '</div>';
        $pag.html(nav);
    }

    /* ══════════════════════════════════════════════════════════════════════
       BULK ACTION BAR
    ══════════════════════════════════════════════════════════════════════ */
    function updateBulkBar() {
        var count    = selectedIds.size;
        var total    = $('.p9-product-checkbox:not(#p9-select-all)').length;
        var checked  = $('.p9-product-checkbox:not(#p9-select-all):checked').length;

        $('#p9-selected-count').text(count);

        if (count > 0) {
            $('#p9-bulk-actions').addClass('active');
        } else {
            $('#p9-bulk-actions').removeClass('active');
        }

        if (checked === 0) {
            $('#p9-select-all').prop('checked', false).prop('indeterminate', false);
        } else if (checked === total) {
            $('#p9-select-all').prop('checked', true).prop('indeterminate', false);
        } else {
            $('#p9-select-all').prop('checked', false).prop('indeterminate', true);
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       HELPERS
    ══════════════════════════════════════════════════════════════════════ */
    function debounce(fn, d) {
        var t;
        d = d || 300;
        return function () {
            var args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(this, args); }, d);
        };
    }

    function bulkUpdateStatus(status) {
        $.post(ajaxUrl, {
            action      : 'portcld9_bulk_update_status',
            nonce       : nonce,
            product_ids : Array.from(selectedIds),
            status      : status
        }, function (res) {
            if (res.success) { loadProducts(currentPage); }
            else { alert(res.data || 'Failed to update products'); }
        }, 'json');
    }

    function bulkDelete() {
        $.post(ajaxUrl, {
            action      : 'portcld9_bulk_delete_products',
            nonce       : nonce,
            product_ids : Array.from(selectedIds)
        }, function (res) {
            if (res.success) { loadProducts(currentPage); }
            else { alert(res.data || 'Failed to delete products'); }
        }, 'json');
    }

    /* ══════════════════════════════════════════════════════════════════════
       EVENT HANDLERS
    ══════════════════════════════════════════════════════════════════════ */

    /* Select All */
    $('#p9-select-all').on('change', function () {
        var checked = $(this).is(':checked');
        $('.p9-product-checkbox:not(#p9-select-all)').each(function () {
            var id = parseInt($(this).val());
            $(this).prop('checked', checked);
            if (checked) { selectedIds.add(id); }
            else         { selectedIds.delete(id); }
        });
        updateBulkBar();
    });

    /* Individual checkbox */
    $(document).on('change', '.p9-product-checkbox', function () {
        var id = parseInt($(this).val());
        if ($(this).is(':checked')) { selectedIds.add(id); }
        else                        { selectedIds.delete(id); }
        updateBulkBar();
    });

    /* Bulk publish */
    $('#p9-bulk-publish').on('click', function () {
        if (selectedIds.size === 0) return;
        if (!confirm('Publish ' + selectedIds.size + ' product(s)?')) return;
        bulkUpdateStatus('publish');
    });

    /* Bulk draft */
    $('#p9-bulk-draft').on('click', function () {
        if (selectedIds.size === 0) return;
        if (!confirm('Set ' + selectedIds.size + ' product(s) to draft?')) return;
        bulkUpdateStatus('draft');
    });

    /* Bulk delete */
    $('#p9-bulk-delete').on('click', function () {
        if (selectedIds.size === 0) return;
        if (!confirm('Delete ' + selectedIds.size + ' product(s)? This cannot be undone.')) return;
        bulkDelete();
    });

    /* Single delete */
    $(document).on('click', '.p9-btn-delete', function () {
        var id = $(this).data('id');
        if (!confirm('Delete this product?')) return;
        $.post(ajaxUrl, {
            action     : 'portcld9_delete_product',
            nonce      : nonce,
            product_id : id
        }, function (res) {
            if (res.success) { loadProducts(currentPage); }
        }, 'json');
    });

    /* Filters */
    $('#p9-apply-filters').on('click', function () { loadProducts(1); });
    $('#p9-search-input').on('input', debounce(function () { loadProducts(1); }, 300));

    /* Pagination */
    $(document).on('click', '.p9-page', function () {
        loadProducts($(this).data('page'));
    });

    /* Export */
    $('#p9-export-btn').on('click', function () {
        $('#p9-export-format-modal').show();
    });

    /* Import */
    $('#p9-import-btn').on('click', function () {
        $('#p9-import-format-modal').show();
    });

    /* Modal close */
    $(document).on('click', '.p9-modal-close', function () {
        $(this).closest('.p9-format-modal').hide();
    });

    /* Export format choice */
    $(document).on('click', '.p9-export-format-option', function () {
        var fmt = $(this).data('format');
        $('#p9-export-format-modal').hide();

        $.ajax({
            url  : ajaxUrl,
            type : 'POST',
            data : { action: 'portcld9_export_products', nonce: nonce, format: fmt },
            success : function (res) {
                if (!res.success) {
                    alert(res.data || 'Export failed.');
                    return;
                }
                var d        = res.data;
                var filename = d.filename;
                var mime     = d.mime_type;
                var content  = d.content;
                var blob;

                if (fmt === 'xlsx') {
                    /* XLSX is base64-encoded binary */
                    var binary = atob(content);
                    var bytes  = new Uint8Array(binary.length);
                    for (var i = 0; i < binary.length; i++) {
                        bytes[i] = binary.charCodeAt(i);
                    }
                    blob = new Blob([bytes], { type: mime });
                } else {
                    /* CSV is plain text */
                    blob = new Blob([content], { type: mime });
                }

                var url = URL.createObjectURL(blob);
                var a   = document.createElement('a');
                a.href     = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            },
            error : function () {
                alert('Export failed. Please try again.');
            }
        });
    });

    /* Import format choice → trigger file input */
    $(document).on('click', '.p9-import-format-option', function () {
        var fmt = $(this).data('format');
        $('#p9-import-format-modal').hide();
        $('#p9-import-file-input').data('format', fmt).trigger('click');
    });

    /* Import file selected */
    $('#p9-import-file-input').on('change', function () {
        var file = this.files[0];
        if (!file) return;
        var fmt  = $(this).data('format') || 'csv';
        var fd   = new FormData();
        fd.append('action', 'portcld9_import_products');
        fd.append('nonce', nonce);
        fd.append('format', fmt);
        fd.append('file', file);
        $.ajax({
            url         : ajaxUrl,
            type        : 'POST',
            data        : fd,
            processData : false,
            contentType : false,
            success     : function (res) {
                if (res.success) { loadProducts(1); }
                else { alert(res.data || 'Import failed'); }
            }
        });
        this.value = '';
    });

    /* ══════════════════════════════════════════════════════════════════════
       INIT
    ══════════════════════════════════════════════════════════════════════ */
    loadProducts(1);

});
