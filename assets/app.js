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

    // CSP 的 style-src 未放开 'unsafe-inline'，HTML 里直接写 style="..." 会被浏览器丢弃。
    // 进度条宽度、评分百分比这类动态数值改用 data-* 承载，再由 JS 经 CSSOM 写入（CSSOM 不受 CSP 限制）。
    // 脚本以 defer 加载，执行时 DOM 已解析完毕，可直接查询。
    document.querySelectorAll('[data-style-width]').forEach(node => {
        node.style.width = node.dataset.styleWidth;
    });
    document.querySelectorAll('[data-style-pct]').forEach(node => {
        node.style.setProperty('--pct', node.dataset.stylePct);
    });

    const notify = (message, type = 'success') => {
        if (!toast) return;
        toast.textContent = message;
        toast.className = `toast visible ${type}`;
        window.setTimeout(() => toast.classList.remove('visible'), 2600);
    };

    // 需要保留可点击链接的提示（目前只有封禁提示的「提交申诉」）走这个版本：
    // 用 innerHTML 渲染并给 toast 加上 clickable 类放开 pointer-events，停留时间也拉长
    // （toast 默认 pointer-events:none 且 2.6 秒即消失，链接根本点不到）
    // 内容只来自服务端 banNoticeHtml()（原因字段已 e() 转义），不接受用户输入，故不存在注入风险
    const notifyHtml = (html, type = 'success', duration = 8000) => {
        if (!toast) return;
        toast.innerHTML = html;
        toast.className = `toast visible clickable ${type}`;
        window.setTimeout(() => {
            toast.classList.remove('visible');
            toast.classList.remove('clickable');
        }, duration);
    };

    // 封禁用户的上传、下载与文件操作入口一律拦下，并弹出后台配置的封禁模板文案。
    // #banNotice 就是 banNoticeHtml() 按 ban_notice_mode 渲染出来的提示条，直接复用它的内容可避免模板两处维护
    const banNotice = document.getElementById('banNotice');
    // 取 innerHTML 而非 textContent：提示条里的「提交申诉」是可点击链接，
    // 弹窗保留这段链接，用户才能像违规分区那样直接点进申诉（plain 模式没有链接，取到的就是纯文本）
    const banToastHtml = banNotice ? banNotice.innerHTML.trim() : '';
    if (banToastHtml !== '') {
        // 上传区、下载直链，以及文件与文件夹的重命名、移动、删除、新建文件夹
        // （服务端 api/file.php、api/folder.php 本就拒绝封禁用户，这里只是把失败提前到点击时并给出提示）
        // 工单附件不在此列：封禁提示本身引导用户走工单提交申诉，申诉需要上传/查看证据，故整体放行
        // （工单附件的下载直链用 data-ticket-download 标记，与普通文件的下载链接区分开）
        const blockedEntry = [
            '#dropZone', '#browseButton', '#fileInput',
            'a[href^="api/file.php"]:not([data-ticket-download])',
            '[data-action]', '#newFolderButton'
        ].join(', ');
        // 命中即可判定为被封禁的入口；用 closest 兼顾点在图标的按钮内部等情况
        const isBlockedEntry = target => target instanceof Element && Boolean(target.closest(blockedEntry));

        // 捕获阶段先于控件自身的逻辑执行，阻止默认行为后不会真的发起上传或跳转下载
        document.addEventListener('click', event => {
            if (!isBlockedEntry(event.target)) return;
            event.preventDefault();
            event.stopPropagation();
            notifyHtml(banToastHtml, 'error');
        }, true);

        // 拖放不经过 click，同样在捕获阶段拦下
        ['dragenter', 'dragover', 'drop'].forEach(type => document.addEventListener(type, event => {
            if (!isBlockedEntry(event.target)) return;
            event.preventDefault();
            event.stopPropagation();
            if (type === 'drop') notifyHtml(banToastHtml, 'error');
        }, true));
    }

    // 用栈记录已打开的弹窗：只有最上层响应 Esc / Tab，避免一次 Esc 关掉全部弹窗
    const overlayStack = [];
    // 弹窗骨架：统一负责遮罩、焦点锁与 Esc 关闭；prepare 可往弹窗里补控件，result 决定确认时的返回值
    const openDialog = ({ title, message, confirmLabel = '确认', extraHtml = '', prepare = null, result = () => true }) => new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'confirm-overlay';
        overlay.innerHTML = '<div class="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="confirm-title">'
            + '<h2 id="confirm-title"></h2>'
            + '<p class="confirm-message"></p>'
            + extraHtml
            + '<div class="confirm-actions">'
            + '<button type="button" class="secondary-button" data-confirm-cancel>取消</button>'
            + '<button type="button" class="confirm-danger" data-confirm-ok></button>'
            + '</div></div>';
        overlay.querySelector('#confirm-title').textContent = title;
        overlay.querySelector('.confirm-message').textContent = message;
        overlay.querySelector('[data-confirm-ok]').textContent = confirmLabel;
        if (prepare) prepare(overlay);

        const previousFocus = document.activeElement;
        let settled = false;
        const close = value => {
            if (settled) return;
            settled = true;
            document.removeEventListener('keydown', onKeydown);
            const position = overlayStack.indexOf(overlay);
            if (position !== -1) overlayStack.splice(position, 1);
            // 最后一个弹窗关闭后才解除背景滚动锁定
            if (overlayStack.length === 0) document.body.classList.remove('has-overlay');
            overlay.remove();
            // 关闭后把焦点还给触发按钮，键盘操作不丢失位置
            if (previousFocus instanceof HTMLElement) previousFocus.focus();
            resolve(value);
        };
        const onKeydown = event => {
            // 只处理最上层弹窗，防止被下层弹窗抢先响应
            if (overlayStack[overlayStack.length - 1] !== overlay) return;
            if (event.key === 'Escape') {
                close(null);
                return;
            }
            // Tab 焦点锁在弹窗内，键盘用户不会跑到遮罩后面的页面
            if (event.key !== 'Tab') return;
            const focusables = overlay.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (focusables.length === 0) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        overlay.querySelector('[data-confirm-ok]').addEventListener('click', () => close(result(overlay)));
        overlay.querySelector('[data-confirm-cancel]').addEventListener('click', () => close(null));
        overlay.addEventListener('click', event => {
            if (event.target === overlay) close(null);
        });
        document.addEventListener('keydown', onKeydown);

        overlayStack.push(overlay);
        document.body.classList.add('has-overlay');
        document.body.append(overlay);
        // 弹窗里带输入控件时优先聚焦它，否则聚焦确认按钮
        (overlay.querySelector('select, input') || overlay.querySelector('[data-confirm-ok]')).focus();
    });

    const askConfirm = message => openDialog({ title: '请确认操作', message }).then(value => value === true);
    window.askConfirm = askConfirm;

    // 站点公告弹窗：由服务端渲染到 #announceModal，不可 Esc / 点遮罩关闭，只能点「我知道了」退出。
    // 每条公告按 cookie nd_ann_<id>（值 {sig}~{expiresAt}）判断是否展示；公告更新后 sig 变化会重新弹出。
    const announceModal = document.getElementById('announceModal');
    if (announceModal) {
        const reminder = document.getElementById('announceReminder');
        const customDays = document.getElementById('announceCustomDays');
        const ackButton = document.getElementById('announceAck');
        const readCookie = name => {
            const prefix = name + '=';
            for (const part of (document.cookie || '').split('; ')) {
                if (part.startsWith(prefix)) return decodeURIComponent(part.slice(prefix.length));
            }
            return null;
        };
        const writeCookie = (name, value, maxAge) => {
            document.cookie = name + '=' + value + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
        };
        const nowSeconds = Math.floor(Date.now() / 1000);
        const pending = [];
        announceModal.querySelectorAll('.announce-item').forEach(item => {
            const raw = readCookie('nd_ann_' + item.dataset.announceId);
            let show = true;
            if (raw) {
                const split = raw.lastIndexOf('~');
                if (split !== -1) {
                    const sig = raw.slice(0, split);
                    const expiresAt = Number(raw.slice(split + 1));
                    // 公告未更新且未过期时跳过；expiresAt 为 0 表示永久有效
                    show = sig !== item.dataset.announceSig || (expiresAt !== 0 && expiresAt <= nowSeconds);
                }
            }
            if (show) {
                item.hidden = false;
                pending.push(item);
            }
        });
        if (pending.length) {
            announceModal.hidden = false;
            document.body.classList.add('has-overlay');
            if (customDays) customDays.hidden = true;
            if (reminder) reminder.addEventListener('change', () => {
                if (customDays) customDays.hidden = reminder.value !== 'custom';
            });
            const closeAnnounce = () => {
                const choice = reminder ? reminder.value : '7';
                // 「每次进入都提醒」不写 cookie（并清掉旧的），其余按选择写入有效期
                let days = null;
                if (choice === '0') days = 0;
                else if (choice === 'custom') days = Math.max(1, Math.min(3650, Number(customDays ? customDays.value : 7) || 7));
                else if (choice !== 'again') days = Number(choice);
                pending.forEach(item => {
                    const name = 'nd_ann_' + item.dataset.announceId;
                    if (days === null) { writeCookie(name, '', 0); return; }
                    const expiresAt = days === 0 ? 0 : nowSeconds + days * 86400;
                    const maxAge = days === 0 ? 315360000 : days * 86400;
                    writeCookie(name, encodeURIComponent(item.dataset.announceSig + '~' + expiresAt), maxAge);
                });
                announceModal.hidden = true;
                document.body.classList.remove('has-overlay');
            };
            if (ackButton) {
                (reminder || ackButton).focus();
                ackButton.addEventListener('click', closeAnnounce);
            }
        }
    }

    // 「移动到文件夹」选择器：复用弹窗骨架，选项来自页面里的 template#folderOptions
    const askFolderPicker = () => openDialog({
        title: '移动到文件夹',
        message: '选择目标文件夹；选择「根目录」即把文件移出当前文件夹。',
        confirmLabel: '移动',
        extraHtml: '<select class="folder-picker"></select>',
        prepare: overlay => {
            const template = document.getElementById('folderOptions');
            if (template) overlay.querySelector('.folder-picker').append(...template.content.cloneNode(true).children);
        },
        result: overlay => overlay.querySelector('.folder-picker').value
    });

    // 行内重命名/重命名式输入：把行内的名称元素临时换成输入框，回车提交、Esc 取消、失焦提交。
    // 不走浏览器 prompt，改动直接在当前行完成；onCommit 返回 Promise，失败时提示并已还原显示。
    // initial 传入时用它作为输入框初值（新建文件夹传空串），否则取元素当前文本。
    const startInlineRename = (nameEl, onCommit, initial) => {
        const baseline = initial === undefined ? nameEl.textContent.trim() : initial;
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'inline-rename';
        input.value = baseline;
        input.setAttribute('aria-label', '输入新的名称');

        nameEl.hidden = true;
        nameEl.insertAdjacentElement('afterend', input);
        input.focus();
        input.select();

        let settled = false;
        const finish = commit => {
            if (settled) return;
            settled = true;
            const value = input.value.trim();
            input.remove();
            nameEl.hidden = false;
            if (!commit || value === '' || value === baseline) return;
            Promise.resolve(onCommit(value)).catch(error => notify(error.message || '操作失败', 'error'));
        };

        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                finish(true);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                finish(false);
            }
        });
        input.addEventListener('blur', () => finish(true));
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
                const name = (row.dataset.fileName || '').toLowerCase();
                row.hidden = value !== '' && !name.includes(value);
            });
        });
    }

    // 预览：图片/PDF/音视频走同源 api/file.php?inline=1，完全复用登录与归属校验
    const previewModal = document.getElementById('previewModal');
    const previewBody = document.getElementById('previewBody');
    const previewDownload = document.getElementById('previewDownload');
    const previewReturnFocus = { current: null };

    // 预览失败（副本全失效 / 服务端不可用）统一提示「数据已丢失，请重新上传」
    const onPreviewError = () => {
        if (!previewBody) return;
        previewBody.innerHTML = '<p class="preview-error">很抱歉，该文件数据已丢失，请重新上传。</p>';
    };

    const closePreview = () => {
        if (!previewModal || previewModal.hidden) return;
        // 清空内容以停止音视频继续加载、释放内存
        if (previewBody) previewBody.innerHTML = '';
        previewModal.hidden = true;
        document.body.classList.remove('has-overlay');
        const back = previewReturnFocus.current;
        if (back && typeof back.focus === 'function') back.focus();
        previewReturnFocus.current = null;
    };

    const openPreview = async (row, button) => {
        if (!previewModal || !previewBody) return;
        const id = row.dataset.fileId;
        const kind = button.dataset.previewKind || '';
        const mime = (button.dataset.previewMime || '').toLowerCase();
        const size = parseInt(button.dataset.previewSize || '0', 10) || 0;
        const name = button.dataset.previewName || '文件';

        // 大文件（默认 20 MB）先提醒，避免一次性拉取占用带宽
        const warnBytes = parseInt(previewModal.dataset.warnBytes || '0', 10) || 0;
        if (warnBytes > 0 && size >= warnBytes) {
            if (document.querySelector('.confirm-overlay')) return;
            const sizeText = size >= 1024 * 1024 ? (size / 1024 / 1024).toFixed(1) + ' MB' : Math.round(size / 1024) + ' KB';
            if (!(await askConfirm(`「${name}」约 ${sizeText}，超过提醒阈值，预览会消耗较多流量。仍要继续预览吗？`))) return;
        }

        const url = `api/file.php?id=${encodeURIComponent(id)}&inline=1`;
        const heading = document.getElementById('previewHeading');
        if (heading) heading.textContent = name;
        if (previewDownload) {
            previewDownload.href = `api/file.php?id=${encodeURIComponent(id)}`;
            previewDownload.setAttribute('download', name);
        }
        previewBody.innerHTML = '<p class="preview-loading">正在加载预览…</p>';
        previewModal.hidden = false;
        document.body.classList.add('has-overlay');
        previewReturnFocus.current = button;

        // 先做一次只取首字节的预检：服务端对「数据已丢失」返回 410，对其它异常返回非 2xx，
        // 这样 iframe（PDF）无法捕获 error 的问题也能统一覆盖
        try {
            const probe = await fetch(url, { headers: { Range: 'bytes=0-0' }, credentials: 'same-origin' });
            if (probe.status === 410) {
                onPreviewError();
                return;
            }
            if (!probe.ok) {
                onPreviewError();
                return;
            }
        } catch (error) {
            onPreviewError();
            return;
        }

        let node;
        if (kind === 'image') {
            node = document.createElement('img');
            node.alt = name;
            node.className = 'preview-media';
            node.addEventListener('error', onPreviewError, { once: true });
        } else if (kind === 'av' && mime.startsWith('audio/')) {
            node = document.createElement('audio');
            node.controls = true;
            node.autoplay = true;
            node.className = 'preview-audio';
            node.addEventListener('error', onPreviewError, { once: true });
        } else if (kind === 'av') {
            node = document.createElement('video');
            node.controls = true;
            node.autoplay = true;
            node.className = 'preview-media';
            node.addEventListener('error', onPreviewError, { once: true });
        } else {
            node = document.createElement('iframe');
            node.className = 'preview-frame';
            node.title = name;
            node.addEventListener('error', onPreviewError, { once: true });
        }
        node.src = url;
        previewBody.innerHTML = '';
        previewBody.append(node);
    };

    if (previewModal) {
        document.getElementById('previewClose')?.addEventListener('click', closePreview);
        previewModal.addEventListener('click', event => { if (event.target === previewModal) closePreview(); });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') closePreview(); });
    }

    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-action]');
        if (!button) return;
        if (button.disabled) return;
        const row = button.closest('[data-file-id]');
        if (!row) return;
        const id = row.dataset.fileId;
        const action = button.dataset.action;
        const body = new URLSearchParams({ id, action });
        // 移动文件归文件夹接口处理：它不依赖文件详情，只改 uploads.folder_id
        let endpoint = 'api/file.php';

        if (action === 'preview') {
            openPreview(row, button);
            return;
        }

        if (action === 'rename') {
            // 行内重命名：名称就地变成输入框，回车/失焦提交后才发出请求
            const nameEl = row.querySelector('.display-name');
            if (!nameEl || row.querySelector('.inline-rename')) return;
            startInlineRename(nameEl, async value => {
                const response = await fetch('api/file.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                    body: new URLSearchParams({ id, action: 'rename', name: value })
                });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.message || '操作失败');
                nameEl.textContent = data.name;
                notify(data.message);
            });
            return;
        } else if (action === 'move') {
            const target = await askFolderPicker();
            if (target === null) return;
            endpoint = 'api/folder.php';
            body.set('action', 'move_file');
            body.set('folder_id', target);
        } else if (action === 'delete') {
            // 已有确认弹窗时直接忽略，避免快速双击叠出两个弹窗
            if (document.querySelector('.confirm-overlay')) return;
            if (!(await askConfirm('确认删除这个文件？删除后将从 Notion 页面归档。'))) return;
        }

        button.disabled = true;
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || '操作失败');
            if (action === 'move') {
                // 目标可能是当前文件夹的子层或其它层级，刷新以同步列表与各文件夹的文件数
                window.location.reload();
                return;
            }
            row.remove();
            notify(data.message);
        } catch (error) {
            notify(error.message || '操作失败', 'error');
        } finally {
            button.disabled = false;
        }
    });

    // 文件夹操作：重命名 / 删除。删除是递归删除，必须先向用户明示会连带删除里面多少文件
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-action]');
        if (!button || button.disabled) return;
        const row = button.closest('[data-folder-id]');
        if (!row) return;
        const action = button.dataset.action;
        if (action !== 'folder-rename' && action !== 'folder-delete') return;

        const current = row.querySelector('.display-name')?.textContent.trim() || '';
        // 重命名已在下方行内提交并 return，这里只剩删除请求
        const body = new URLSearchParams({ id: row.dataset.folderId, action: 'delete' });

        if (action === 'folder-rename') {
            // 行内重命名：名称就地变成输入框，提交后刷新以同步列表与各文件夹的文件数
            const nameEl = row.querySelector('.display-name');
            if (!nameEl || row.querySelector('.inline-rename')) return;
            startInlineRename(nameEl, async value => {
                const response = await fetch('api/folder.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                    body: new URLSearchParams({ id: row.dataset.folderId, action: 'rename', name: value })
                });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.message || '操作失败');
                notify(data.message);
                window.location.reload();
            });
            return;
        }

        // 已有确认弹窗时直接忽略，避免快速双击叠出两个弹窗
        if (document.querySelector('.confirm-overlay')) return;
        const inside = Number(row.dataset.folderInside || 0);
        const warning = `确认删除文件夹「${current}」？`
            + (inside > 0 ? `其中（含子文件夹）的 ${inside} 个文件会一并删除并从 Notion 归档，` : '')
            + '删除后无法恢复。';
        if (!(await askConfirm(warning))) return;

        button.disabled = true;
        try {
            const response = await fetch('api/folder.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || '操作失败');
            notify(data.message);
            window.location.reload();
        } catch (error) {
            notify(error.message || '操作失败', 'error');
            button.disabled = false;
        }
    });

    // 新建文件夹：建在当前层级下，data-parent 为 0 表示根目录
    const newFolderButton = document.getElementById('newFolderButton');
    if (newFolderButton) {
        newFolderButton.addEventListener('click', () => {
            // 行内新建：按钮就地换成输入框（初值为空），提交后刷新列表同步各文件夹文件数
            if (newFolderButton.hidden) return;
            startInlineRename(newFolderButton, async value => {
                const body = new URLSearchParams({
                    action: 'create',
                    parent_id: newFolderButton.dataset.parent || '0',
                    name: value
                });
                const response = await fetch('api/folder.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                    body
                });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.message || '创建失败');
                notify(data.message);
                window.location.reload();
            }, '');
        });
    }

    // 图形验证码「换一张」：带时间戳重新请求，避免浏览器缓存旧图片
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-captcha-refresh]');
        if (!button) return;
        const image = document.getElementById(button.dataset.captchaRefresh);
        if (image) image.src = `captcha.php?t=${Date.now()}`;
    });

    // 二次确认通过后的统一提交。临时关闭浏览器校验（表单常混有无关 required 字段会静默拦下提交，
    // 确认弹窗已承担确认职责），提交发起后立即恢复，避免该表单后续提交永久失去原生校验。
    const submitConfirmed = (form, trigger) => {
        form.noValidate = true;
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(trigger);
        } else {
            // 旧浏览器兜底：把按钮的 name/value 复制成隐藏域再提交
            if (trigger.name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = trigger.name;
                hidden.value = trigger.value;
                form.append(hidden);
            }
            form.submit();
        }
        form.noValidate = false;
    };

    document.addEventListener('click', async event => {
        const trigger = event.target.closest('[data-confirm]');
        if (!trigger) return;
        event.preventDefault();
        if (document.querySelector('.confirm-overlay')) return;
        if (!(await askConfirm(trigger.dataset.confirm))) return;

        const form = trigger.form;
        if (!form) return;
        // 标记本次提交已过确认，交给下面的 submit 委托放行，避免被重复确认
        form.dataset.confirmPassed = '1';
        submitConfirmed(form, trigger);
    });

    // 键盘隐式提交（光标停在表单里按 Enter）不经过 click 委托，会被浏览器直接提交而绕过二次确认。
    // 这里在 submit 阶段兜底，但必须用「本次真正触发的按钮」判断：显式点击取 event.submitter，
    // 隐式提交取浏览器会激活的默认按钮（表单内第一个提交按钮）。否则同一表单里既有「保存」又有
    // 「删除」时，点保存会被误套上删除按钮的确认，甚至以删除动作提交。
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (form.dataset.confirmPassed === '1') {
            delete form.dataset.confirmPassed;
            return;
        }
        const trigger = event.submitter
            || form.querySelector('button:not([type="button"]):not([type="reset"]), input[type="submit"]');
        if (!trigger || !trigger.matches('[data-confirm]')) return;
        event.preventDefault();
        if (document.querySelector('.confirm-overlay')) return;
        if (!(await askConfirm(trigger.dataset.confirm))) return;
        form.dataset.confirmPassed = '1';
        submitConfirmed(form, trigger);
    });

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

    // ── 工单附件：复用同一套分块协议，附件归属工单（scope=ticket），
    // 不出现在「我的文件」、不计入个人容量，工单关闭时由服务端从 Notion 删除 ──
    const ticketPanel = document.getElementById('ticketAttachments');
    const ticketDrop = document.getElementById('ticketDropZone');
    const ticketInput = document.getElementById('ticketFileInput');
    const ticketBrowse = document.getElementById('ticketBrowseButton');
    const ticketQueue = document.getElementById('ticketQueue');
    if (ticketPanel && ticketDrop && ticketInput && ticketBrowse && ticketQueue) {
        const ticketId = ticketPanel.dataset.ticket || '0';
        let ticketBusy = false;
        let ticketUploaded = 0;
        const ticketPending = [];

        const ticketValidate = file => {
            const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
            if (new TextEncoder().encode(file.name).length > 900) return '文件名超过 900 字节';
            if (!allowed.has(extension)) return 'Notion 不支持该文件类型';
            if (!file.size) return '不能上传空文件';
            return '';
        };

        const ticketAddRetry = entry => {
            const { item } = entry;
            if (item.querySelector('[data-retry]')) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'queue-retry';
            button.dataset.retry = '';
            button.textContent = '重试';
            button.addEventListener('click', () => {
                button.remove();
                item.classList.remove('failed');
                const status = item.querySelector('.queue-status');
                if (status) {
                    status.textContent = '等待重试';
                    status.title = '';
                }
                const bar = item.querySelector('.progress-track');
                if (bar) bar.value = 0;
                ticketPending.push(entry);
                ticketRunQueue();
            });
            item.append(button);
        };

        // 分块上传：start → 逐块 chunk → finish；附件不参与重名处理，服务端始终保留原名
        const ticketUpload = async entry => {
            const { file, item } = entry;
            const status = item.querySelector('.queue-status');
            const bar = item.querySelector('.progress-track');
            const fail = (detail, retry = true) => {
                status.textContent = detail;
                status.title = detail;
                item.classList.add('failed');
                if (retry) ticketAddRetry(entry);
                return false;
            };
            const setProgress = percent => {
                const value = Math.min(98, Math.max(0, percent));
                bar.value = Math.max(bar.value, value);
                status.textContent = `上传中 ${Math.round(value)}%`;
            };

            let started;
            try {
                started = await controlRequest({ action: 'start', scope: 'ticket', ticket_id: ticketId, filename: file.name, size: file.size });
            } catch (_) {
                return fail('网络连接失败，未能与服务器通信');
            }
            if (!started.ok || !started.data.ok) return fail(failureText(started.status, started.data));

            const token = started.data.upload_token;
            const chunkSize = Number(started.data.chunk_size) || file.size || 1;
            let total = Number(started.data.total) || 1;
            setProgress(0);

            let index = 0;
            while (index < total) {
                const begin = index * chunkSize;
                const blob = file.slice(begin, Math.min(begin + chunkSize, file.size));
                let result;
                for (let attempt = 1; ; attempt += 1) {
                    result = await sendChunk(token, index, blob, loaded => setProgress((begin + loaded) / file.size * 98));
                    const retryable = result.status === 0 || result.status >= 500;
                    if (!retryable || attempt >= 3) break;
                    status.textContent = `分块上传失败，正在重试（${attempt}/2）`;
                    await new Promise(resolve => window.setTimeout(resolve, 500 * attempt));
                }
                if (result.status === 409 && result.data.code === 'chunk_out_of_order') {
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
            ticketUploaded += 1;
            return true;
        };

        async function ticketRunQueue() {
            if (ticketBusy) return;
            ticketBusy = true;
            while (ticketPending.length) await ticketUpload(ticketPending.shift());
            ticketBusy = false;

            // 仍有可重试的失败条目：保留错误文案与「重试」按钮，不刷新页面
            const failedNow = ticketQueue.querySelectorAll('[data-retry]').length;
            if (failedNow > 0) {
                notify(`附件上传结束：失败 ${failedNow} 个，可点击失败条目的「重试」重新上传`, 'error');
                return;
            }
            if (ticketUploaded > 0) {
                notify(`已上传 ${ticketUploaded} 个工单附件，正在刷新页面…`);
                window.location.reload();
            }
        }

        const ticketAddFiles = files => {
            [...files].forEach(file => {
                const error = ticketValidate(file);
                const item = document.createElement('article');
                item.className = `queue-item${error ? ' failed' : ''}`;
                item.innerHTML = '<span class="file-kind"></span><div class="queue-meta"><b></b><small></small><progress class="progress-track" max="100" value="0"></progress></div><span class="queue-status"></span>';
                item.querySelector('.file-kind').textContent = (file.name.split('.').pop() || 'FILE').toUpperCase();
                item.querySelector('.queue-meta b').textContent = file.name;
                item.querySelector('.queue-meta small').textContent = formatBytes(file.size);
                item.querySelector('.queue-status').textContent = error || '等待上传';
                ticketQueue.appendChild(item);
                if (!error) ticketPending.push({ file, item });
            });
            ticketRunQueue();
        };

        ticketBrowse.addEventListener('click', event => { event.stopPropagation(); ticketInput.click(); });
        ticketDrop.addEventListener('click', event => { if (!event.target.closest('button')) ticketInput.click(); });
        ticketDrop.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); ticketInput.click(); } });
        ticketInput.addEventListener('change', () => { ticketAddFiles(ticketInput.files); ticketInput.value = ''; });
        ['dragenter', 'dragover'].forEach(type => ticketDrop.addEventListener(type, event => { event.preventDefault(); ticketDrop.classList.add('dragging'); }));
        ['dragleave', 'drop'].forEach(type => ticketDrop.addEventListener(type, event => { event.preventDefault(); ticketDrop.classList.remove('dragging'); }));
        ticketDrop.addEventListener('drop', event => ticketAddFiles(event.dataTransfer.files));
    }

    // 工单批量操作：全选与已选计数（批量表单在表格外，勾选框用 form 属性关联过来）
    const ticketBulkForm = document.getElementById('ticketBulkForm');
    if (ticketBulkForm) {
        const bulkItems = [...document.querySelectorAll('[data-check-item]')];
        const bulkMasters = [...document.querySelectorAll('[data-check-all]')];
        const bulkCounter = ticketBulkForm.querySelector('[data-check-count]');
        const syncBulk = () => {
            const checked = bulkItems.filter(item => item.checked).length;
            if (bulkCounter) bulkCounter.textContent = String(checked);
            bulkMasters.forEach(master => {
                master.checked = checked > 0 && checked === bulkItems.length;
                master.indeterminate = checked > 0 && checked < bulkItems.length;
            });
        };
        bulkMasters.forEach(master => master.addEventListener('change', () => {
            bulkItems.forEach(item => { item.checked = master.checked; });
            syncBulk();
        }));
        bulkItems.forEach(item => item.addEventListener('change', syncBulk));
        syncBulk();
    }

    // 内容审核提示的「全选」：纯 UI 辅助，批量删除由表单提交 POST（action=dismiss_all_notices）完成，
    // 取消勾选不会阻止提交（一键删除以服务端为准，作用于面板上全部提示）。
    const noticeCheckAll = document.querySelector('[data-check-all="notice-check"]');
    if (noticeCheckAll) {
        const noticeItems = [...document.querySelectorAll('input[name="notice-check"]')];
        noticeCheckAll.addEventListener('change', () => {
            noticeItems.forEach(item => { item.checked = noticeCheckAll.checked; });
        });
        const syncNoticeCheckAll = () => {
            const checked = noticeItems.filter(item => item.checked).length;
            noticeCheckAll.checked = checked > 0 && checked === noticeItems.length;
            noticeCheckAll.indeterminate = checked > 0 && checked < noticeItems.length;
        };
        noticeItems.forEach(item => item.addEventListener('change', syncNoticeCheckAll));
        syncNoticeCheckAll();
    }

    const dropZone = document.getElementById('dropZone');
    const input = document.getElementById('fileInput');
    const browse = document.getElementById('browseButton');
    const queue = document.getElementById('queue');
    if (!dropZone || !input || !browse || !queue) return;

    const limit = Number(document.body.dataset.limit || 0);
    const remainingRaw = document.body.dataset.remaining ?? '';
    const remaining = remainingRaw === '' ? null : Number(remainingRaw);
    let busy = false;
    let uploaded = 0;
    let overwritten = 0;
    let renamed = 0;
    // 内容风控：被暂停使用的文件数（不可重试，文件已被隔离等待管理员复核）
    let held = 0;
    // 内容风控：AI 审核未能完成、转为人工复核的文件数（文件可正常使用）
    let reviewing = 0;
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

    // 与服务端 nextAvailableFilename 一致的编号预览：编号插在扩展名之前，取第一个空闲编号
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

    const addRetry = entry => {
        const { item } = entry;
        if (item.querySelector('[data-retry]')) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'queue-retry';
        button.dataset.retry = '';
        button.textContent = '重试';
        button.addEventListener('click', () => {
            button.remove();
            item.classList.remove('failed');
            const status = item.querySelector('.queue-status');
            if (status) {
                status.textContent = '等待重试';
                status.title = '';
            }
            const bar = item.querySelector('.progress-track');
            if (bar) bar.value = 0;
            pending.push(entry);
            runQueue();
        });
        item.append(button);
    };

    // 分块上传：start → 逐块 chunk → finish；服务端按序接收，409 时用 received 自同步续传
    const CHUNK_ATTEMPTS = 3;
    const upload = async ({ file, item, duplicateAction }) => {
        const status = item.querySelector('.queue-status');
        const bar = item.querySelector('.progress-track');
        const fail = (detail, retry = true) => {
            status.textContent = detail;
            status.title = detail;
            item.classList.add('failed');
            if (retry) addRetry({ file, item, duplicateAction });
            return false;
        };
        const setProgress = percent => {
            const value = Math.min(98, Math.max(0, percent));
            bar.value = Math.max(bar.value, value);
            status.textContent = `上传中 ${Math.round(value)}%`;
        };

        let started;
        try {
            // 上传落到当前浏览的文件夹（data-folder 为 0 表示根目录）
            started = await controlRequest({ action: 'start', filename: file.name, size: file.size, duplicate_action: duplicateAction || '', folder_id: document.body.dataset.folder || '0' });
        } catch (_) {
            return fail('网络连接失败，未能与服务器通信');
        }
        if (!started.ok || !started.data.ok) {
            // 关键词拦截：属于确定性拒绝，重试也不会通过，改为提示申诉号
            if (started.data.code === 'moderation_rejected') {
                held += 1;
                return fail(failureText(started.status, started.data), false);
            }
            return fail(failureText(started.status, started.data));
        }

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
            let result;
            for (let attempt = 1; ; attempt += 1) {
                result = await sendChunk(token, index, blob, loaded => setProgress((begin + loaded) / file.size * 98));
                // 仅网络中断（status 0）与服务端 5xx 值得重试；其余（如 4xx）重试也不会成功
                const retryable = result.status === 0 || result.status >= 500;
                if (!retryable || attempt >= CHUNK_ATTEMPTS) break;
                status.textContent = `分块上传失败，正在重试（${attempt}/${CHUNK_ATTEMPTS - 1}）`;
                await new Promise(resolve => window.setTimeout(resolve, 500 * attempt));
            }
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

        // 内容风控结果：flagged 表示文件已被暂停使用（隔离），pending 表示转人工复核但仍可用
        const moderation = finished.data.moderation || null;
        if (moderation && moderation.decision === 'flagged') {
            held += 1;
            return fail(moderation.message || '该文件未通过内容审核，已暂停使用。', false);
        }
        if (moderation && moderation.decision === 'pending') {
            reviewing += 1;
        }

        bar.value = 100;
        status.textContent = moderation && moderation.decision === 'pending' ? '上传完成，等待人工复核' : '上传完成';
        item.classList.add('complete');
        uploaded += 1;
        return true;
    };

    async function runQueue() {
        if (busy) return;
        busy = true;
        const uploadedBefore = uploaded;
        while (pending.length) await upload(pending.shift());
        busy = false;

        // 仍有可重试的失败条目：保留错误文案与「重试」按钮，不刷新页面（刷新会把失败原因冲掉）
        const failedNow = queue.querySelectorAll('[data-retry]').length;
        if (failedNow > 0) {
            const parts = [];
            const okCount = uploaded - uploadedBefore;
            if (okCount > 0) parts.push(`成功 ${okCount} 个`);
            parts.push(`失败 ${failedNow} 个`);
            notify(`上传结束：${parts.join('，')}，可点击失败条目的「重试」重新上传`, 'error');
            return;
        }

        if (uploaded > 0) {
            const extras = [];
            if (overwritten) extras.push(`覆盖 ${overwritten} 个同名文件`);
            if (renamed) extras.push(`自动编号 ${renamed} 个同名文件`);
            if (held) extras.push(`${held} 个文件未通过内容审核，已暂停使用（首页可查看申诉号）`);
            if (reviewing) extras.push(`${reviewing} 个文件等待人工复核`);
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
