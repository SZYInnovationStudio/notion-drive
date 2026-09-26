(() => {
    'use strict';

    const csrf = document.body.dataset.csrf || '';
    const toast = document.getElementById('toast');

    // 支付下单后会回到本页并带上收银台地址：脚本导航不受 CSP form-action 约束，用它代替跨站 302
    const checkoutUrl = document.body.dataset.checkoutUrl || '';
    if (checkoutUrl) {
        // 用 replace 而非 assign，避免用户从收银台返回时又被自动送回
        window.location.replace(checkoutUrl);
    }
    const allowed = new Set(['aac','adts','mid','midi','mp3','mpga','m4a','m4b','mp4','oga','ogg','opus','wav','wma','weba','flac','pdf','txt','csv','json','js','ts','tsx','py','doc','dot','docx','dotx','xls','xlt','xla','xlsx','xltx','ppt','pot','pps','ppa','pptx','potx','rtf','md','markdown','html','htm','epub','xml','css','odt','ods','odp','ics','yaml','yml','tsv','zip','gz','gzip','tar','7z','bz2','rar','gif','heic','jpeg','jpg','png','svg','tif','tiff','webp','ico','bmp','avif','apng','amv','asf','wmv','avi','f4v','flv','gifv','m4v','mkv','webm','mov','qt','mpeg','ogv','3gp','3g2','dwg','dxf','dwf','rvt','rfa','fbx','3dm','step','stp','iges','igs','stl','obj','3mf','gltf','glb','dae','usdz']);

    const notify = (message, type = 'success') => {
        if (!toast) return;
        toast.textContent = message;
        toast.className = `toast visible ${type}`;
        window.setTimeout(() => toast.classList.remove('visible'), 2600);
    };

    // 上传成功后会刷新文件列表，提示语留到刷新之后再弹出
    const uploadNotice = window.sessionStorage.getItem('uploadNotice');
    if (uploadNotice) {
        window.sessionStorage.removeItem('uploadNotice');
        notify(uploadNotice);
    }

    const formatBytes = bytes => {
        if (!bytes) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), 3);
        return `${(bytes / (1024 ** index)).toFixed(index ? 1 : 0)} ${units[index]}`;
    };

    const fileSearch = document.getElementById('fileSearch');
    if (fileSearch) {
        fileSearch.addEventListener('input', () => {
            const value = fileSearch.value.trim().toLocaleLowerCase();
            document.querySelectorAll('#fileTable tbody tr').forEach(row => {
                row.hidden = value !== '' && !row.dataset.fileName.includes(value);
            });
        });
    }

    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-action]');
        if (!button) return;
        const row = button.closest('[data-file-id]');
        if (!row) return;
        const id = row.dataset.fileId;
        const action = button.dataset.action;
        const body = new URLSearchParams({ id, action });

        if (action === 'rename') {
            const current = row.querySelector('.display-name')?.textContent.trim() || '';
            const name = window.prompt('输入新的显示名称', current);
            if (name === null || name.trim() === '' || name.trim() === current) return;
            body.set('name', name.trim());
        } else if (action === 'delete') {
            if (!window.confirm('确认删除这个文件？删除后将从 Notion 页面归档。')) return;
        }

        button.disabled = true;
        try {
            const response = await fetch('api/file.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || '操作失败');
            if (action === 'rename') {
                row.querySelector('.display-name').textContent = data.name;
            } else {
                row.remove();
            }
            notify(data.message);
        } catch (error) {
            notify(error.message || '操作失败', 'error');
        } finally {
            button.disabled = false;
        }
    });

    // 图形验证码「换一张」：带时间戳重新请求，避免浏览器缓存旧图片
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-captcha-refresh]');
        if (!button) return;
        const image = document.getElementById(button.dataset.captchaRefresh);
        if (image) image.src = `captcha.php?t=${Date.now()}`;
    });

    const dropZone = document.getElementById('dropZone');
    const input = document.getElementById('fileInput');
    const browse = document.getElementById('browseButton');
    const queue = document.getElementById('queue');
    if (!dropZone || !input || !browse || !queue) return;

    const limit = Number(document.body.dataset.limit || 0);            // 单个文件上限
    const remainingRaw = document.body.dataset.remaining ?? '';        // 剩余容量，'' 表示不限总容量
    const remaining = remainingRaw === '' ? null : Number(remainingRaw);
    let busy = false;
    let uploaded = 0;
    let overwritten = 0;
    let renamed = 0;
    const pending = [];
    const validate = file => {
        const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
        if (new TextEncoder().encode(file.name).length > 900) return '文件名超过 900 字节';
        if (!allowed.has(extension)) return 'Notion 不支持该文件类型';
        if (!file.size) return '不能上传空文件';
        if (limit <= 0) return '当前账户无法上传文件';
        if (remaining !== null && remaining <= 0) return '个人空间容量已用完';
        if (file.size > limit) return `单个文件不能超过 ${formatBytes(limit)}`;
        if (remaining !== null && file.size > remaining) return `剩余空间不足，还可上传 ${formatBytes(remaining)}`;
        return '';
    };

    /**
     * 与服务端 nextAvailableFilename 一致的编号预览：1.txt → 1(1).txt（编号插在扩展名之前）。
     * 依据当前列表里已存在的文件名计算第一个空闲编号，而不是固定显示 (1)。
     */
    const previewRename = name => {
        const index = name.lastIndexOf('.');
        const stem = index > 0 ? name.slice(0, index) : name;
        const suffix = index > 0 ? name.slice(index) : '';
        const existing = new Set(
            [...document.querySelectorAll('#fileTable tbody tr')]
                .map(row => (row.dataset.fileName || '').trim().toLowerCase())
        );
        for (let i = 1; i <= 999; i += 1) {
            const candidate = `${stem}(${i})${suffix}`;
            if (!existing.has(candidate.toLowerCase())) return candidate;
        }
        return `${stem}(新)${suffix}`;
    };

    /** 上传前向服务器确认这些文件名是否已存在；失败时按「无重名」处理，交由服务端兜底编号。 */
    const findDuplicates = async names => {
        if (!names.length) return new Set();
        try {
            const response = await fetch('api/upload.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body: new URLSearchParams({ mode: 'check', names: JSON.stringify(names) })
            });
            const data = await response.json();
            return new Set(response.ok && data.ok ? (data.duplicates || []) : []);
        } catch (_) {
            return new Set();
        }
    };

    /** 界面内确认弹窗：返回 true 表示覆盖旧文件，false 表示自动编号。 */
    const confirmOverwrite = (name, target) => new Promise(resolve => {
        const dialog = document.createElement('dialog');
        dialog.className = 'duplicate-dialog';
        dialog.innerHTML = '<h2>文件已存在</h2><p>当前空间里已经有同名文件，请选择处理方式。</p><div class="duplicate-name">文件名<strong></strong></div><p class="duplicate-hint"></p><div class="duplicate-actions"><button type="button" class="secondary-button" data-choice="rename">自动编号</button><button type="button" class="primary-button compact" data-choice="overwrite">覆盖并上传</button></div>';
        dialog.querySelector('.duplicate-name strong').textContent = name;
        dialog.querySelector('.duplicate-hint').textContent = `「覆盖并上传」会删除旧文件并替换为新文件；「自动编号」会保留两者，新文件命名为 ${target}。`;

        const finish = choice => {
            dialog.close();
            resolve(choice === 'overwrite');
        };

        dialog.querySelector('[data-choice="overwrite"]').addEventListener('click', () => finish('overwrite'));
        dialog.querySelector('[data-choice="rename"]').addEventListener('click', () => finish('rename'));
        // 按 Esc 等同于「自动编号」，与浏览器原生确认框取消时的语义保持一致
        dialog.addEventListener('cancel', event => {
            event.preventDefault();
            finish('rename');
        });
        dialog.addEventListener('close', () => dialog.remove());

        document.body.appendChild(dialog);
        dialog.showModal();
        dialog.querySelector('[data-choice="overwrite"]').focus();
    });

    const addFiles = async files => {
        const entries = [];
        [...files].forEach(file => {
            const error = validate(file);
            const item = document.createElement('article');
            item.className = `queue-item${error ? ' failed' : ''}`;
            item.innerHTML = '<span class="file-kind"></span><div class="queue-meta"><b></b><small></small><progress class="progress-track" max="100" value="0"></progress></div><span class="queue-status"></span>';
            item.querySelector('.file-kind').textContent = (file.name.split('.').pop() || 'FILE').toUpperCase();
            item.querySelector('.queue-meta b').textContent = file.name;
            item.querySelector('.queue-meta small').textContent = formatBytes(file.size);
            item.querySelector('.queue-status').textContent = error || '等待上传';
            queue.appendChild(item);
            if (!error) entries.push({ file, item, duplicateAction: '' });
        });

        const duplicates = await findDuplicates(entries.map(entry => entry.file.name));
        for (const entry of entries) {
            if (!duplicates.has(entry.file.name)) continue;
            const target = previewRename(entry.file.name);
            const overwrite = await confirmOverwrite(entry.file.name, target);
            entry.duplicateAction = overwrite ? 'overwrite' : 'rename';
            entry.item.querySelector('.queue-status').textContent = overwrite
                ? '等待上传（覆盖同名旧文件）'
                : `等待上传（将编号为 ${target}）`;
        }

        pending.push(...entries);
        runQueue();
    };

    const failureText = (statusCode, data) => {
        const message = data && data.message ? data.message : '';
        const suffix = data && data.reference ? `（参考号 ${data.reference}，可在「运行日志」中查询）` : '';
        if (message) return `${message}${suffix}`;
        if (statusCode === 0) return '网络连接中断，上传未完成，请检查网络后重试';
        if (statusCode === 413) return '文件体积超过服务器允许的上传大小（HTTP 413）';
        if (statusCode === 401) return '登录已过期，请刷新页面重新登录';
        return `上传失败：服务器返回 HTTP ${statusCode}，请重试或查看「运行日志」`;
    };

    /** 分块协议的控制请求（start / finish / 重试），请求体很小，不需要上传进度。 */
    const controlRequest = async fields => {
        const form = new FormData();
        Object.entries(fields).forEach(([key, value]) => form.append(key, String(value)));
        const response = await fetch('api/upload.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
            body: form
        });
        let data = {};
        try { data = JSON.parse(await response.text()) || {}; } catch (_) {}
        return { ok: response.ok, status: response.status, data };
    };

    /** 上传单个分块；自带进度回调，409「顺序不符」原样返回给调用方自同步。 */
    const sendChunk = (token, index, blob, onProgress) => new Promise(resolve => {
        const form = new FormData();
        form.append('action', 'chunk');
        form.append('upload_token', token);
        form.append('index', String(index));
        form.append('chunk', blob, 'chunk.bin');
        const xhr = new XMLHttpRequest();
        xhr.upload.addEventListener('progress', event => {
            if (event.lengthComputable) onProgress(event.loaded);
        });
        const finish = (statusCode, data) => resolve({ ok: statusCode >= 200 && statusCode < 300, status: statusCode, data });
        xhr.addEventListener('load', () => {
            let data = {};
            try { data = JSON.parse(xhr.responseText) || {}; } catch (_) {}
            finish(xhr.status, data);
        });
        xhr.addEventListener('error', () => finish(0, { message: '网络连接失败，未能与服务器通信' }));
        xhr.addEventListener('abort', () => finish(0, { message: '上传被中断，请重试' }));
        xhr.addEventListener('timeout', () => finish(0, { message: '上传超时，服务器未在预期时间内响应' }));
        xhr.open('POST', 'api/upload.php');
        // 单个分块体积有限，超时只用于在网络挂死时结束等待
        xhr.timeout = 600000;
        xhr.setRequestHeader('X-CSRF-Token', csrf);
        xhr.send(form);
    });

    /**
     * 分块上传：start 建立会话 → 逐块 chunk（进度条按已发送字节推进）→ finish 由服务端拼接并双写 Notion。
     * 服务端严格按顺序接收，顺序不符时用返回的 received 自同步后继续，不必重传整个文件。
     */
    const upload = ({ file, item, duplicateAction }) => new Promise(async resolve => {
        const status = item.querySelector('.queue-status');
        const bar = item.querySelector('.progress-track');
        const fail = detail => {
            status.textContent = detail;
            status.title = detail;
            item.classList.add('failed');
            resolve(false);
        };
        const setProgress = percent => {
            const value = Math.min(98, Math.max(0, percent));
            bar.value = Math.max(bar.value, value);
            status.textContent = `上传中 ${Math.round(value)}%`;
        };

        let started;
        try {
            started = await controlRequest({ action: 'start', filename: file.name, size: file.size, duplicate_action: duplicateAction || '' });
        } catch (_) {
            return fail('网络连接失败，未能与服务器通信');
        }
        if (!started.ok || !started.data.ok) return fail(failureText(started.status, started.data));

        const token = started.data.upload_token;
        const chunkSize = Number(started.data.chunk_size) || file.size || 1;
        let total = Number(started.data.total) || 1;
        // 服务端可能因重名把文件自动编号，以服务端返回的名称为准
        if (started.data.duplicate === 'overwrite') overwritten += 1;
        else if (started.data.duplicate === 'rename') renamed += 1;
        if (started.data.name && started.data.name !== file.name) {
            item.querySelector('.queue-meta b').textContent = started.data.name;
        }
        setProgress(0);

        let index = 0;
        while (index < total) {
            const begin = index * chunkSize;
            const blob = file.slice(begin, Math.min(begin + chunkSize, file.size));
            const result = await sendChunk(token, index, blob, loaded => setProgress((begin + loaded) / file.size * 98));
            if (result.status === 409 && result.data.code === 'chunk_out_of_order') {
                // 服务端已收到的分块数与本地不一致：按服务端进度继续发送剩余分块
                index = Number(result.data.received) || 0;
                total = Number(result.data.total) || total;
                continue;
            }
            if (!result.ok || !result.data.ok) return fail(failureText(result.status, result.data));
            index += 1;
        }

        bar.value = 99;
        status.textContent = '正在写入 Notion';
        let finished;
        try {
            finished = await controlRequest({ action: 'finish', upload_token: token });
        } catch (_) {
            return fail('网络连接中断，文件已送达服务器但未确认结果，请刷新页面查看是否已上传成功');
        }
        if (!finished.ok || !finished.data.ok) return fail(failureText(finished.status, finished.data));

        bar.value = 100;
        status.textContent = '上传完成';
        item.classList.add('complete');
        uploaded += 1;
        resolve(true);
    });

    async function runQueue() {
        if (busy) return;
        busy = true;
        let completed = false;
        while (pending.length) completed = (await upload(pending.shift())) || completed;
        busy = false;
        if (completed) {
            const extras = [];
            if (overwritten) extras.push(`覆盖 ${overwritten} 个同名文件`);
            if (renamed) extras.push(`自动编号 ${renamed} 个同名文件`);
            const suffix = extras.length ? `（${extras.join('，')}）` : '';
            window.sessionStorage.setItem('uploadNotice', `上传成功：已上传 ${uploaded} 个文件并附加到 Notion。${suffix}`);
            // 新文件按时间倒序排在第一页，若停在后面的页码会看不到刚上传的文件，因此回到第一页
            const url = new URL(window.location.href);
            url.searchParams.delete('page');
            window.location.replace(url.toString());
        }
    }

    browse.addEventListener('click', event => { event.stopPropagation(); input.click(); });
    dropZone.addEventListener('click', event => { if (!event.target.closest('button')) input.click(); });
    dropZone.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); input.click(); } });
    input.addEventListener('change', () => { addFiles(input.files); input.value = ''; });
    ['dragenter', 'dragover'].forEach(type => dropZone.addEventListener(type, event => { event.preventDefault(); dropZone.classList.add('dragging'); }));
    ['dragleave', 'drop'].forEach(type => dropZone.addEventListener(type, event => { event.preventDefault(); dropZone.classList.remove('dragging'); }));
    dropZone.addEventListener('drop', event => addFiles(event.dataTransfer.files));
})();
