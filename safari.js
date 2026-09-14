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
        const totals = { stored: 0, duplicate: 0, rejected: 0 };
        const failures = [];

        try {
            for (let index = 0; index < files.length; index++) {
                const file = files[index];
                if (message) message.textContent = `Uploading ${index + 1} of ${files.length}: ${file.name}`;

                try {
                    // Safari/WebKit workaround: copy the picked File into a
                    // fresh in-memory Blob before appending it to FormData.
                    const bytes = await file.arrayBuffer();
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
                        continue;
                    }

                    const result = data.results[0];
                    if (result?.status === 'stored') totals.stored++;
                    else if (result?.status === 'duplicate') totals.duplicate++;
                    else {
                        totals.rejected++;
                        failures.push(`${file.name}: ${result?.message || 'Upload rejected.'}`);
                    }
                } catch (error) {
                    totals.rejected++;
                    failures.push(`${file.name}: ${error.message || 'Upload failed.'}`);
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
            if (button) button.disabled = false;
            if (input) input.disabled = false;
        }
    }, true);
}());
