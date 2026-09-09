/**
 * Portal Cloud 9 – Reward Points JS
 *
 * All required data (role, ajax_url, nonce) is read directly from
 * data-* attributes on .p9rw-wrap, so this script works regardless of
 * whether wp_localize_script ran correctly.
 */
(function () {
    'use strict';

    /* ── Read config from the DOM — never blocked by wp_localize_script ── */
    var wrap = document.querySelector('.p9rw-wrap');
    if (!wrap) { return; } // rewards tab not on this page

    var AJAX      = wrap.getAttribute('data-ajax') || '';
    var NONCE     = wrap.getAttribute('data-nonce') || '';
    var ROLE      = wrap.getAttribute('data-role')  || 'customer';
    var BAL       = parseInt(wrap.getAttribute('data-balance') || '0', 10);
    var ORDER_URL = wrap.getAttribute('data-order-url') || '';

    /* Fallback: also accept wp_localize_script data if present */
    if (typeof portcld9_rewards_data !== 'undefined' && portcld9_rewards_data) {
        AJAX  = AJAX  || portcld9_rewards_data.ajax_url || '';
        NONCE = NONCE || portcld9_rewards_data.nonce    || '';
    }

    /* ── Utilities ─────────────────────────────────────────────── */
    var toastTmr = null;

    function esc(v) {
        if (v == null) { return ''; }
        return String(v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function hashCode(s) {
        var h = 0;
        for (var i = 0; i < s.length; i++) { h = Math.imul(31, h) + s.charCodeAt(i) | 0; }
        return h;
    }

    function toast(msg, type) {
        var el = document.getElementById('p9rw-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'p9rw-toast';
            el.className = 'p9rw-toast';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.className = 'p9rw-toast show ' + (type || 'success');
        clearTimeout(toastTmr);
        toastTmr = setTimeout(function () { el.classList.remove('show'); }, 3400);
    }

    function ajax(action, data, cb) {
        if (!AJAX) { toast('AJAX URL not available.', 'error'); return; }
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', NONCE);
        if (data) {
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        }
        fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (r.success) {
                    cb(r.data, null);
                } else {
                    cb(null, (r.data && r.data.message) ? r.data.message : 'An error occurred.');
                }
            })
            .catch(function () { cb(null, 'Network error. Please try again.'); });
    }

    /* ── Tab switching ─────────────────────────────────────────── */
    function initTabs() {
        wrap.querySelectorAll('.p9rw-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var panelId = this.getAttribute('data-panel');
                wrap.querySelectorAll('.p9rw-tab').forEach(function (b) {
                    b.classList.remove('active');
                });
                wrap.querySelectorAll('.p9rw-panel').forEach(function (p) {
                    p.classList.remove('active');
                });
                this.classList.add('active');
                var panel = document.getElementById(panelId);
                if (panel) { panel.classList.add('active'); }
            });
        });
    }

    /* ================================================================
       ADMIN
       ================================================================ */

    var searchDebounce   = null;
    var activeRoleFilter = '';
    var modalActionType  = 'add';

    /* ── Filter tabs ───────────────────────────────────────────── */
    function initFilters() {
        wrap.querySelectorAll('.p9rw-filter-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                wrap.querySelectorAll('.p9rw-filter-btn').forEach(function (b) {
                    b.classList.remove('active');
                });
                this.classList.add('active');
                activeRoleFilter = this.getAttribute('data-role-filter') || '';
                var inp = document.getElementById('p9rw-search-input');
                clearTimeout(searchDebounce);
                runSearch(inp ? inp.value : '');
            });
        });
    }

    /* ── Pill search ───────────────────────────────────────────── */
    function initSearch() {
        var inp    = document.getElementById('p9rw-search-input');
        var btn    = document.getElementById('p9rw-search-btn');
        var clearX = document.getElementById('p9rw-search-clear');
        var resetB = document.getElementById('p9rw-status-reset');
        if (!inp) { return; }

        inp.addEventListener('input', function () {
            if (clearX) { clearX.style.display = this.value.length ? '' : 'none'; }
        });

        if (clearX) {
            clearX.addEventListener('click', function () {
                inp.value = '';
                clearX.style.display = 'none';
                inp.focus();
                runSearch('');
            });
        }

        inp.addEventListener('input', function () {
            var q = this.value;
            clearTimeout(searchDebounce);
            if (q.length === 0) {
                searchDebounce = setTimeout(function () { runSearch(''); }, 150);
            } else if (q.length >= 2) {
                searchDebounce = setTimeout(function () { runSearch(q); }, 360);
            }
        });

        if (btn) {
            btn.addEventListener('click', function () {
                clearTimeout(searchDebounce);
                runSearch(inp.value);
            });
        }

        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { clearTimeout(searchDebounce); runSearch(this.value); }
        });

        if (resetB) {
            resetB.addEventListener('click', function () {
                inp.value = '';
                if (clearX) { clearX.style.display = 'none'; }
                runSearch('');
            });
        }
    }

    function runSearch(q) {
        var list      = document.getElementById('p9rw-user-list');
        var statusBar = document.getElementById('p9rw-search-status');
        var statusTxt = document.getElementById('p9rw-status-text');
        if (!list) { return; }

        list.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:28px;font-size:13px;color:#94a3b8;">'
            + '<div class="p9rw-search-spin" style="display:inline-block;margin-right:8px;"></div>Searching…</div>';

        ajax('portcld9_rewards_admin_get_users', { search: q, role_filter: activeRoleFilter }, function (users, err) {
            if (err) {
                toast(err, 'error');
                list.innerHTML = '<div class="p9rw-empty" style="grid-column:1/-1;">'
                    + '<div class="p9rw-empty-icon">⚠️</div>'
                    + '<p>Could not load users. Please refresh the page.</p></div>';
                return;
            }

            if (statusBar && statusTxt) {
                if (q || activeRoleFilter) {
                    var cnt = users ? users.length : 0;
                    statusTxt.textContent = cnt
                        ? 'Showing ' + cnt + ' result' + (cnt !== 1 ? 's' : '') + (q ? ' for "' + q + '"' : '')
                        : 'No results' + (q ? ' for "' + q + '"' : '');
                    statusBar.style.display = '';
                } else {
                    statusBar.style.display = 'none';
                }
            }

            if (!users || !users.length) {
                list.innerHTML = '<div class="p9rw-empty" style="grid-column:1/-1;">'
                    + '<div class="p9rw-empty-icon">🔍</div>'
                    + '<p>No users found' + (q ? ' for "' + esc(q) + '"' : '') + '.</p></div>';
                return;
            }

            list.innerHTML = users.map(function (u) {
                var letter  = u.name ? u.name.charAt(0).toUpperCase() : '?';
                var hue     = Math.abs(hashCode(u.name || '')) % 360;
                var rk      = u.role     || 'cust';
                var rl      = u.role_lbl || 'Customer';
                var zeroCls = (u.balance === 0 || u.balance === '0') ? ' zero-pts' : '';
                var html    = '<div class="p9rw-acard' + zeroCls + '" data-uid="' + esc(u.id) + '" data-name="' + esc(u.name) + '" data-pts="' + esc(u.balance) + '" data-role="' + esc(rk) + '">';
                html += '<div class="p9rw-acard-sel"><input type="checkbox" class="p9rw-card-cb" data-uid="' + esc(u.id) + '" title="Select ' + esc(u.name) + '"></div>';
                html += '<div class="p9rw-acard-top">'
                    + '<div class="p9rw-acard-av" style="--av-hue:' + hue + '">' + esc(letter) + '</div>'
                    + '<div class="p9rw-acard-meta">'
                    + '<div class="p9rw-acard-name">' + esc(u.name) + '</div>'
                    + '<div class="p9rw-acard-email">' + esc(u.email) + '</div>'
                    + '<span class="p9rw-role-tag ' + esc(rk) + '">' + esc(rl) + '</span>'
                    + '</div>'
                    + '<div class="p9rw-acard-balance">'
                    + '<div class="p9rw-acard-bal-num' + ((!u.balance || u.balance === '0') ? ' zero' : '') + '">' + Number(u.balance).toLocaleString() + '</div>'
                    + '<div class="p9rw-acard-bal-lbl">points</div>'
                    + '</div></div>';
                html += '<div class="p9rw-acard-stats">'
                    + '<div class="p9rw-acard-stat"><span>' + Number(u.balance).toLocaleString() + '</span><label>Balance</label></div>'
                    + '</div>';
                html += '<div class="p9rw-acard-actions">'
                    + '<button class="p9rw-action-btn add p9rw-adjust" data-uid="' + esc(u.id) + '" data-name="' + esc(u.name) + '" data-pts="' + esc(u.balance) + '" data-type="add">➕ Add Points</button>';
                if (u.balance > 0) {
                    html += '<button class="p9rw-action-btn sub p9rw-adjust" data-uid="' + esc(u.id) + '" data-name="' + esc(u.name) + '" data-pts="' + esc(u.balance) + '" data-type="deduct">➖ Deduct</button>';
                    html += '<button class="p9rw-action-btn set p9rw-adjust" data-uid="' + esc(u.id) + '" data-name="' + esc(u.name) + '" data-pts="' + esc(u.balance) + '" data-type="set">✏️ Set</button>';
                }
                html += '<button class="p9rw-action-btn hist p9rw-view-hist" data-uid="' + esc(u.id) + '" data-name="' + esc(u.name) + '">📋 History</button>';
                html += '</div></div>';
                return html;
            }).join('');
        });
    }

    /* ── Modal helpers ─────────────────────────────────────────── */
    function syncPills(action) {
        wrap.querySelectorAll('.p9rw-apill').forEach(function (p) { p.classList.remove('active'); });
        var a = wrap.querySelector('.p9rw-apill[data-action="' + action + '"]');
        if (a) { a.classList.add('active'); }
    }

    function toggleResetUI(action) {
        var isReset = (action === 'reset');
        ['p9rw-preset-row', 'p9rw-amount-row', 'p9rw-modal-preview'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.style.display = isReset ? 'none' : ''; }
        });
    }

    function livePreview(curPts) {
        var previewEl = document.getElementById('p9rw-preview-after');
        if (!previewEl) { return; }
        var pts     = parseInt((document.getElementById('p9rw-modal-pts') || {}).value, 10) || 0;
        var cur     = parseInt(curPts, 10) || 0;
        var actInp  = document.getElementById('p9rw-modal-action-type');
        var action  = actInp ? actInp.value : 'add';
        var result  = cur;
        if (action === 'add')    { result = cur + pts; }
        if (action === 'deduct') { result = Math.max(0, cur - pts); }
        if (action === 'set')    { result = pts; }
        if (action === 'reset')  { result = 0; }
        previewEl.textContent = Number(result).toLocaleString() + ' pts';
    }

    function openModal(uid, name, curPts, defAction) {
        var modal = document.getElementById('p9rw-modal');
        if (!modal) { return; }
        modalActionType = defAction || 'add';
        var av     = document.getElementById('p9rw-modal-av');
        var title  = document.getElementById('p9rw-modal-title');
        var curEl  = document.getElementById('p9rw-modal-cur-pts');
        var uidInp = document.getElementById('p9rw-modal-uid');
        var actInp = document.getElementById('p9rw-modal-action-type');
        var ptsInp = document.getElementById('p9rw-modal-pts');
        if (av) {
            av.style.setProperty('--av-hue', Math.abs(hashCode(String(name))) % 360);
            av.textContent = name ? String(name).charAt(0).toUpperCase() : '?';
        }
        if (title)  { title.textContent = name; }
        if (curEl)  { curEl.textContent = Number(curPts).toLocaleString(); }
        if (uidInp) { uidInp.value = uid; }
        if (actInp) { actInp.value = modalActionType; }
        if (ptsInp) { ptsInp.value = 100; }
        syncPills(modalActionType);
        livePreview(curPts);
        toggleResetUI(modalActionType);
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        if (ptsInp) { setTimeout(function () { ptsInp.focus(); }, 50); }
    }

    function closeModal(id) {
        var el = document.getElementById(id || 'p9rw-modal');
        if (el) { el.classList.remove('open'); el.setAttribute('aria-hidden', 'true'); }
    }

    /* ── Single delegated listener for ALL admin interactions ──── */
    function initAdmin() {

        initSearch();
        initFilters();

        document.addEventListener('click', function (e) {

            /* Adjust button */
            var adj = e.target.closest && e.target.closest('.p9rw-adjust');
            if (adj) {
                openModal(
                    adj.getAttribute('data-uid'),
                    adj.getAttribute('data-name'),
                    adj.getAttribute('data-pts') || '0',
                    adj.getAttribute('data-type') || 'add'
                );
                return;
            }

            /* History button */
            var hist = e.target.closest && e.target.closest('.p9rw-view-hist');
            if (hist) {
                openHistoryDrawer(hist.getAttribute('data-uid'), hist.getAttribute('data-name'));
                return;
            }

            /* Action pills inside modal */
            var pill = e.target.closest && e.target.closest('.p9rw-apill');
            if (pill && pill.closest('#p9rw-modal')) {
                modalActionType = pill.getAttribute('data-action');
                syncPills(modalActionType);
                var actI = document.getElementById('p9rw-modal-action-type');
                if (actI) { actI.value = modalActionType; }
                var curTxt = (document.getElementById('p9rw-modal-cur-pts') || {}).textContent || '0';
                livePreview(parseInt(curTxt.replace(/,/g, ''), 10) || 0);
                toggleResetUI(modalActionType);
                return;
            }

            /* Preset quick-amount */
            var pre = e.target.closest && e.target.closest('.p9rw-preset');
            if (pre && pre.closest('#p9rw-modal')) {
                var ptsI2 = document.getElementById('p9rw-modal-pts');
                if (ptsI2) {
                    ptsI2.value = pre.getAttribute('data-val');
                    var curTxt2 = (document.getElementById('p9rw-modal-cur-pts') || {}).textContent || '0';
                    livePreview(parseInt(curTxt2.replace(/,/g, ''), 10) || 0);
                }
                return;
            }

            /* Modal cancel */
            if (e.target.id === 'p9rw-modal-cancel') { closeModal('p9rw-modal'); return; }

            /* Modal overlay click-outside */
            if (e.target.id === 'p9rw-modal') { closeModal('p9rw-modal'); return; }

            /* Drawer close */
            if (e.target.id === 'p9rw-drawer-close' || e.target.id === 'p9rw-drawer') {
                closeModal('p9rw-drawer'); return;
            }

            /* Modal confirm */
            if (e.target.id === 'p9rw-modal-confirm') {
                var action   = (document.getElementById('p9rw-modal-action-type') || {}).value || 'add';
                var uid      = (document.getElementById('p9rw-modal-uid') || {}).value || '';
                var pts      = parseInt((document.getElementById('p9rw-modal-pts') || {}).value, 10) || 0;
                var reason   = (document.getElementById('p9rw-modal-reason') || {}).value || 'Manual Adjustment';
                var note     = (document.getElementById('p9rw-modal-note') || {}).value || '';
                var curTxt3  = (document.getElementById('p9rw-modal-cur-pts') || {}).textContent || '0';
                var curPts   = parseInt(curTxt3.replace(/,/g, ''), 10) || 0;
                var fullNote = reason + (note ? ': ' + note : '');

                if (action !== 'reset' && pts <= 0) { toast('Enter a valid amount.', 'error'); return; }

                var sendPts = pts, sendType = action;
                if (action === 'set') {
                    sendPts  = Math.abs(pts - curPts);
                    sendType = (pts >= curPts) ? 'add' : 'deduct';
                    if (sendPts === 0) { toast('Balance is already at that amount.', 'error'); return; }
                }
                if (action === 'reset') {
                    sendPts  = curPts;
                    sendType = 'deduct';
                    if (sendPts === 0) { toast('Balance is already zero.', 'error'); return; }
                }

                var confirmBtn = document.getElementById('p9rw-modal-confirm');
                if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.textContent = '⏳…'; }

                ajax('portcld9_rewards_admin_adjust', {
                    target_user: uid,
                    points:      sendPts,
                    action_type: sendType,
                    note:        fullNote
                }, function (data, err) {
                    if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = 'Apply Change'; }
                    closeModal('p9rw-modal');
                    if (err) { toast(err, 'error'); return; }
                    var newBal = parseInt(data.new_balance, 10);
                    toast(data.message + ' — Balance: ' + Number(newBal).toLocaleString() + ' pts', 'success');
                    var card = document.querySelector('.p9rw-acard[data-uid="' + uid + '"]');
                    if (card) {
                        card.setAttribute('data-pts', newBal);

                        // Update top-right balance number
                        var balNum = card.querySelector('.p9rw-acard-bal-num');
                        if (balNum) { balNum.textContent = Number(newBal).toLocaleString(); balNum.classList.toggle('zero', newBal === 0); }

                        // Update the Balance stat cell in the stats row (the "350 BALANCE" section)
                        card.querySelectorAll('.p9rw-acard-stat label').forEach(function (lbl) {
                            if (lbl.textContent.trim() === 'Balance') {
                                var span = lbl.previousElementSibling;
                                if (span) { span.textContent = Number(newBal).toLocaleString(); }
                            }
                        });

                        // Sync data-pts on all buttons
                        card.querySelectorAll('[data-pts]').forEach(function (b) { b.setAttribute('data-pts', newBal); });
                        /* Show Deduct+Set buttons if now has balance */
                        if (newBal > 0) {
                            var actions = card.querySelector('.p9rw-acard-actions');
                            if (actions && !actions.querySelector('.p9rw-action-btn.sub')) {
                                var nm = card.getAttribute('data-name') || '';
                                var sb = document.createElement('button');
                                sb.className = 'p9rw-action-btn sub p9rw-adjust';
                                sb.setAttribute('data-uid', uid); sb.setAttribute('data-name', nm);
                                sb.setAttribute('data-pts', newBal); sb.setAttribute('data-type', 'deduct');
                                sb.textContent = '➖ Deduct';
                                var addB = actions.querySelector('.p9rw-action-btn.add');
                                if (addB && addB.nextSibling) { actions.insertBefore(sb, addB.nextSibling); } else { actions.appendChild(sb); }
                            }
                            if (actions && !actions.querySelector('.p9rw-action-btn.set')) {
                                var nm2 = card.getAttribute('data-name') || '';
                                var sb2 = document.createElement('button');
                                sb2.className = 'p9rw-action-btn set p9rw-adjust';
                                sb2.setAttribute('data-uid', uid); sb2.setAttribute('data-name', nm2);
                                sb2.setAttribute('data-pts', newBal); sb2.setAttribute('data-type', 'set');
                                sb2.textContent = '✏️ Set';
                                var histB = actions.querySelector('.p9rw-action-btn.hist');
                                if (histB) { actions.insertBefore(sb2, histB); } else { actions.appendChild(sb2); }
                            }
                        }
                    }
                });
                return;
            }

            /* Approval */
            var appBtn = e.target.closest && e.target.closest('.p9rw-approve');
            if (appBtn) {
                appBtn.disabled = true;
                ajax('portcld9_rewards_approve_withdrawal', {
                    target_user: appBtn.getAttribute('data-uid'),
                    wd_id:       appBtn.getAttribute('data-wdid')
                }, function (data, err) {
                    appBtn.disabled = false;
                    if (err) { toast(err, 'error'); return; }
                    toast(data.message, 'success');
                    var item = appBtn.closest('.p9rw-wd-card');
                    if (item) {
                        var b = item.querySelector('.p9rw-pill');
                        if (b) { b.textContent = 'APPROVED'; b.className = 'p9rw-pill approved'; }
                        appBtn.remove();
                        var rj = item.querySelector('.p9rw-reject');
                        if (rj) { rj.remove(); }
                    }
                });
                return;
            }

            /* Rejection */
            var rejBtn = e.target.closest && e.target.closest('.p9rw-reject');
            if (rejBtn) {
                var reason2 = window.prompt('Reason for rejection (optional):') || '';
                rejBtn.disabled = true;
                ajax('portcld9_rewards_reject_withdrawal', {
                    target_user: rejBtn.getAttribute('data-uid'),
                    wd_id:       rejBtn.getAttribute('data-wdid'),
                    reason:      reason2
                }, function (data, err) {
                    rejBtn.disabled = false;
                    if (err) { toast(err, 'error'); return; }
                    toast(data.message, 'success');
                    var item2 = rejBtn.closest('.p9rw-wd-card');
                    if (item2) {
                        var b2 = item2.querySelector('.p9rw-pill');
                        if (b2) { b2.textContent = 'REJECTED'; b2.className = 'p9rw-pill rejected'; }
                        var ap2 = item2.querySelector('.p9rw-approve');
                        if (ap2) { ap2.remove(); }
                        rejBtn.remove();
                    }
                });
                return;
            }
        });

        /* pts input live preview */
        var ptsInp = document.getElementById('p9rw-modal-pts');
        if (ptsInp) {
            ptsInp.addEventListener('input', function () {
                var curTxt4 = (document.getElementById('p9rw-modal-cur-pts') || {}).textContent || '0';
                livePreview(parseInt(curTxt4.replace(/,/g, ''), 10) || 0);
            });
        }

        /* Settings save */
        var saveBtn = document.getElementById('p9rw-save-settings');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var payload = {};
                wrap.querySelectorAll('.p9rw-rs-input[name]').forEach(function (el) {
                    payload[el.name] = el.value;
                });
                var tog = document.getElementById('p9rw-rs-enabled');
                payload.enabled = (tog && tog.checked) ? 1 : 0;
                saveBtn.disabled = true; saveBtn.textContent = '⏳ Saving…';
                ajax('portcld9_rewards_save_settings', payload, function (data, err) {
                    saveBtn.disabled = false; saveBtn.textContent = '💾 Save Settings';
                    if (err) { toast(err, 'error'); return; }
                    toast(data.message || 'Settings saved.', 'success');
                });
            });
        }

        /* Load user list via AJAX on init — keeps server and AJAX rendering in sync */
        runSearch('');

        // Expose for bulk select refresh hook
        window._p9rwRunSearch = runSearch;

        initBulkSelect();
        initExpiryTrigger();
        initCsvImport();
    }

    /* ── History drawer ─────────────────────────────────────────── */
    function openHistoryDrawer(uid, name) {
        var drawer = document.getElementById('p9rw-drawer');
        if (!drawer) { return; }
        var nameEl = document.getElementById('p9rw-drawer-name');
        var avEl   = document.getElementById('p9rw-drawer-av');
        var body   = document.getElementById('p9rw-drawer-body');
        if (nameEl) { nameEl.textContent = name; }
        if (avEl) {
            avEl.textContent = name ? String(name).charAt(0).toUpperCase() : '?';
            avEl.style.setProperty('--av-hue', Math.abs(hashCode(String(name || ''))) % 360);
        }
        if (body) {
            body.innerHTML = '<div style="text-align:center;padding:20px;font-size:13px;color:#94a3b8;">'
                + '<div class="p9rw-search-spin" style="display:inline-block;margin-right:6px;"></div>Loading…</div>';
        }
        drawer.classList.add('open');
        drawer.setAttribute('aria-hidden', 'false');

        ajax('portcld9_rewards_get_log', { user_id: uid, page: 1 }, function (log, err) {
            if (!body) { return; }
            if (err || !log || !log.length) {
                body.innerHTML = '<div class="p9rw-empty"><div class="p9rw-empty-icon">📋</div><p>No transactions yet.</p></div>';
                return;
            }
            var icons  = { earn: '⬆️', redeem: '🎟️', adjust: '✏️', deduct: '⬇️', withdrawal: '💸', withdrawal_approved: '✅', refund: '↩️' };
            var colors = { earn: 'emerald', redeem: 'rose', adjust: 'sky', deduct: 'rose', withdrawal: 'violet', refund: 'amber' };
            body.innerHTML = '<div class="p9rw-log">'
                + log.map(function (e) {
                    var pts = parseInt(e.points, 10);
                    var pos = pts >= 0;
                    var col = colors[e.type] || 'sky';
                    var noteHtml = esc(e.note || e.type);
                    if (e.source_type === 'order' && e.source_id && ORDER_URL) {
                        noteHtml += ' <a href="' + esc(ORDER_URL) + '" target="_blank" class="p9rw-order-link" title="View order #' + esc(e.source_id) + '">#' + esc(e.source_id) + '&nbsp;↗</a>';
                    }
                    return '<div class="p9rw-log-row">'
                        + '<div class="p9rw-log-dot ' + col + '">' + (icons[e.type] || '•') + '</div>'
                        + '<div class="p9rw-log-body"><div class="p9rw-log-note">' + noteHtml + '</div>'
                        + '<div class="p9rw-log-time">' + esc(e.created_at) + '</div></div>'
                        + '<div class="p9rw-log-pts ' + (pos ? 'pos' : 'neg') + '">' + (pos ? '+' : '') + pts + '</div>'
                        + '</div>';
                }).join('')
                + '</div>';
        });
    }

    /* ================================================================
       MANAGER
       ================================================================ */
    function initManager() {
        var wdRate  = parseInt(wrap.getAttribute('data-wd-rate') || '100', 10);
        var wdMin   = parseInt(wrap.getAttribute('data-wd-min')  || '500', 10);
        var ptsInp  = document.getElementById('p9rw-wd-pts');
        var preview = document.getElementById('p9rw-wd-preview');
        var btn     = document.getElementById('p9rw-wd-submit');

        if (ptsInp && preview) {
            ptsInp.addEventListener('input', function () {
                var pts = parseInt(ptsInp.value, 10) || 0;
                preview.textContent = 'Cash value: $' + (wdRate > 0 ? (pts / wdRate).toFixed(2) : '0.00');
            });
        }
        if (btn) {
            btn.addEventListener('click', function () {
                var pts     = parseInt((document.getElementById('p9rw-wd-pts')     || {}).value, 10) || 0;
                var method  = (document.getElementById('p9rw-wd-method')  || {}).value || 'bank';
                var details = (document.getElementById('p9rw-wd-details') || {}).value || '';
                if (pts < wdMin) { toast('Minimum ' + wdMin + ' points required.', 'error'); return; }
                if (pts > BAL)   { toast('Not enough points.', 'error'); return; }
                if (!details.trim()) { toast('Please enter your payment account details.', 'error'); return; }
                btn.disabled = true; btn.textContent = '⏳ Submitting…';
                ajax('portcld9_rewards_request_withdrawal', { points: pts, method: method, details: details }, function (data, err) {
                    btn.disabled = false; btn.textContent = '💸 Request Withdrawal';
                    if (err) { toast(err, 'error'); return; }
                    toast(data.message || 'Request submitted!', 'success');
                    setTimeout(function () { window.location.reload(); }, 1600);
                });
            });
        }
    }

    /* ================================================================
       CUSTOMER
       ================================================================ */
    function initCustomer() {
        var redeemRate = parseInt(wrap.getAttribute('data-redeem-rate') || '100', 10);
        var redeemMin  = parseInt(wrap.getAttribute('data-redeem-min')  || '50',  10);
        var ptsInp     = document.getElementById('p9rw-redeem-pts');
        var preview    = document.getElementById('p9rw-redeem-preview');
        var btn        = document.getElementById('p9rw-redeem-btn');
        var result     = document.getElementById('p9rw-coupon-result');
        var codeEl     = document.getElementById('p9rw-coupon-code-text');
        var copyBtn    = document.getElementById('p9rw-copy-code');
        var bal        = BAL;

        function refreshBalanceUI(newBal) {
            bal = newBal;
            // Wallet number — animate count-up
            var balNumEl = document.getElementById('p9rw-cust-bal-num');
            if (balNumEl) {
                var start = parseInt(balNumEl.textContent.replace(/,/g, ''), 10) || 0;
                var end   = newBal;
                var dur   = 800;
                var startTime = null;
                function countUp(ts) {
                    if (!startTime) { startTime = ts; }
                    var pct = Math.min((ts - startTime) / dur, 1);
                    balNumEl.textContent = Number(Math.round(start + (end - start) * pct)).toLocaleString();
                    if (pct < 1) { requestAnimationFrame(countUp); }
                }
                requestAnimationFrame(countUp);
            }
            // Mini-stat "Available" card
            var availEl = wrap.querySelector('.p9rw-ms-card.sky span');
            if (availEl) { availEl.textContent = Number(newBal).toLocaleString(); }
            // Coupon value mini-stat
            var cpnEl = wrap.querySelector('.p9rw-ms-card.amber span');
            if (cpnEl && redeemRate > 0) { cpnEl.textContent = '$' + (newBal / redeemRate).toFixed(2); }
            // Progress bar + label
            var pct      = Math.min(100, Math.round(newBal / Math.max(1, redeemMin) * 100));
            var progFill = document.getElementById('p9rw-prog-fill');
            var progLbl  = document.getElementById('p9rw-prog-lbl');
            if (progFill) { progFill.style.width = pct + '%'; }
            if (progLbl) {
                progLbl.textContent = newBal >= redeemMin
                    ? '✅ Ready to redeem!'
                    : Number(redeemMin - newBal).toLocaleString() + ' more points to unlock redemption';
            }
            // Update pts input max
            if (ptsInp) { ptsInp.max = newBal; }
        }

        if (ptsInp && preview) {
            ptsInp.addEventListener('input', function () {
                var pts = parseInt(ptsInp.value, 10) || 0;
                preview.textContent = '$' + (redeemRate > 0 ? (pts / redeemRate).toFixed(2) : '0.00') + ' off';
            });
        }
        if (btn && ptsInp) {
            btn.addEventListener('click', function () {
                var pts = parseInt(ptsInp.value, 10) || 0;
                if (pts < redeemMin) { toast('Minimum ' + redeemMin + ' points required.', 'error'); return; }
                if (pts > bal)       { toast('Not enough points.', 'error'); return; }
                btn.disabled = true; btn.textContent = '⏳ Processing…';
                ajax('portcld9_rewards_convert_coupon', { points: pts }, function (data, err) {
                    btn.disabled = false; btn.textContent = '🎟️ Get My Coupon Code';
                    if (err) { toast(err, 'error'); return; }
                    if (codeEl) { codeEl.textContent = data.code; }
                    if (result) {
                        result.style.display = 'block';
                        result.classList.add('p9rw-reveal-pulse');
                        setTimeout(function () { result.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 80);
                        setTimeout(function () { result.classList.remove('p9rw-reveal-pulse'); }, 800);
                    }
                    refreshBalanceUI(parseInt(data.new_balance, 10) || 0);
                    toast('✅ Coupon created: ' + data.code, 'success');
                });
            });
        }
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                if (codeEl && navigator.clipboard) {
                    navigator.clipboard.writeText(codeEl.textContent)
                        .then(function () { toast('Code copied!', 'success'); });
                }
            });
        }
    }

    /* ================================================================
       ADMIN — BULK SELECT
       ================================================================ */
    function initBulkSelect() {
        var bulkBar     = document.getElementById('p9rw-bulk-bar');
        var bulkCount   = document.getElementById('p9rw-bulk-count');
        var selectRow   = document.getElementById('p9rw-select-row');
        var selectAll   = document.getElementById('p9rw-select-all');
        var selectedCnt = document.getElementById('p9rw-selected-count');
        var bulkApply   = document.getElementById('p9rw-bulk-apply');
        var bulkCancel  = document.getElementById('p9rw-bulk-cancel');

        function getChecked() {
            return Array.from(document.querySelectorAll('.p9rw-card-cb:checked'));
        }

        function updateBar() {
            var checked = getChecked();
            var n = checked.length;
            if (bulkBar)     { bulkBar.style.display = n > 0 ? '' : 'none'; bulkBar.setAttribute('aria-hidden', n > 0 ? 'false' : 'true'); }
            if (selectRow)   { selectRow.style.display = document.querySelectorAll('.p9rw-card-cb').length > 0 ? '' : 'none'; }
            if (bulkCount)   { bulkCount.textContent = n; }
            if (selectedCnt) { selectedCnt.textContent = n + ' selected'; }
        }

        // Delegated — works for both server-rendered and AJAX-loaded cards
        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('p9rw-card-cb')) { updateBar(); }
            if (e.target.id === 'p9rw-select-all') {
                document.querySelectorAll('.p9rw-card-cb').forEach(function (cb) {
                    cb.checked = e.target.checked;
                });
                updateBar();
            }
        });

        if (bulkCancel) {
            bulkCancel.addEventListener('click', function () {
                document.querySelectorAll('.p9rw-card-cb').forEach(function (cb) { cb.checked = false; });
                if (selectAll) { selectAll.checked = false; }
                updateBar();
            });
        }

        if (bulkApply) {
            bulkApply.addEventListener('click', function () {
                var checked = getChecked();
                if (!checked.length) { toast('No users selected.', 'error'); return; }
                var pts    = parseInt((document.getElementById('p9rw-bulk-pts') || {}).value, 10) || 0;
                var action = (document.getElementById('p9rw-bulk-action') || {}).value || 'add';
                var note   = (document.getElementById('p9rw-bulk-note') || {}).value || '';
                if (pts <= 0) { toast('Enter a valid point amount.', 'error'); return; }
                var uids = checked.map(function (cb) { return parseInt(cb.getAttribute('data-uid'), 10); });
                bulkApply.disabled = true; bulkApply.textContent = '⏳…';
                ajax('portcld9_rewards_bulk_adjust', {
                    user_ids:    JSON.stringify(uids),
                    points:      pts,
                    action_type: action,
                    note:        note
                }, function (data, err) {
                    bulkApply.disabled = false; bulkApply.textContent = 'Apply';
                    if (err) { toast(err, 'error'); return; }
                    toast(data.message, 'success');
                    // Update each card's displayed balance
                    if (data.balances) {
                        Object.keys(data.balances).forEach(function (uid) {
                            var card = document.querySelector('.p9rw-acard[data-uid="' + uid + '"]');
                            if (!card) { return; }
                            var newBal = parseInt(data.balances[uid], 10);
                            card.setAttribute('data-pts', newBal);

                            // Update top-right balance number
                            var balNum = card.querySelector('.p9rw-acard-bal-num');
                            if (balNum) { balNum.textContent = Number(newBal).toLocaleString(); balNum.classList.toggle('zero', newBal === 0); }

                            // Update the Balance stat cell
                            card.querySelectorAll('.p9rw-acard-stat label').forEach(function (lbl) {
                                if (lbl.textContent.trim() === 'Balance') {
                                    var span = lbl.previousElementSibling;
                                    if (span) { span.textContent = Number(newBal).toLocaleString(); }
                                }
                            });

                            card.querySelectorAll('[data-pts]').forEach(function (b) { b.setAttribute('data-pts', newBal); });
                        });
                    }
                    // Deselect
                    checked.forEach(function (cb) { cb.checked = false; });
                    if (selectAll) { selectAll.checked = false; }
                    updateBar();
                });
            });
        }

        // Re-run after AJAX list reload
        var origRunSearch = window._p9rwRunSearch;
        if (typeof origRunSearch === 'function') {
            window._p9rwRunSearch = function (q) { origRunSearch(q); setTimeout(updateBar, 400); };
        }
    }

    /* ================================================================
       ADMIN — EXPIRY TRIGGER
       ================================================================ */
    function initExpiryTrigger() {
        var btn      = document.getElementById('p9rw-run-expiry');
        var timeEl   = document.getElementById('p9rw-expiry-time');
        var ltTimeEl = document.getElementById('p9rw-lt-expiry-time');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            btn.disabled = true; btn.textContent = '⏳ Running…';
            ajax('portcld9_rewards_trigger_expiry', {}, function (data, err) {
                btn.disabled = false; btn.textContent = '⚡ Run Now';
                if (err) { toast(err, 'error'); return; }
                toast('Expiry check complete.', 'success');
                if (timeEl && data.ran_at) { timeEl.textContent = data.ran_at; }
                if (ltTimeEl && data.ran_at) { ltTimeEl.textContent = data.ran_at; }
            });
        });
    }

    /* ================================================================
       ADMIN — CSV IMPORT
       ================================================================ */
    function initCsvImport() {
        var drop      = document.getElementById('p9rw-csv-drop');
        var fileInput = document.getElementById('p9rw-csv-file');
        var nameEl    = document.getElementById('p9rw-csv-filename');
        var importBtn = document.getElementById('p9rw-csv-import');
        var resultEl  = document.getElementById('p9rw-csv-result');
        var selectedFile = null;

        if (!drop || !fileInput) { return; }

        function setFile(file) {
            if (!file || !file.name.match(/\.csv$/i)) { toast('Please select a .csv file.', 'error'); return; }
            selectedFile = file;
            if (nameEl) { nameEl.textContent = file.name; nameEl.style.display = ''; }
            if (importBtn) { importBtn.disabled = false; }
            drop.classList.add('has-file');
        }

        drop.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () { if (this.files[0]) { setFile(this.files[0]); } });

        drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('drag-over'); });
        drop.addEventListener('dragleave', function ()  { drop.classList.remove('drag-over'); });
        drop.addEventListener('drop', function (e) {
            e.preventDefault(); drop.classList.remove('drag-over');
            if (e.dataTransfer.files[0]) { setFile(e.dataTransfer.files[0]); }
        });

        if (importBtn) {
            importBtn.addEventListener('click', function () {
                if (!selectedFile) { toast('No file selected.', 'error'); return; }
                var fd = new FormData();
                fd.append('action', 'portcld9_rewards_import_csv');
                fd.append('nonce', NONCE);
                fd.append('csv_file', selectedFile);
                importBtn.disabled = true; importBtn.textContent = '⏳ Importing…';
                fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (r) {
                        importBtn.disabled = false; importBtn.textContent = '📥 Import Points';
                        if (!r.success) { toast((r.data && r.data.message) || 'Import failed.', 'error'); return; }
                        toast(r.data.message, 'success');
                        if (resultEl) {
                            var html = '<div class="p9rw-csv-ok">✅ ' + esc(r.data.message) + '</div>';
                            if (r.data.errors && r.data.errors.length) {
                                html += '<div class="p9rw-csv-errors"><strong>Warnings:</strong><ul>' +
                                    r.data.errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') +
                                    '</ul></div>';
                            }
                            resultEl.innerHTML = html;
                            resultEl.style.display = '';
                        }
                        // Refresh user list
                        runSearch('');
                    })
                    .catch(function () { importBtn.disabled = false; importBtn.textContent = '📥 Import Points'; toast('Network error.', 'error'); });
            });
        }
    }

    /* ================================================================
       BOOT — always runs, no external dependency required
       ================================================================ */
    initTabs();

    if (ROLE === 'administrator') {
        initAdmin();
    } else if (ROLE === 'shop_manager') {
        initManager();
    } else {
        initCustomer();
    }

}());
