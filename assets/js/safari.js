/*
 * Safari 26.5+/26.6 multipart upload workaround.
 *
 * WebKit can send a zero-byte multipart/form-data request when a picked
 * File object is appended directly to FormData. Reading the File into
 * memory and wrapping it in a fresh Blob avoids that WebKit path.
 */
(function () {
    'use strict';

    document.addEventListener('submit', async function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.id !== 'gallery-upload-form') return;

        // This capture-phase handler replaces the older fetch/File upload
        // handler in gallery.php on affected Safari/WebKit versions.
        event.preventDefault();
        event.stopImmediatePropagation();

        const input = form.querySelector('input[type="file"][name="photos[]"]');
        const message = document.getElementById('gallery-message');
        const button = form.querySelector('button[type="submit"]');
        const files = Array.from(input?.files || []);

        if (!files.length) {
            if (message) message.textContent = 'Please select at least one photo.';
            return;
        }

        if (button) button.disabled = true;
        if (input) input.disabled = true;

        const csrfInput = form.querySelector('input[name="csrf_token"]');
        const csrf = csrfInput ? csrfInput.value : '';
        const progress = document.getElementById('gallery-upload-progress');
        const progressFile = document.getElementById('gallery-upload-progress-file');
        const progressStatus = document.getElementById('gallery-upload-progress-status');
        const progressBar = document.getElementById('gallery-upload-progress-bar');
        const progressPercent = document.getElementById('gallery-upload-progress-percent');
        const progressTrack = progress?.querySelector('[role="progressbar"]');
        const totals = { stored: 0, duplicate: 0, rejected: 0 };
        const failures = [];

        let displayedPercent = 2;
        let progressTimer = null;

        function setProgress(percent) {
            displayedPercent = Math.max(displayedPercent, Math.min(90, percent));
            if (progressBar) progressBar.style.width = displayedPercent + '%';
            if (progressPercent) progressPercent.textContent = displayedPercent + '%';
            if (progressTrack) progressTrack.setAttribute('aria-valuenow', String(displayedPercent));
        }

        function startProgress() {
            if (progress) {
                progress.classList.add('active');
                progress.style.display = 'block';
            }
            setProgress(2);
            if (progressTimer !== null) window.clearInterval(progressTimer);
            progressTimer = window.setInterval(function () {
                if (displayedPercent < 88) setProgress(displayedPercent + 1);
            }, 180);
        }

        function stopProgress() {
            if (progressTimer !== null) {
                window.clearInterval(progressTimer);
                progressTimer = null;
            }
        }

        try {
            startProgress();

            for (let index = 0; index < files.length; index++) {
                const file = files[index];
                if (progressFile) progressFile.textContent = `Photo ${index + 1} of ${files.length}: ${file.name}`;
                if (progressStatus) progressStatus.textContent = 'Preparing upload…';
                if (message) message.textContent = `Uploading photo ${index + 1} of ${files.length}…`;

                try {
                    // Safari/WebKit workaround: copy the picked File into a
                    // fresh in-memory Blob before appending it to FormData.
                    const bytes = await file.arrayBuffer();
                    if (progressStatus) progressStatus.textContent = 'Uploading…';
                    const safeBlob = new Blob([bytes], { type: file.type || 'application/octet-stream' });
                    const uploadData = new FormData();
                    uploadData.append('csrf_token', csrf);
                    uploadData.append('photos[]', safeBlob, file.name);

                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: uploadData,
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-Token': csrf
                        },
                        cache: 'no-store'
                    });

                    const text = await response.text();
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (_) {
                        throw new Error(`The upload returned an invalid response (HTTP ${response.status}): ${text.trim().slice(0, 500) || '[empty response]'}`);
                    }

                    if (!response.ok || !Array.isArray(data.results)) {
                        totals.rejected++;
                        failures.push(`${file.name}: ${data.message || 'Upload failed.'}`);
                        setProgress(100);
                        if (progressPercent) progressPercent.textContent = 'Failed';
                        continue;
                    }

                    const result = data.results[0];
                    if (result?.status === 'stored') totals.stored++;
                    else if (result?.status === 'duplicate') totals.duplicate++;
                    else {
                        totals.rejected++;
                        failures.push(`${file.name}: ${result?.message || 'Upload rejected.'}`);
                    }
                    setProgress(100);
                    if (progressStatus) progressStatus.textContent = result?.status === 'stored'
                        ? 'Uploaded and processed successfully.'
                        : result?.status === 'duplicate'
                            ? 'Already in the gallery (duplicate).'
                            : 'Upload rejected.';
                } catch (error) {
                    totals.rejected++;
                    failures.push(`${file.name}: ${error.message || 'Upload failed.'}`);
                    setProgress(100);
                    if (progressPercent) progressPercent.textContent = 'Failed';
                    if (progressStatus) progressStatus.textContent = 'Upload failed.';
                }
            }

            if (message) {
                message.textContent = `Upload complete: ${totals.stored} stored, ${totals.duplicate} duplicate, ${totals.rejected} rejected.`;
                if (failures.length) {
                    const details = document.createElement('div');
                    details.style.marginTop = '8px';
                    details.style.color = '#dc3545';
                    details.textContent = failures.join(' | ');
                    message.appendChild(details);
                }
            }

            if (totals.stored > 0) setTimeout(() => window.location.reload(), 1200);
        } finally {
            stopProgress();
            if (button) button.disabled = false;
            if (input) input.disabled = false;
        }
    }, true);

    // Mobile-only Gallery controls. Desktop keeps the existing forms visible.
    function setupMobileGalleryActionPanel(selector, buttonText, panelLabel) {
        if (!window.matchMedia('(max-width: 600px)').matches) return;

        const panel = document.querySelector(selector);
        if (!panel || panel.dataset.mobileActionPanelReady === '1') return;

        panel.dataset.mobileActionPanelReady = '1';
        const heading = panel.querySelector('h3');
        const form = panel.querySelector('form');
        const message = panel.querySelector('.gallery-message');
        if (!form) return;

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn btn-primary gallery-action-toggle';
        toggle.textContent = buttonText;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', panelLabel);

        const details = document.createElement('div');
        details.className = 'gallery-action-details';
        details.hidden = true;

        if (heading) details.appendChild(heading);
        details.appendChild(form);
        if (message) details.appendChild(message);

        panel.innerHTML = '';
        panel.appendChild(toggle);
        panel.appendChild(details);

        toggle.addEventListener('click', function () {
            const open = !details.hidden;
            details.hidden = open;
            toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
            toggle.textContent = open ? buttonText : `✕ Close ${panelLabel}`;

            if (!open) {
                const firstInput = details.querySelector('input:not([type="hidden"])');
                if (firstInput) firstInput.focus();
            }
        });
    }

    function setupMobileGalleryActionPanels() {
        if (!window.matchMedia('(max-width: 600px)').matches) return;

        const style = document.createElement('style');
        style.textContent = `
            .gallery-action-toggle { width: 100%; box-sizing: border-box; justify-content: center; margin-top: 0; }
            .gallery-action-details[hidden] { display: none !important; }
            .gallery-action-details { margin-top: 12px; }
            .gallery-action-details .gallery-upload,
            .gallery-action-details .gallery-albums { border: 0; padding: 0; background: transparent; }
        `;
        document.head.appendChild(style);

        setupMobileGalleryActionPanel('.gallery-upload', '📷 Upload Photo', 'Upload Photo');
        setupMobileGalleryActionPanel('.gallery-albums', '➕ Create Album', 'Create Album');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupMobileGalleryActionPanels, { once: true });
    } else {
        setupMobileGalleryActionPanels();
    }
}());
