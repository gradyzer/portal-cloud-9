/**
 * ============================================================================
 * Portal Cloud 9 - Visitor Presence Heartbeat
 * ============================================================================
 *
 * Sends a lightweight ping every 20 seconds so the server knows
 * this visitor is still active on the site. Loaded on all front-end
 * pages. Written in plain JavaScript with no jQuery dependency for
 * minimum overhead and earliest possible execution.
 *
 * Responsibilities:
 * - Periodic heartbeat AJAX ping to update visitor last-seen timestamp
 * - Page URL and referrer tracking per ping
 * - Graceful degradation when the server is unavailable
 *
 * Dependencies: None (vanilla JS)
 *
 * @package Portal_Cloud_9
 * @version 8.6.0
 * @author  Brian Agoi (Gradyzer)
 * @company Gradyzer
 * @license GPL-2.0+
 * ============================================================================
 */

(function () {
    'use strict';

    var cfg      = window.portcld9_presence || {};
    var ajaxUrl  = cfg.ajax_url  || '';
    var token    = cfg.token     || '';           // static HMAC token (cache-safe)
    var interval = (cfg.interval || 20) * 1000;  // ms
    var timer    = null;

    // Nothing to do if localisation didn't load
    if ( !ajaxUrl || !token ) {
        return;
    }

    /* ------------------------------------------------------------------ */
    /*  Build POST body                                                     */
    /* ------------------------------------------------------------------ */

    function makeBody() {
        return 'action=portcld9_heartbeat&token=' + encodeURIComponent( token );
    }

    /* ------------------------------------------------------------------ */
    /*  Send one ping                                                       */
    /* ------------------------------------------------------------------ */

    function ping() {
        // sendBeacon: fires even during page unload, non-blocking
        if ( navigator && navigator.sendBeacon ) {
            var fd = new FormData();
            fd.append( 'action', 'portcld9_heartbeat' );
            fd.append( 'token',  token );
            navigator.sendBeacon( ajaxUrl, fd );
            return;
        }

        // Fallback: XMLHttpRequest
        try {
            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', ajaxUrl, true );
            xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
            xhr.send( makeBody() );
        } catch (e) {
            // Silently ignore – analytics shouldn't break the site
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Start / stop helpers                                                */
    /* ------------------------------------------------------------------ */

    function start() {
        if ( timer ) { return; }  // already running
        ping();                   // immediate first ping
        timer = setInterval( ping, interval );
    }

    function stop() {
        if ( timer ) {
            clearInterval( timer );
            timer = null;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Pause when tab is hidden, resume when visible again                */
    /* ------------------------------------------------------------------ */

    document.addEventListener( 'visibilitychange', function () {
        if ( document.hidden ) {
            stop();
        } else {
            // Re-ping immediately so the admin sees the user instantly
            start();
        }
    } );

    /* ------------------------------------------------------------------ */
    /*  Final leave beacon when user navigates away / closes tab          */
    /*  Sends action=portcld9_leave which deletes the presence row        */
    /*  immediately — no waiting for the 90-second timeout to expire.     */
    /* ------------------------------------------------------------------ */

    window.addEventListener( 'pagehide', function () {
        stop();
        if ( navigator && navigator.sendBeacon ) {
            var fd = new FormData();
            fd.append( 'action', 'portcld9_leave' );
            fd.append( 'token',  token );
            navigator.sendBeacon( ajaxUrl, fd );
        }
    } );

    /* ------------------------------------------------------------------ */
    /*  Boot                                                                */
    /* ------------------------------------------------------------------ */

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', start );
    } else {
        start();
    }

}());
