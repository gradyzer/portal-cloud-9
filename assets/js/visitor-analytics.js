/**
 * Portal Cloud 9 – Visitor Analytics (Free Edition)
 * Displays: Today, Last 7 Days, Last 30 Days, Last 365 Days, Online now.
 */
(function ($) {
    'use strict';

    var VA = {

        init: function () {
            VA.loadStats();
            VA.loadOnline();
            // Refresh online count every 30 seconds
            setInterval( VA.loadOnline, 30000 );
        },

        loadStats: function () {
            $.ajax({
                url:  portcld9_va.ajax_url,
                type: 'POST',
                data: { action: 'portcld9_visitor_stats', nonce: portcld9_va.nonce },
                success: function ( res ) {
                    if ( ! res.success ) return;
                    var d = res.data;
                    $( '#p9-va-val-today' ).text( d.today  !== undefined ? d.today  : '—' );
                    $( '#p9-va-val-week'  ).text( d.week   !== undefined ? d.week   : '—' );
                    $( '#p9-va-val-month' ).text( d.month  !== undefined ? d.month  : '—' );
                    $( '#p9-va-val-year'  ).text( d.year   !== undefined ? d.year   : '—' );
                }
            });
        },

        loadOnline: function () {
            $.ajax({
                url:  portcld9_va.ajax_url,
                type: 'POST',
                data: { action: 'portcld9_visitor_online', nonce: portcld9_va.nonce },
                success: function ( res ) {
                    if ( ! res.success ) return;
                    var count = res.data.online !== undefined ? res.data.online : '—';
                    $( '#p9-va-val-online' ).text( count );
                }
            });
        }
    };

    $( document ).ready( function () {
        if ( $( '#p9-va-wrap' ).length ) {
            VA.init();
        }
    });

}(jQuery));
