(function () {
    'use strict';

    function fetchFolderData() {
        return fetch('vault_folder_data.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) {
                if (!response.ok) throw new Error('Folder data unavailable');
                return response.json();
            });
    }

    function currentCategory() {
        return new URLSearchParams(window.location.search).get('vault_view') || '';
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function postAction(action, fields) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'vault_category_actions.php';
        var values = Object.assign({ action: action, csrf_token: csrfToken() }, fields || {});
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

    function showManagementStatus() {
        var status = new URLSearchParams(window.location.search).get('status');
        var messages = {
            category_renamed: 'Category renamed successfully.',
            category_deleted: 'Category deleted. Its records are now uncategorised.',
            folder_renamed: 'Folder renamed successfully.',
            folder_deleted: 'Folder deleted. Its records remain in the category.',
            folder_added: 'Folder created successfully.'
        };
        if (!status || !messages[status]) return;
        var notice = document.createElement('p');
        notice.className = 'success';
        notice.textContent = messages[status];
        var panel = document.querySelector('.box');
        if (panel) panel.insertBefore(notice, panel.firstChild);
    }

    function addFolderSelector(form, data) {
        if (!form || form.querySelector('[data-vault-folder-selector]')) return;
        var categorySelect = form.querySelector('select[name="category"]');
        if (!categorySelect) return;
        var group = document.createElement('div');
        group.className = 'form-group';
        group.setAttribute('data-vault-folder-selector', '1');
        var label = document.createElement('label');
        label.textContent = 'Folder:';
        var select = document.createElement('select');
        select.name = 'folder';
        select.className = 'input-field';
        select.disabled = true;
        select.innerHTML = '<option value="">Category level (no folder)</option>';
        group.appendChild(label);
        group.appendChild(select);
        categorySelect.closest('.form-group').insertAdjacentElement('afterend', group);

        function refresh() {
            var category = categorySelect.value;
            select.innerHTML = '<option value="">Category level (no folder)</option>';
            select.disabled = !category;
            if (!category || !data.folders[category]) return;
            data.folders[category].forEach(function (folder) {
                var option = document.createElement('option');
                option.value = folder;
                option.textContent = folder;
                select.appendChild(option);
            });
        }
        categorySelect.addEventListener('change', refresh);
        refresh();

        var params = new URLSearchParams(window.location.search);
        var initialCategory = params.get('vault_view');
        if (initialCategory && data.folders[initialCategory]) {
            categorySelect.value = initialCategory;
            refresh();
            var initialFolder = params.get('vault_folder');
            if (initialFolder) select.value = initialFolder;
        }
    }

    function wireAddAndEditForms(data) {
        addFolderSelector(document.querySelector('#add-panel form'), data);
        addFolderSelector(document.querySelector('#edit-panel form'), data);
    }

    function managementButton(label, handler) {
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.className = 'btn';
        button.style.cssText = 'padding:5px 8px;font-size:12px;background:#fff;color:#495057;border:1px solid #dee2e6;';
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            handler();
        });
        return button;
    }

    function wireFolderCards() {
        var category = currentCategory();
        document.querySelectorAll('.vault-folder-card').forEach(function (card) {
            if (card.getAttribute('data-folder-wired') === '1') return;
            var nameNode = card.querySelector('span:last-child');
            if (!nameNode) return;
            var folder = nameNode.textContent.trim();
            card.setAttribute('data-folder-wired', '1');
            card.style.cursor = 'pointer';
            card.setAttribute('role', 'link');
            card.setAttribute('tabindex', '0');
            card.addEventListener('click', function () {
                window.location.href = 'index.php?pane=records&vault_view=' + encodeURIComponent(category) + '&vault_folder=' + encodeURIComponent(folder);
            });
            card.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    window.location.href = 'index.php?pane=records&vault_view=' + encodeURIComponent(category) + '&vault_folder=' + encodeURIComponent(folder);
                }
            });

            var controls = document.createElement('span');
            controls.style.cssText = 'margin-left:auto;display:flex;gap:5px;flex:0 0 auto;';
            controls.appendChild(managementButton('Rename', function () {
                var newName = window.prompt('Rename folder:', folder);
                if (newName === null) return;
                newName = newName.trim();
                if (!newName || newName === folder) return;
                postAction('rename_folder', { category: category, folder: folder, new_folder: newName });
            }));
            controls.appendChild(managementButton('Delete', function () {
                if (!window.confirm('Delete the folder "' + folder + '"? Records in it will remain in the category, but will no longer belong to a folder.')) return;
                postAction('delete_folder', { category: category, folder: folder });
            }));
            card.appendChild(controls);
        });
    }

    function wireCategoryCards() {
        document.querySelectorAll('#view-panel a[href*="vault_view="]').forEach(function (card) {
            if (card.getAttribute('data-category-wired') === '1') return;
            var href = card.getAttribute('href') || '';
            var match = href.match(/[?&]vault_view=([^&]+)/);
            if (!match) return;
            var category = decodeURIComponent(match[1]);
            if (!category) return;
            card.setAttribute('data-category-wired', '1');
            var controls = document.createElement('span');
            controls.style.cssText = 'display:flex;justify-content:center;gap:6px;margin-top:8px;';
            controls.appendChild(managementButton('Rename', function () {
                var newName = window.prompt('Rename category:', category);
                if (newName === null) return;
                newName = newName.trim();
                if (!newName || newName === category) return;
                postAction('rename_category', { category: category, new_category: newName });
            }));
            controls.appendChild(managementButton('Delete', function () {
                if (!window.confirm('Delete the category "' + category + '"? Its folders will be removed and its records will be kept as uncategorised.')) return;
                postAction('delete_category', { category: category });
            }));
            card.appendChild(controls);
        });
    }

    function recordIdFromCard(card) {
        var onclick = card.getAttribute('onclick') || '';
        var start = onclick.indexOf('[');
        var end = onclick.lastIndexOf(']');
        if (start === -1 || end <= start) return '';
        try {
            var args = JSON.parse(onclick.slice(start, end + 1));
            return Array.isArray(args) ? String(args[5] || '') : '';
        } catch (e) {
            return '';
        }
    }

    function applyFolderView(data) {
        var folder = new URLSearchParams(window.location.search).get('vault_folder');
        if (!folder) return;
        var category = currentCategory();
        var matched = 0;
        document.querySelectorAll('.vault-record-card').forEach(function (card) {
            var id = recordIdFromCard(card);
            var record = data.records[id];
            var visible = !!record && record.category === category && record.folder === folder;
            card.style.display = visible ? '' : 'none';
            if (visible) matched++;
        });
        var title = document.querySelector('#records-panel h3');
        if (title) title.textContent = '📁 ' + folder;
        var empty = document.getElementById('vault-empty-message');
        if (matched === 0 && !empty) {
            empty = document.createElement('p');
            empty.id = 'vault-empty-message';
            empty.style.cssText = 'text-align:center;padding:20px;color:#777;';
            empty.textContent = 'No records in this folder yet.';
            var grid = document.getElementById('vault-record-grid');
            if (grid) grid.parentNode.insertBefore(empty, grid);
        }
        var back = document.querySelector('#records-panel > div:first-child a.btn');
        if (back) back.href = 'index.php?pane=records&vault_view=' + encodeURIComponent(category);
    }

    document.addEventListener('DOMContentLoaded', function () {
        showManagementStatus();
        fetchFolderData().then(function (data) {
            wireAddAndEditForms(data);
            wireFolderCards();
            wireCategoryCards();
            applyFolderView(data);
        }).catch(function (error) {
            console.error('SentryIQ folder UI initialization failed:', error);
        });
    });
}());
