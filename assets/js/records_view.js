(function () {
    'use strict';

    var data = null;

    function activeVaultView() {
        return new URLSearchParams(window.location.search).get('vault_view') || 'records';
    }

    function activeFolder() {
        return new URLSearchParams(window.location.search).get('vault_folder') || '';
    }

    function postAction(action, fields) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'records_category_actions.php';
        var csrf = document.querySelector('meta[name="csrf-token"]');
        var values = Object.assign({ action: action, csrf_token: csrf ? csrf.getAttribute('content') : '' }, fields || {});
        Object.keys(values).forEach(function (key) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = values[key] == null ? '' : values[key];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    }

    function recordIdFromCard(card) {
        var onclick = card.getAttribute('onclick') || '';
        var open = onclick.indexOf('(');
        var close = onclick.lastIndexOf(')');
        if (open === -1 || close <= open) return '';
        try {
            var args = JSON.parse(onclick.slice(open + 1, close));
            return Array.isArray(args) ? String(args[5] || '') : '';
        } catch (_) {
            return '';
        }
    }

    function filterFolderRecords() {
        var category = activeVaultView();
        var folder = activeFolder();
        if (category === 'records' || !folder || !data || !data.records) return;

        document.querySelectorAll('.vault-record-card').forEach(function (card) {
            var id = recordIdFromCard(card);
            var record = id ? data.records[id] : null;
            var visible = !!record && String(record.category || '') === category && String(record.folder || '') === folder;
            card.style.display = visible ? '' : 'none';
        });
    }

    function filterRecordsBucket() {
        if (activeVaultView() !== 'records') return;
        document.querySelectorAll('.vault-record-card').forEach(function (card) {
            var category = (card.getAttribute('data-vault-category') || '').trim();
            if (category !== '') card.remove();
        });
    }

    function updateRecordsLabels() {
        var label = data && data.records_label ? data.records_label : 'Records';
        if (activeVaultView() === 'records') {
            var panel = document.getElementById('records-panel');
            var heading = panel ? panel.querySelector(':scope > div:first-child h3') : null;
            if (heading) heading.textContent = '📁 ' + label;
            if (panel) panel.setAttribute('data-vault-title', label);
        }

        var tile = document.querySelector('#view-panel a[href="index.php?pane=records"]');
        if (tile) {
            if (data && data.records_deleted) {
                tile.style.display = 'none';
            } else {
                tile.style.display = 'flex';
                tile.style.alignItems = 'center';
                tile.style.justifyContent = 'center';
                tile.style.minHeight = '58px';
                tile.style.width = '100%';
                tile.style.boxSizing = 'border-box';
                tile.style.textAlign = 'center';
                tile.style.textDecoration = 'none';
                tile.style.fontSize = '16px';
                tile.style.background = '#f1f3f5';
                tile.style.color = '#212529';
                tile.style.border = '1px solid #dee2e6';
                tile.classList.remove('btn-primary');
                tile.classList.add('btn');
                tile.textContent = '📁 ' + label;
            }
        }
    }

    function updateStatusMessages() {
        var params = new URLSearchParams(window.location.search);
        var status = params.get('status');
        if (status !== 'records_renamed' && status !== 'records_deleted') return;
        var message = status === 'records_renamed' ? 'Records category renamed successfully.' : 'Records category deleted. Its records remain uncategorised.';
        var notice = document.createElement('p');
        notice.className = 'success';
        notice.textContent = message;
        var panel = document.querySelector('#records-panel') || document.querySelector('.box');
        if (panel) panel.insertBefore(notice, panel.firstChild);
    }

    async function init() {
        try {
            var response = await fetch('records_view_data.php', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Records view data unavailable');
            data = await response.json();
            updateRecordsLabels();
            filterRecordsBucket();
            filterFolderRecords();
            updateStatusMessages();

            if (window.viewRecordDetails && !window.__recordsViewWrapped) {
                var originalViewRecordDetails = window.viewRecordDetails;
                window.viewRecordDetails = function () {
                    var args = Array.prototype.slice.call(arguments);
                    if (Array.isArray(args[0])) {
                        args[0] = args[0].slice();
                        if ((args[0][6] || '') === '' && activeVaultView() === 'records' && data && !data.records_deleted) args[0][6] = data.records_label;
                    } else if (args.length >= 7 && !args[6] && activeVaultView() === 'records' && data && !data.records_deleted) {
                        args[6] = data.records_label;
                    }
                    return originalViewRecordDetails.apply(this, args);
                };
                window.__recordsViewWrapped = true;
            }
        } catch (error) {
            console.error('SentryIQ Records view initialization failed:', error);
        }
    }

    document.addEventListener('DOMContentLoaded', init);
}());
