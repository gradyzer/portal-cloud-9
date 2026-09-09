
/* ============================================
   PURGE DATA SCRIPT
   ============================================ */
(function ($) {
                        var purgeNonce = (typeof portcld9_admin_data !== 'undefined') ? portcld9_admin_data.purge_nonce : '';

                        // Show/hide the "Delete All Data Now" row when toggle changes
                        $(document).on('change', 'input[data-key="remove_data_on_uninstall"]', function () {
                            if ($(this).is(':checked')) {
                                $('#pc9-purge-row').show();
                            } else {
                                $('#pc9-purge-row').hide();
                            }
                        });

                        // Open modal
                        $('#pc9-purge-btn').on('click', function () {
                            $('#pc9-purge-confirm-input').val('');
                            $('#pc9-purge-confirm').prop('disabled', true).removeClass('is-ready');
                            $('#pc9-purge-status').hide();
                            $('#pc9-purge-modal').addClass('is-open');
                        });

                        // Close modal
                        $('#pc9-purge-cancel, #pc9-purge-modal').on('click', function (e) {
                            if (e.target === this) {
                                $('#pc9-purge-modal').removeClass('is-open');
                            }
                        });

                        // Enable confirm button only when "DELETE" is typed exactly
                        $('#pc9-purge-confirm-input').on('input', function () {
                            var valid = $(this).val() === 'DELETE';
                            $('#pc9-purge-confirm')
                                .prop('disabled', !valid)
                                .toggleClass('is-ready', valid);
                        });

                        // Execute purge
                        $('#pc9-purge-confirm').on('click', function () {
                            var $btn = $(this);
                            $btn.prop('disabled', true).text('⏳ Deleting…');
                            $('#pc9-purge-cancel').prop('disabled', true);
                            $('#pc9-purge-confirm-input').prop('disabled', true);

                            $.ajax({
                                url:  ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'portcld9_purge_data',
                                    nonce:  purgeNonce,
                                },
                                success: function (res) {
                                    var $status = $('#pc9-purge-status');
                                    if (res.success) {
                                        $status.css({background:'#f0fdf4',border:'1px solid #86efac',color:'#166534'})
                                               .text('✓ All data deleted successfully. The plugin is still installed but has no stored data.')
                                               .show();
                                        // Reload the page after 3s so settings UI reflects clean state
                                        setTimeout(function () { location.reload(); }, 3000);
                                    } else {
                                        $status.css({background:'#fef2f2',border:'1px solid #fca5a5',color:'#dc2626'})
                                               .text('✗ Error: ' + (res.data || 'Deletion failed.'))
                                               .show();
                                        $btn.prop('disabled', false).text('🗑️ Yes, Delete Everything');
                                        $('#pc9-purge-cancel').prop('disabled', false);
                                        $('#pc9-purge-confirm-input').prop('disabled', false);
                                    }
                                },
                                error: function () {
                                    $('#pc9-purge-status').css({background:'#fef2f2',border:'1px solid #fca5a5',color:'#dc2626'})
                                                         .text('✗ Server error. Please try again.')
                                                         .show();
                                    $btn.prop('disabled', false).text('🗑️ Yes, Delete Everything');
                                    $('#pc9-purge-cancel').prop('disabled', false);
                                    $('#pc9-purge-confirm-input').prop('disabled', false);
                                }
                            });
                        });
                    }(jQuery));

/* ============================================
   CLEAR CACHE SCRIPT
   ============================================ */
function pc9ClearCache() {
            if (!confirm('This will flush WordPress rewrite rules and clear Portal Cloud 9 caches. Continue?')) {
                return;
            }
            
            var btn = document.getElementById('pc9-clear-cache-btn');
            var originalText = btn.innerHTML;
            btn.innerHTML = '⏳ Clearing...';
            btn.disabled = true;
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'portcld9_clear_cache',
                    nonce: (typeof portcld9_admin_data !== 'undefined') ? portcld9_admin_data.clear_cache_nonce : ''
                },
                success: function(response) {
                    if (response.success) {
                        btn.innerHTML = '✓ Cache Cleared!';
                        setTimeout(function() {
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        }, 2000);
                    } else {
                        alert('Error: ' + (response.data || 'Failed to clear cache'));
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                },
                error: function() {
                    alert('Error clearing cache. Please try again.');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });
        }

/* ============================================
   TOGGLE OPTION SCRIPT
   ============================================ */
        (function ($) {
            var nonce = (typeof portcld9_admin_data !== 'undefined') ? portcld9_admin_data.toggle_nonce : '';
            var saving = {};   // debounce map: key → timeout id

            $(document).on('change', 'input[data-ajax-toggle="1"]', function () {
                var $cb  = $(this);
                var key  = $cb.data('key');
                var val  = $cb.is(':checked') ? 1 : 0;
                var $row = $cb.closest('tr');

                // Cancel any in-flight debounce for this key
                if (saving[key]) {
                    clearTimeout(saving[key]);
                }

                // Show spinner immediately
                var $indicator = $row.find('.pc9-toggle-indicator');
                if (!$indicator.length) {
                    $indicator = $('<span class="pc9-toggle-indicator"></span>').appendTo($row.find('td'));
                }
                $indicator.attr('data-state', 'saving').text(' ⏳ Saving…');

                saving[key] = setTimeout(function () {
                    $.ajax({
                        url:  ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'portcld9_toggle_option',
                            nonce:  nonce,
                            key:    key,
                            value:  val,
                        },
                        success: function (res) {
                            if (res.success) {
                                $indicator.attr('data-state', 'saved').text(' ✓ Saved');
                            } else {
                                $indicator.attr('data-state', 'error').text(' ✗ Error — reverting');
                                // Revert the checkbox on failure
                                $cb.prop('checked', !val);
                            }
                        },
                        error: function () {
                            $indicator.attr('data-state', 'error').text(' ✗ Error — reverting');
                            $cb.prop('checked', !val);
                        },
                        complete: function () {
                            setTimeout(function () {
                                $indicator.attr('data-state', '').text('');
                            }, 2500);
                            delete saving[key];
                        }
                    });
                }, 250); // 250 ms debounce
            });
        }(jQuery));

