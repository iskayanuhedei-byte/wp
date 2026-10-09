(function () {
    'use strict';

    const config = window.WTSR_ADMIN;
    const runButton = document.getElementById('wtsr-run');
    if (!config || !runButton) {
        return;
    }

    const stopButton = document.getElementById('wtsr-stop');
    const controls = document.getElementById('wtsr-mode-controls');
    const confirmation = document.getElementById('wtsr-delete-confirmation');
    const confirmDelete = document.getElementById('wtsr-confirm-delete');
    const status = document.getElementById('wtsr-status');
    const errorBox = document.getElementById('wtsr-error');
    const resultBody = document.getElementById('wtsr-results');
    const emptyMessage = document.getElementById('wtsr-empty');
    const settings = document.getElementById('wtsr-settings-form');
    const stateLabels = {
        healthy: 'Sudah baik',
        repairable: 'Dapat diperbaiki',
        fixed: 'Diperbaiki',
        missing: 'Tanpa sumber gambar',
        retry: 'Perlu dicoba lagi',
        deleted: 'Dihapus permanen'
    };
    let running = false;
    let stopping = false;
    let counts = freshCounts();

    function freshCounts() {
        return { scanned: 0, healthy: 0, repairable: 0, fixed: 0, missing: 0, retry: 0, deleted: 0 };
    }

    function mode() {
        const selected = controls.querySelector('input[name="wtsr_mode"]:checked');
        return selected ? selected.value : 'preview';
    }

    function updateMode() {
        const selected = mode();
        const destructive = selected === 'repair_delete';
        confirmation.hidden = !destructive;
        confirmDelete.disabled = running || !destructive;
        if (!destructive) {
            confirmDelete.checked = false;
        }
        runButton.textContent = selected === 'preview' ? 'Mulai pratinjau' : (
            destructive ? 'Mulai perbaikan + hapus permanen' : 'Mulai perbaikan'
        );
        runButton.disabled = running || (destructive && !confirmDelete.checked);
    }

    function updateCounts() {
        Object.keys(counts).forEach(function (key) {
            document.getElementById('wtsr-count-' + key).textContent = String(counts[key]);
        });
    }

    function showError(message) {
        errorBox.querySelector('p').textContent = message;
        errorBox.hidden = false;
    }

    function setBusy(busy) {
        running = busy;
        controls.querySelectorAll('input').forEach(function (input) {
            input.disabled = busy;
        });
        settings.querySelectorAll('textarea, button, input[type="submit"]').forEach(function (input) {
            input.disabled = busy;
        });
        stopButton.disabled = !busy;
        updateMode();
    }

    async function request(action, parameters) {
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', config.nonce);
        Object.keys(parameters).forEach(function (key) {
            body.set(key, String(parameters[key]));
        });
        let response;
        try {
            response = await fetch(config.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: body,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }
            });
        } catch (error) {
            throw new Error('Sambungan ke server terputus. Periksa hasil posting sebelum menjalankan ulang.');
        }
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('Server mengembalikan respons yang tidak dapat dibaca. Periksa log server dan jalankan ulang setelah gangguan teratasi.');
        }
        if (!response.ok || !payload || payload.success !== true) {
            const message = payload && payload.data && typeof payload.data.message === 'string'
                ? payload.data.message : 'Permintaan gagal. Muat ulang halaman jika sesi atau nonce telah kedaluwarsa.';
            const failure = new Error(message);
            failure.status = response.status;
            throw failure;
        }
        if (!payload.data || typeof payload.data !== 'object') {
            throw new Error('Hasil server tidak lengkap. Periksa hasil posting sebelum menjalankan ulang.');
        }
        return payload.data;
    }

    function normalizedState(result) {
        return result && Object.prototype.hasOwnProperty.call(stateLabels, result.state) ? result.state : 'retry';
    }

    function safeEditUrl(raw) {
        try {
            const url = new URL(raw, window.location.href);
            return /^https?:$/.test(url.protocol) && url.origin === window.location.origin ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function appendText(parent, tag, text, className) {
        const node = document.createElement(tag);
        node.textContent = text;
        if (className) {
            node.className = className;
        }
        parent.appendChild(node);
        return node;
    }

    function createRow(item) {
        const row = document.createElement('tr');
        const titleCell = document.createElement('td');
        const title = typeof item.title === 'string' && item.title ? item.title : '(Tanpa judul)';
        const editUrl = typeof item.edit_url === 'string' ? safeEditUrl(item.edit_url) : '';
        if (editUrl) {
            const link = appendText(titleCell, 'a', title);
            link.href = editUrl;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.className = 'wtsr-post-link';
        } else {
            appendText(titleCell, 'strong', title);
        }
        appendText(titleCell, 'span', 'ID ' + String(item.post_id), 'wtsr-post-id');
        row.appendChild(titleCell);
        const stateCell = document.createElement('td');
        row.appendChild(stateCell);
        const detailCell = document.createElement('td');
        row.appendChild(detailCell);
        row.wtsrCells = { title: titleCell, state: stateCell, detail: detailCell };
        resultBody.appendChild(row);
        emptyMessage.hidden = true;
        return row;
    }

    function renderRow(row, result, processing) {
        const state = normalizedState(result);
        row.className = 'wtsr-state-' + state;
        row.wtsrCells.state.textContent = '';
        appendText(row.wtsrCells.state, 'span', processing ? 'Sedang diproses…' : stateLabels[state], 'wtsr-state-label');
        row.wtsrCells.detail.textContent = '';
        appendText(row.wtsrCells.detail, 'p', typeof result.message === 'string' ? result.message : 'Hasil tidak lengkap; posting perlu diperiksa lagi.');
        if (Array.isArray(result.evidence) && result.evidence.length) {
            const evidence = document.createElement('ul');
            evidence.className = 'wtsr-evidence';
            result.evidence.forEach(function (source) {
                if (typeof source === 'string') {
                    appendText(evidence, 'li', source);
                }
            });
            row.wtsrCells.detail.appendChild(evidence);
        }
        if (state === 'deleted') {
            const link = row.wtsrCells.title.querySelector('a');
            if (link) {
                const title = document.createElement('strong');
                title.textContent = link.textContent;
                link.replaceWith(title);
            }
        }
    }

    function progressMessage(selected) {
        const prefix = selected === 'preview' ? 'Memeriksa' : 'Memeriksa dan memproses';
        return prefix + ' posting… ' + String(counts.scanned) + ' telah diperiksa.';
    }

    async function run() {
        if (running) {
            return;
        }
        const selected = mode();
        if (selected === 'repair_delete' && !confirmDelete.checked) {
            showError('Centang persetujuan penghapusan permanen sebelum menjalankan tindakan ini.');
            return;
        }
        stopping = false;
        counts = freshCounts();
        resultBody.textContent = '';
        emptyMessage.hidden = false;
        emptyMessage.textContent = 'Belum ada posting dengan masalah thumbnail yang ditemukan dalam sesi ini.';
        errorBox.hidden = true;
        updateCounts();
        setBusy(true);
        status.textContent = progressMessage(selected);
        let cursor = 0;
        let complete = false;
        try {
            while (!stopping && !complete) {
                const batch = await request('wtsr_scan', { cursor: cursor, limit: 1 });
                if (!batch || !Array.isArray(batch.items)) {
                    throw new Error('Hasil pemeriksaan server tidak lengkap. Tidak ada tindakan lanjutan yang dijalankan.');
                }
                for (const item of batch.items) {
                    const initialState = normalizedState(item);
                    counts.scanned += 1;
                    counts[initialState] += 1;
                    updateCounts();
                    if (initialState === 'healthy') {
                        if (!stopping) {
                            status.textContent = progressMessage(selected);
                        }
                        continue;
                    }
                    const row = createRow(item);
                    renderRow(row, item, false);
                    if (selected !== 'preview' && !stopping) {
                        renderRow(row, item, true);
                        let result;
                        try {
                            result = await request('wtsr_process', {
                                post_id: item.post_id,
                                mode: selected,
                                fingerprint: item.fingerprint,
                                confirm_delete: selected === 'repair_delete' ? 1 : 0
                            });
                        } catch (error) {
                            result = {
                                state: 'retry',
                                message: error.message,
                                evidence: Array.isArray(item.evidence) ? item.evidence : []
                            };
                            if (!error.status || error.status === 401 || error.status === 403 || error.status >= 500) {
                                stopping = true;
                                showError(error.message);
                            }
                        }
                        counts[initialState] -= 1;
                        counts[normalizedState(result)] += 1;
                        renderRow(row, result, false);
                        updateCounts();
                    }
                    if (!stopping) {
                        status.textContent = progressMessage(selected);
                    }
                }
                complete = batch.done === true;
                const nextCursor = Number(batch.next_cursor);
                if (!complete && (!Number.isSafeInteger(nextCursor) || nextCursor <= cursor)) {
                    throw new Error('Kursor pemeriksaan tidak maju. Proses dihentikan untuk mencegah pengulangan.');
                }
                cursor = nextCursor;
            }
            status.textContent = stopping
                ? 'Dihentikan. ' + String(counts.scanned) + ' posting telah diperiksa. Hasil yang sudah tersimpan tetap berlaku; mulai lagi untuk pemeriksaan baru.'
                : 'Selesai. ' + String(counts.scanned) + ' posting telah diperiksa. Lihat status dan keterangan setiap hasil.';
        } catch (error) {
            showError(error.message);
            status.textContent = 'Proses berhenti karena gangguan. ' + String(counts.scanned) + ' posting telah diperiksa; periksa hasil sebelum menjalankan ulang.';
        } finally {
            if (selected === 'repair_delete') {
                confirmDelete.checked = false;
            }
            setBusy(false);
            stopping = false;
        }
    }

    controls.querySelectorAll('input[name="wtsr_mode"]').forEach(function (input) {
        input.addEventListener('change', updateMode);
    });
    confirmDelete.addEventListener('change', updateMode);
    runButton.addEventListener('click', run);
    stopButton.addEventListener('click', function () {
        stopping = true;
        stopButton.disabled = true;
        status.textContent = 'Permintaan berhenti diterima. Menunggu operasi yang sedang berjalan selesai; posting berikutnya tidak akan diproses.';
    });
    window.addEventListener('beforeunload', function (event) {
        if (running) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    updateMode();
}());
