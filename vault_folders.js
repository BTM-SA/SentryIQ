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

    function styleCreateFolderForm() {
        var form = document.getElementById('create-folder-form');
        if (!form) return;
        form.classList.add('vault-create-folder-form');
        function apply() {
            var mobile = window.matchMedia('(max-width: 420px)').matches;
            var input = form.querySelector('input[name="folder"]');
            var buttons = form.querySelectorAll('button');
            if (mobile) {
                form.style.setProperty('display', form.style.display === 'none' ? 'none' : 'flex', 'important');
                form.style.setProperty('flex-direction', 'column', 'important');
                form.style.setProperty('align-items', 'stretch', 'important');
                form.style.setProperty('gap', '10px', 'important');
                form.style.setProperty('margin', '0 0 16px', 'important');
                form.style.setProperty('padding', '14px', 'important');
                form.style.setProperty('border', '0', 'important');
                form.style.setProperty('border-radius', '16px', 'important');
                form.style.setProperty('background', 'var(--neo-surface, #e7ebf1)', 'important');
                form.style.setProperty('box-shadow', '8px 8px 16px var(--neo-shadow-dark, rgba(142,151,166,.55)), -8px -8px 16px var(--neo-shadow-light, rgba(255,255,255,.96))', 'important');
                if (input) {
                    input.style.setProperty('width', '100%', 'important');
                    input.style.setProperty('flex', '0 0 auto', 'important');
                    input.style.setProperty('min-width', '0', 'important');
                    input.style.setProperty('height', '46px', 'important');
                    input.style.setProperty('font-size', '16px', 'important');
                }
                buttons.forEach(function (button) {
                    button.style.setProperty('width', '100%', 'important');
                    button.style.setProperty('min-height', '46px', 'important');
                    button.style.setProperty('white-space', 'normal', 'important');
                    button.style.setProperty('border-radius', '14px', 'important');
                });
            } else {
                ['flex-direction','align-items','gap','padding','border','border-radius','background','box-shadow'].forEach(function (property) { form.style.removeProperty(property); });
                if (input) ['width','flex','min-width','height','font-size'].forEach(function (property) { input.style.removeProperty(property); });
                buttons.forEach(function (button) { ['width','min-height','white-space','border-radius'].forEach(function (property) { button.style.removeProperty(property); }); });
            }
        }
        apply();
        window.addEventListener('resize', apply);
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

    function confirmDelete(message, handler) {
        if (window.confirm(message)) handler();
    }

    function addSwipeDelete(card, message, handler) {
        if (!card || card.getAttribute('data-swipe-delete-wired') === '1') return;
        card.setAttribute('data-swipe-delete-wired', '1');
        card.style.position = 'relative';
        card.style.overflow = 'visible';
        card.style.touchAction = 'pan-y';
        card.style.transition = 'transform 180ms ease';
        var action = document.createElement('button');
        action.type = 'button';
        action.textContent = 'Delete';
        action.setAttribute('aria-label', 'Delete');
        action.className = 'vault-swipe-delete';
        action.style.cssText = 'position:absolute;top:0;right:-78px;width:78px;height:100%;border:0;border-radius:0 10px 10px 0;background:#dc3545;color:#fff;font-weight:700;display:none;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,.12);';
        action.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            confirmDelete(message, handler);
        });
        card.appendChild(action);
        function updateSwipeMode() {
            var mobile = window.matchMedia('(max-width: 700px)').matches;
            action.style.display = 'none';
            if (!mobile) card.style.transform = '';
        }
        updateSwipeMode();
        window.addEventListener('resize', updateSwipeMode);
        var startX = 0, startY = 0, deltaX = 0, tracking = false, moved = false;
        card.addEventListener('touchstart', function (event) {
            if (!window.matchMedia('(max-width: 700px)').matches || event.touches.length !== 1) return;
            startX = event.touches[0].clientX;
            startY = event.touches[0].clientY;
            deltaX = 0;
            tracking = true;
            moved = false;
        }, { passive: true });
        card.addEventListener('touchmove', function (event) {
            if (!tracking || event.touches.length !== 1) return;
            deltaX = event.touches[0].clientX - startX;
            var deltaY = event.touches[0].clientY - startY;
            if (Math.abs(deltaY) > Math.abs(deltaX) || deltaX > 0) return;
            if (Math.abs(deltaX) > 10) moved = true;
            card.style.transform = 'translateX(' + Math.max(deltaX, -78) + 'px)';
        }, { passive: true });
        card.addEventListener('touchend', function () {
            if (!tracking) return;
            tracking = false;
            card.style.transform = deltaX <= -45 ? 'translateX(-78px)' : '';
        }, { passive: true });
        card.addEventListener('touchcancel', function () {
            tracking = false;
            card.style.transform = '';
        }, { passive: true });
        card.addEventListener('click', function (event) {
            if (moved) {
                event.preventDefault();
                event.stopPropagation();
                moved = false;
            }
        }, true);
    }

    function folderUrl(category, folder) {
        return 'index.php?pane=records&vault_view=' + encodeURIComponent(category) + '&vault_folder=' + encodeURIComponent(folder);
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
            function openFolder() { window.location.href = folderUrl(category, folder); }
            card.addEventListener('click', openFolder);
            card.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openFolder();
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
                confirmDelete('Delete the folder "' + folder + '"? Records in it will remain in the category, but will no longer belong to a folder.', function () {
                    postAction('delete_folder', { category: category, folder: folder });
                });
            }));
            card.appendChild(controls);
            addSwipeDelete(card, 'Delete the folder "' + folder + '"? Records in it will remain in the category, but will no longer belong to a folder.', function () {
                postAction('delete_folder', { category: category, folder: folder });
            });
        });
    }

    function categoryActionUrl(action, category) {
        return 'index.php?pane=records&vault_view=' + encodeURIComponent(category) + '&vault_action=' + encodeURIComponent(action);
    }

    function addCategoryOptions(card, category) {
        if (!card || card.getAttribute('data-category-options-wired') === '1') return;
        card.setAttribute('data-category-options-wired', '1');

        var wrapper = document.createElement('div');
        wrapper.className = 'vault-category-card-wrap';
        wrapper.style.cssText = 'position:relative;min-width:0;';
        card.parentNode.insertBefore(wrapper, card);
        wrapper.appendChild(card);

        var optionsButton = document.createElement('button');
        optionsButton.type = 'button';
        optionsButton.className = 'vault-category-options-button';
        optionsButton.textContent = 'Options';
        optionsButton.setAttribute('aria-expanded', 'false');
        optionsButton.setAttribute('aria-label', 'Options for ' + category);
        optionsButton.style.cssText = 'display:none;position:absolute;right:8px;top:8px;z-index:5;min-height:38px;padding:7px 12px;border:1px solid #dee2e6;border-radius:10px;background:#fff;color:#212529;font-weight:600;box-shadow:0 3px 8px rgba(0,0,0,.10);';

        var menu = document.createElement('div');
        menu.className = 'vault-category-options-menu';
        menu.style.cssText = 'display:none;position:absolute;right:8px;top:52px;z-index:20;min-width:190px;padding:7px;border:1px solid #dee2e6;border-radius:12px;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.16);';

        function menuButton(label, handler, destructive) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.style.cssText = 'display:block;width:100%;padding:11px 12px;text-align:left;border:0;border-radius:8px;background:transparent;color:' + (destructive ? '#b02a37' : '#212529') + ';font-size:15px;cursor:pointer;';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                menu.style.display = 'none';
                optionsButton.setAttribute('aria-expanded', 'false');
                handler();
            });
            return button;
        }

        menu.appendChild(menuButton('＋ Add Record', function () {
            window.location.href = 'index.php?pane=add&vault_view=' + encodeURIComponent(category);
        }, false));
        menu.appendChild(menuButton('📁 Create Folder', function () {
            window.location.href = categoryActionUrl('create_folder', category);
        }, false));
        menu.appendChild(menuButton('✏ Rename', function () {
            var newName = window.prompt('Rename category:', category);
            if (newName === null) return;
            newName = newName.trim();
            if (!newName || newName === category) return;
            postAction('rename_category', { category: category, new_category: newName });
        }, false));
        menu.appendChild(menuButton('🗑 Delete', function () {
            confirmDelete('Delete the category "' + category + '"? Its records will be kept but moved to uncategorised.', function () {
                postAction('delete_category', { category: category });
            });
        }, true));

        optionsButton.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var open = menu.style.display === 'block';
            menu.style.display = open ? 'none' : 'block';
            optionsButton.setAttribute('aria-expanded', open ? 'false' : 'true');
        });

        wrapper.appendChild(optionsButton);
        wrapper.appendChild(menu);

        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) {
                menu.style.display = 'none';
                optionsButton.setAttribute('aria-expanded', 'false');
            }
        });

        function updateCategoryOptionsMode() {
            var mobile = window.matchMedia('(max-width: 700px)').matches;
            optionsButton.style.display = mobile ? 'block' : 'none';
            if (!mobile) {
                menu.style.display = 'none';
                optionsButton.setAttribute('aria-expanded', 'false');
            }
        }
        updateCategoryOptionsMode();
        window.addEventListener('resize', updateCategoryOptionsMode);
    }

    function wireCategoryCards() {
        document.querySelectorAll('#view-panel a[href*="vault_view="]').forEach(function (card) {
            if (card.getAttribute('data-category-wired') === '1') return;
            var href = card.getAttribute('href') || '';
            var match = href.match(/[?&]vault_view=([^&]+)/);
            if (!match) return;
            var category = decodeURIComponent(match[1]);
            if (!category) return;
            addCategoryOptions(card, category);
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

    function applyCategoryAction() {
        var params = new URLSearchParams(window.location.search);
        if (params.get('vault_action') !== 'create_folder') return;
        var form = document.getElementById('create-folder-form');
        if (!form) return;
        form.style.display = 'flex';
        var input = form.querySelector('input[name="folder"]');
        if (input) input.focus();
    }

    document.addEventListener('DOMContentLoaded', function () {
        showManagementStatus();
        styleCreateFolderForm();
        wireFolderCards();
        wireCategoryCards();
        fetchFolderData().then(function (data) {
            wireAddAndEditForms(data);
            applyFolderView(data);
            applyCategoryAction();
        }).catch(function (error) {
            console.error('SentryIQ folder data initialization failed:', error);
            applyCategoryAction();
        });
    });
})();
