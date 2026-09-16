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
            card.style.display = category === '' ? '' : 'none';
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
                tile.style.display = '';
                tile.classList.remove('btn-primary');
                tile.classList.add('btn');
                tile.style.background = '#f1f3f5';
                tile.style.color = '#212529';
                tile.style.border = '1px solid #dee2e6';
                tile.textContent = '📁 ' + label;
            }
        }
    }

    function addRecordsOptions() {
        if (activeVaultView() !== 'records' || !data || data.records_deleted) return;
        var header = document.querySelector('#records-panel > div:first-child');
        var title = header ? header.querySelector('h3') : null;
        if (!header || !title || title.getAttribute('data-records-options-wired') === '1') return;
        title.setAttribute('data-records-options-wired', '1');

        var wrapper = document.createElement('span');
        wrapper.className = 'vault-category-header-options records-category-header-options';
        wrapper.style.cssText = 'position:relative;display:flex;align-items:center;gap:8px;flex:0 0 auto;';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'vault-category-options-button';
        button.textContent = 'Options';
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-label', 'Options for ' + data.records_label);
        button.style.cssText = 'min-height:38px;padding:7px 12px;border:1px solid #dee2e6;border-radius:10px;background:#fff;color:#212529;font-weight:600;box-shadow:0 3px 8px rgba(0,0,0,.10);cursor:pointer;';

        var menu = document.createElement('div');
        menu.className = 'vault-category-options-menu';
        menu.style.cssText = 'display:none;position:absolute;right:0;top:calc(100% + 8px);z-index:100;min-width:210px;padding:7px;border:1px solid #dee2e6;border-radius:12px;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.16);';

        function menuButton(label, handler, destructive) {
            var item = document.createElement('button');
            item.type = 'button';
            item.textContent = label;
            item.style.cssText = 'display:block;width:100%;padding:11px 12px;text-align:left;border:0;border-radius:10px;background:transparent;color:' + (destructive ? '#b02a37' : '#212529') + ';font-size:15px;cursor:pointer;';
            item.addEventListener('mouseenter', function () { item.style.background = 'rgba(0,0,0,.05)'; });
            item.addEventListener('mouseleave', function () { item.style.background = 'transparent'; });
            item.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                handler();
            });
            return item;
        }

        menu.appendChild(menuButton('＋ Add Record', function () {
            menu.style.display = 'none';
            button.setAttribute('aria-expanded', 'false');
            window.location.href = 'index.php?pane=add';
        }, false));

        menu.appendChild(menuButton('✏ Rename', function () {
            menu.style.display = 'none';
            button.setAttribute('aria-expanded', 'false');
            var newName = window.prompt('Rename category:', data.records_label);
            if (newName === null) return;
            newName = newName.trim();
            if (!newName || newName === data.records_label) return;
            postAction('rename_records', { new_label: newName });
        }, false));

        menu.appendChild(menuButton('🗑 Delete', function () {
            menu.style.display = 'none';
            button.setAttribute('aria-expanded', 'false');
            if (!window.confirm('Delete the ' + data.records_label + ' category? Its records will be kept as uncategorised records.')) return;
            postAction('delete_records');
        }, true));

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var open = menu.style.display === 'block';
            menu.style.display = open ? 'none' : 'block';
            button.setAttribute('aria-expanded', open ? 'false' : 'true');
        });

        wrapper.appendChild(button);
        wrapper.appendChild(menu);
        title.appendChild(wrapper);

        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) {
                menu.style.display = 'none';
                button.setAttribute('aria-expanded', 'false');
            }
        });
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
            addRecordsOptions();
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
