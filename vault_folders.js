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

        var initialCategory = new URLSearchParams(window.location.search).get('vault_view');
        if (initialCategory && data.folders[initialCategory]) {
            categorySelect.value = initialCategory;
            refresh();
            var initialFolder = new URLSearchParams(window.location.search).get('vault_folder');
            if (initialFolder) select.value = initialFolder;
        }
    }

    function wireAddAndEditForms(data) {
        var addForm = document.querySelector('#add-panel form');
        addFolderSelector(addForm, data);
        var editForm = document.querySelector('#edit-panel form');
        addFolderSelector(editForm, data);
    }

    function wireFolderCards(data) {
        var category = currentCategory();
        document.querySelectorAll('.vault-folder-card').forEach(function (card) {
            var nameNode = card.querySelector('span:last-child');
            if (!nameNode) return;
            var folder = nameNode.textContent.trim();
            var link = document.createElement('a');
            link.href = 'index.php?pane=records&vault_view=' + encodeURIComponent(category) + '&vault_folder=' + encodeURIComponent(folder);
            link.className = 'vault-folder-card';
            link.style.cssText = card.getAttribute('style') || '';
            link.style.textDecoration = 'none';
            link.style.color = 'inherit';
            link.innerHTML = card.innerHTML;
            card.replaceWith(link);
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
        fetchFolderData().then(function (data) {
            wireAddAndEditForms(data);
            wireFolderCards(data);
            applyFolderView(data);
        }).catch(function (error) {
            console.error('SentryIQ folder UI initialization failed:', error);
        });
    });
}());
