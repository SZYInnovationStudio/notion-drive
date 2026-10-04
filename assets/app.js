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
    // 不支持的后缀统一用这一句：先明确「不支持」，再给出压缩包这条可行替代路径
    const UNSUPPORTED_HINT = 'Notion 不支持该文件类型，可压缩为 ZIP / RAR 等压缩包后再上传';

    /** 取小写扩展名（不含点）；没有扩展名时返回空串。 */
    const extensionOf = file => (file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '');

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

    // 接口通常返回 JSON。但部分服务器（如开启 fastcgi_intercept_errors 的 Nginx）会把 PHP 的
    // 4xx/5xx 响应体替换成自己的 HTML 错误页，直接 response.json() 就会抛出「Unexpected token '<'」。
    // 统一在这里解析：拿不到 JSON 时给出带状态码的提示，避免用户看到无从理解的报错。
    const readJson = async response => {
        try {
            return JSON.parse(await response.text());
        } catch (_) {
            throw new Error(`服务器响应异常（HTTP ${response.status}），请稍后重试；若持续出现请联系管理员。`);
        }
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
            '#dropZone', '#browseButton', '#fileInput', '#browseFolderButton', '#folderInput',
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
                const data = await readJson(response);
                if (!response.ok || !data.ok) throw new Error(data.message || '操作失败');
                nameEl.textContent = data.name;
                notify(data.message);
            });
            return;
        } else if (action === 'move') {
            const target = await askFolderPicker();
            if (target === null) return;
            endpoint = 'api/folder.php';
            // 移动接口用 file_id 指文件、folder_id 指目标文件夹；通用 body 里的 id 是文件夹接口的字段，这里不能沿用
            body.delete('id');
            body.set('action', 'move_file');
            body.set('file_id', id);
            body.set('folder_id', target);
        } else if (action === 'delete') {
            // 已有确认弹窗时直接忽略，避免快速双击叠出两个弹窗
            if (document.querySelector('.confirm-overlay')) return;
            if (!(await askConfirm('确认删除这个文件？删除后将从 Notion 页面归档。'))) return;
        } else if (action === 'retry-write') {
            // 写入失败后重新提交：服务端置回「正在写入存储」并在后台重试，因此刷新页面看新状态而不是移除该行
            body.set('action', 'retry_write');
        }

        button.disabled = true;
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body
            });
            const data = await readJson(response);
            if (!response.ok || !data.ok) throw new Error(data.message || '操作失败');
            if (action === 'move' || action === 'retry-write') {
                // 移动会改变各文件夹的文件数、重试写入会改变状态徽章，都刷新以同步列表
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
                const data = await readJson(response);
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
            const data = await readJson(response);
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
                const data = await readJson(response);
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
        // 防重复提交：服务端处理偏慢时用户连点会重复提交（例如连开多张工单），锁住后只放行第一次。
        // 只拦截后续提交、不禁用按钮，避免带 name/value 的提交按钮因 disabled 而丢失取值。
        if (form.dataset.submitLocked === '1') {
            event.preventDefault();
            return;
        }
        if (form.dataset.confirmPassed === '1') {
            // 二次确认已通过，本次是真正提交：上锁后放行
            delete form.dataset.confirmPassed;
            form.dataset.submitLocked = '1';
            return;
        }
        const trigger = event.submitter
            || form.querySelector('button:not([type="button"]):not([type="reset"]), input[type="submit"]');
        if (!trigger || !trigger.matches('[data-confirm]')) {
            // 无需二次确认的表单：本次提交直接放行，上锁防止连点重复提交
            form.dataset.submitLocked = '1';
            return;
        }
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

    /**
     * 批量查询「正在写入存储」的进度：返回 { 上传id: { status, progress } }。
     * 请求失败返回 null（由调用方决定是否继续轮询），不使用异常中断轮询。
     */
    const fetchWriteStatus = async ids => {
        if (!ids.length) return {};
        try {
            const result = await controlRequest({ action: 'write_status', ids: ids.join(',') });
            if (!result.ok || !result.data || !result.data.ok) return null;
            return result.data.items || {};
        } catch (_) {
            return null;
        }
    };

    // 文件列表里「正在写入存储」的行：轮询写入进度填进行内进度条；状态一变就刷新页面，
    // 由服务端重新渲染徽章与「已安全存储 / 写入失败」文案（避免前端拼文案与服务端口径不一致）
    const writeRows = [...document.querySelectorAll('#fileTable tr[data-write-track]')];
    if (writeRows.length) {
        const writeRowIds = writeRows.map(row => row.dataset.fileId);
        const pollWriteRows = async () => {
            const items = await fetchWriteStatus(writeRowIds);
            if (items) {
                for (const row of writeRows) {
                    const info = items[row.dataset.fileId];
                    if (!info) continue;
                    if (info.status !== 'writing') {
                        window.location.reload();
                        return;
                    }
                    const bar = row.querySelector('.write-track');
                    if (bar) bar.value = Math.max(0, Math.min(100, Number(info.progress) || 0));
                }
            }
            window.setTimeout(pollWriteRows, 2500);
        };
        window.setTimeout(pollWriteRows, 1200);
    }

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
            if (new TextEncoder().encode(file.name).length > 900) return '文件名超过 900 字节';
            if (!allowed.has(extensionOf(file))) return UNSUPPORTED_HINT;
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
    const folderInput = document.getElementById('folderInput');
    const browseFolder = document.getElementById('browseFolderButton');
    const queue = document.getElementById('queue');
    if (!dropZone || !input || !browse || !queue) return;

    // Safari 等浏览器不认 webkitdirectory，会把「选择文件夹」静默退化成一个只能选文件的窗口；
    // 这类浏览器改用 showDirectoryPicker() 兜底，才能继续还原目录结构
    const dirPickerSupported = 'webkitdirectory' in document.createElement('input');
    const dirPickerFallback = typeof window.showDirectoryPicker === 'function';

    /** 从 webkitRelativePath 取相对目录（不含文件名）；直接选散落文件时返回空串。 */
    const relativeDirOf = file => {
        const path = String(file.webkitRelativePath || '');
        const index = path.lastIndexOf('/');
        return index > 0 ? path.slice(0, index) : '';
    };

    /** readEntries 每次只返回一批，必须反复读到空数组为止。 */
    const readAllEntries = entry => new Promise(resolve => {
        const reader = entry.createReader();
        const all = [];
        const step = () => reader.readEntries(batch => {
            if (!batch.length) {
                resolve(all);
                return;
            }
            all.push(...batch);
            step();
        }, () => resolve(all));
        step();
    });

    /** 递归读取拖入的 FileSystemEntry：返回 [{ file, relDir }]；relDir 为相对拖入起点的目录，散落文件为空串。 */
    const collectDropped = async (entries, prefix = '') => {
        const collected = [];
        for (const entry of entries) {
            if (entry.isFile) {
                const file = await new Promise(resolve => entry.file(resolve, () => resolve(null)));
                if (file) collected.push({ file, relDir: prefix });
                continue;
            }
            if (entry.isDirectory) {
                // 与目录选择保持同一口径：顶层目录名也算进相对路径
                const dir = prefix === '' ? entry.name : `${prefix}/${entry.name}`;
                collected.push(...await collectDropped(await readAllEntries(entry), dir));
            }
        }
        return collected;
    };

    /** showDirectoryPicker() 兜底读取：递归列出目录内全部文件，相对路径口径与 webkitdirectory 保持一致（含顶层目录名）。 */
    const pickDirectoryFiles = async () => {
        const handle = await window.showDirectoryPicker();
        const collected = [];
        const walk = async (directory, prefix) => {
            for await (const entry of directory.values()) {
                if (entry.kind === 'file') {
                    collected.push({ file: await entry.getFile(), relDir: prefix });
                } else if (entry.kind === 'directory') {
                    await walk(entry, prefix === '' ? entry.name : `${prefix}/${entry.name}`);
                }
            }
        };
        await walk(handle, handle.name);
        return collected;
    };

    const limit = Number(document.body.dataset.limit || 0);
    const remainingRaw = document.body.dataset.remaining ?? '';
    // 剩余空间随上传递减（上传文件夹会一次排很多文件），使后续文件能在入队/上传前就拦下「空间不足」
    let remaining = remainingRaw === '' ? null : Number(remainingRaw);
    let busy = false;
    let uploaded = 0;
    let overwritten = 0;
    let renamed = 0;
    // 内容风控：被暂停使用的文件数（不可重试，文件已被隔离等待管理员复核）
    let held = 0;
    // 文件已提交、等待后台写入 Notion 的条目（每条一个进度条）与最终写入失败数
    let writeFailed = 0;
    // 通过「在线上传」直传、提交时即已写入 Notion 的文件数（不计入待写入轮询）
    let onlineDone = 0;
    const pendingWrite = [];
    const pending = [];
    const validate = file => {
        if (new TextEncoder().encode(file.name).length > 900) return '文件名超过 900 字节';
        if (!allowed.has(extensionOf(file))) return UNSUPPORTED_HINT;
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

    /** 上传前向服务器确认这些「文件 + 目标文件夹」是否已存在；失败时按「无重名」处理，交由服务端兜底编号。
 *  重名按文件夹隔离，故必须逐项带上 folder_id（文件夹上传时同一批文件可能落在不同目录）。 */
    const findDuplicates = async items => {
        if (!items.length) return new Set();
        try {
            const response = await fetch('api/upload.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body: new URLSearchParams({ mode: 'check', items: JSON.stringify(items) })
            });
            const data = await response.json();
            if (!response.ok || !data.ok) return new Set();
            // 服务端返回 [{ name, folder_id }]，用「文件夹 + 文件名」作键，避免不同目录的同名文件互相误判
            return new Set((data.duplicates || []).map(entry => `${Number(entry.folder_id) || 0}|${entry.name}`));
        } catch (_) {
            return new Set();
        }
    };

    /** 文件夹已存在时的选择弹窗：返回 'merge'（合并进已有）或 'new'（新建一个）。
 *  复用 `.duplicate-dialog` 骨架；Esc/取消按「合并」处理（不新建多余文件夹，是更稳妥的默认）。 */
    const askFolderConflict = name => new Promise(resolve => {
        const dialog = document.createElement('dialog');
        dialog.className = 'duplicate-dialog';
        dialog.innerHTML = '<h2>文件夹已存在</h2><p>当前目录下已经有同名文件夹，请选择处理方式。</p><div class="duplicate-name">文件夹<strong></strong></div><p class="duplicate-hint"></p><div class="duplicate-actions"><button type="button" class="secondary-button" data-choice="new">新建一个</button><button type="button" class="primary-button compact" data-choice="merge">合并进去</button></div>';
        dialog.querySelector('.duplicate-name strong').textContent = name;
        dialog.querySelector('.duplicate-hint').textContent = '「合并进去」会把这次的文件放进已有文件夹；「新建一个」会另建一个同名文件夹，两者并存。';
        const finish = choice => {
            dialog.close();
            resolve(choice === 'new' ? 'new' : 'merge');
        };
        dialog.querySelector('[data-choice="new"]').addEventListener('click', () => finish('new'));
        dialog.querySelector('[data-choice="merge"]').addEventListener('click', () => finish('merge'));
        // 按 Esc 等同于「合并进去」：避免误建重名文件夹
        dialog.addEventListener('cancel', event => {
            event.preventDefault();
            finish('merge');
        });
        dialog.addEventListener('close', () => dialog.remove());
        document.body.appendChild(dialog);
        dialog.showModal();
        dialog.querySelector('[data-choice="merge"]').focus();
    });

    /** 界面内确认弹窗：返回 true 表示覆盖旧文件，false 表示自动编号。 */
    const confirmOverwrite = (name, target) => new Promise(resolve => {
        const dialog = document.createElement('dialog');
        dialog.className = 'duplicate-dialog';
        dialog.innerHTML = '<h2>文件已存在</h2><p>当前空间里已经有同名文件，请选择处理方式。</p><div class="duplicate-name">文件名<strong></strong></div><p class="duplicate-hint"></p><div class="duplicate-actions"><button type="button" class="secondary-button" data-choice="rename">自动编号</button><button type="button" class="primary-button compact" data-choice="overwrite">覆盖并上传</button></div>';
        dialog.querySelector('.duplicate-name strong').textContent = name;
        dialog.querySelector('.duplicate-hint').textContent = target
            ? `「覆盖并上传」会删除旧文件并替换为新文件；「自动编号」会保留两者，新文件命名为 ${target}。`
            : '「覆盖并上传」会删除旧文件并替换为新文件；「自动编号」会保留两者，新文件由服务器自动编号。';

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

    /** 建立队列条目（校验 + DOM）。candidates 为 [{ file, folderId, relPath }]；
     *  文件夹上传时 relPath 非空，条目主标题显示相对路径，便于区分不同子目录里的同名文件。 */
    const buildEntries = candidates => {
        const entries = [];
        candidates.forEach(({ file, folderId = 0, relPath = '', error: presetError = '' }) => {
            const error = presetError || validate(file);
            const item = document.createElement('article');
            item.className = `queue-item${error ? ' failed' : ''}`;
            item.innerHTML = '<span class="file-kind"></span><div class="queue-meta"><b></b><small></small><progress class="progress-track" max="100" value="0"></progress></div><span class="queue-status"></span>';
            item.querySelector('.file-kind').textContent = (file.name.split('.').pop() || 'FILE').toUpperCase();
            item.querySelector('.queue-meta b').textContent = file.name;
            // 文件夹上传时副标题显示相对目录（比体积更能区分不同子目录的同名文件），悬停可看完整路径
            const sub = item.querySelector('.queue-meta small');
            if (relPath) {
                const dir = relPath.includes('/') ? relPath.slice(0, relPath.lastIndexOf('/')) : relPath;
                sub.textContent = dir;
                sub.title = relPath;
                sub.classList.add('queue-path');
            } else {
                sub.textContent = formatBytes(file.size);
            }
            item.querySelector('.queue-status').textContent = error || '等待上传';
            queue.appendChild(item);
            if (!error) entries.push({ file, item, duplicateAction: '', folderId, relPath });
        });
        return entries;
    };

    /** 重名预检（按文件夹隔离）并按需弹窗确认。 */
    const resolveDuplicates = async entries => {
        const duplicates = await findDuplicates(entries.map(entry => ({
            name: entry.file.name,
            folder_id: entry.folderId || 0
        })));
        for (const entry of entries) {
            if (!duplicates.has(`${entry.folderId || 0}|${entry.file.name}`)) continue;
            // 文件夹上传的目标目录不在本页表格里，无法本地预览编号，交由服务端命名并在响应里回填
            const target = entry.relPath ? '' : previewRename(entry.file.name);
            const overwrite = await confirmOverwrite(entry.file.name, target);
            entry.duplicateAction = overwrite ? 'overwrite' : 'rename';
            entry.item.querySelector('.queue-status').textContent = overwrite
                ? '等待上传（覆盖同名旧文件）'
                : (target ? `等待上传（将编号为 ${target}）` : '等待上传（同名将自动编号）');
        }
    };

    const addFiles = async files => {
        const rootFolder = Number(document.body.dataset.folder || 0);
        const entries = buildEntries([...files].map(file => ({ file, folderId: rootFolder })));
        await resolveDuplicates(entries);
        pending.push(...entries);
        runQueue();
    };

    /** 目录路径集合：把每个文件的 webkitRelativePath 拆成各级目录（含祖先），父先于子排序。 */
    const collectDirPaths = candidates => {
        const paths = new Set();
        candidates.forEach(({ relDir }) => {
            if (!relDir) return;
            const segments = relDir.split('/').filter(Boolean);
            let current = '';
            segments.forEach(segment => {
                current = current === '' ? segment : `${current}/${segment}`;
                paths.add(current);
            });
        });
        return [...paths].sort((a, b) => a.length - b.length || (a < b ? -1 : 1));
    };

    /** 调 api/folder.php 的 create_tree：inspect 时只探测冲突；否则带 merges 真正建树并返回 路径→id。 */
    const createFolderTree = async (parentId, paths, { inspect = false, merges = [] } = {}) => {
        const body = new URLSearchParams({
            action: 'create_tree',
            parent_id: String(parentId || 0),
            paths: JSON.stringify(paths)
        });
        if (inspect) body.set('inspect', '1');
        else body.set('merges', JSON.stringify(merges));
        const response = await fetch('api/folder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
            body
        });
        const data = await readJson(response);
        if (!response.ok || !data.ok) throw new Error(data.message || '创建文件夹失败');
        return inspect ? (data.conflicts || []) : (data.folders || {});
    };

    /** 文件夹上传前的确认窗：列出待上传文件并支持勾选剔除，返回选中的 [{ file, relDir }]；取消返回 null。 */
    const confirmFolderUpload = candidates => new Promise(resolve => {
        const dirCount = collectDirPaths(candidates).length;
        const totalBytes = candidates.reduce((sum, item) => sum + item.file.size, 0);
        const dialog = document.createElement('dialog');
        dialog.className = 'duplicate-dialog folder-confirm';
        dialog.innerHTML = '<h2>确认上传文件夹</h2><p class="folder-confirm-summary"></p><div class="folder-confirm-toolbar"><label><input type="checkbox" data-check-all checked> 全选</label><span class="folder-confirm-selected"></span></div><div class="folder-confirm-list"></div><div class="duplicate-actions"><button type="button" class="secondary-button" data-choice="cancel">取消</button><button type="button" class="primary-button compact" data-choice="confirm">确认上传</button></div>';
        // 后缀不支持的先在这里点明，避免用户勾着传完才在队列里一条条报错
        const unsupportedCount = candidates.filter(item => !allowed.has(extensionOf(item.file))).length;
        dialog.querySelector('.folder-confirm-summary').textContent =
            `共 ${candidates.length} 个文件、${dirCount} 个文件夹，合计 ${formatBytes(totalBytes)}。取消勾选可跳过不想上传的文件。`
            + (unsupportedCount ? `其中 ${unsupportedCount} 个文件类型不支持，${UNSUPPORTED_HINT}。` : '');

        // 选中的下标集合，全选开关对全部文件生效（含未逐条列出的部分）
        const selected = new Set(candidates.map((_, index) => index));
        const list = dialog.querySelector('.folder-confirm-list');
        const checkAll = dialog.querySelector('[data-check-all]');
        const counter = dialog.querySelector('.folder-confirm-selected');
        const syncCounter = () => {
            counter.textContent = `已选 ${selected.size} / ${candidates.length} 个`;
            checkAll.checked = selected.size === candidates.length;
            checkAll.indeterminate = selected.size > 0 && selected.size < candidates.length;
        };

        // 文件极多时只逐条渲染前 300 个，避免插入上万个节点卡死页面；全选开关仍覆盖全部文件
        const MAX_ROWS = 300;
        candidates.slice(0, MAX_ROWS).forEach((item, index) => {
            const row = document.createElement('label');
            row.className = 'folder-confirm-row';
            row.innerHTML = '<input type="checkbox" checked><span class="folder-confirm-path"></span><span class="folder-confirm-tag"></span><span class="folder-confirm-size"></span>';
            const path = item.relDir ? `${item.relDir}/${item.file.name}` : item.file.name;
            const pathNode = row.querySelector('.folder-confirm-path');
            pathNode.textContent = path;
            pathNode.title = path;
            // 后缀不支持的逐条标「不支持」，悬停给出压缩包方案，让用户自行决定是否取消勾选
            if (!allowed.has(extensionOf(item.file))) {
                const tag = row.querySelector('.folder-confirm-tag');
                tag.textContent = '不支持';
                tag.title = UNSUPPORTED_HINT;
            }
            row.querySelector('.folder-confirm-size').textContent = formatBytes(item.file.size);
            row.querySelector('input').addEventListener('change', event => {
                if (event.target.checked) selected.add(index);
                else selected.delete(index);
                syncCounter();
            });
            list.appendChild(row);
        });
        if (candidates.length > MAX_ROWS) {
            const more = document.createElement('p');
            more.className = 'folder-confirm-more';
            more.textContent = `其余 ${candidates.length - MAX_ROWS} 个文件未逐条列出，随上方「全选」开关一并处理。`;
            list.appendChild(more);
        }
        checkAll.addEventListener('change', () => {
            selected.clear();
            if (checkAll.checked) candidates.forEach((_, index) => selected.add(index));
            list.querySelectorAll('.folder-confirm-row input').forEach(box => { box.checked = checkAll.checked; });
            syncCounter();
        });
        syncCounter();

        const finish = value => {
            dialog.close();
            resolve(value);
        };
        dialog.querySelector('[data-choice="confirm"]').addEventListener('click', () => {
            if (!selected.size) {
                notify('请至少勾选一个文件再上传。', 'error');
                return;
            }
            finish(candidates.filter((_, index) => selected.has(index)));
        });
        dialog.querySelector('[data-choice="cancel"]').addEventListener('click', () => finish(null));
        dialog.addEventListener('cancel', event => {
            event.preventDefault();
            finish(null);
        });
        dialog.addEventListener('close', () => dialog.remove());
        document.body.appendChild(dialog);
        dialog.showModal();
        dialog.querySelector('[data-choice="confirm"]').focus();
    });

    /** 上传文件夹：先按相对路径建好整棵目录树（同名文件夹让用户选合并/新建），再把文件排进各自目录。 */
    const addFolderFiles = async candidates => {
        const usable = candidates.filter(({ file }) => file && file.name);
        if (!usable.length) return;

        // 上传前先让用户确认并勾选要传的文件，确认前不建目录、不查重
        const chosen = await confirmFolderUpload(usable);
        if (!chosen || !chosen.length) return;

        const rootFolder = Number(document.body.dataset.folder || 0);
        const paths = collectDirPaths(chosen);

        const merges = [];
        if (paths.length) {
            let conflicts;
            try {
                conflicts = await createFolderTree(rootFolder, paths, { inspect: true });
            } catch (error) {
                notify(error.message || '探测文件夹失败', 'error');
                return;
            }
            for (const path of conflicts) {
                const choice = await askFolderConflict(path);
                if (choice === 'merge') merges.push(path);
            }
        }

        let folders = {};
        if (paths.length) {
            try {
                folders = await createFolderTree(rootFolder, paths, { merges });
            } catch (error) {
                notify(error.message || '创建文件夹失败', 'error');
                return;
            }
        }

        const entries = buildEntries(chosen.map(({ file, relDir }) => {
            // 目录 id 解析不到时明确报错，而不是静默把文件丢到当前目录（会造成文件「放错地方」却看似成功）
            const resolved = relDir ? Number(folders[relDir] || 0) : 0;
            return {
                file,
                folderId: resolved || rootFolder,
                relPath: file.webkitRelativePath || (relDir ? `${relDir}/${file.name}` : file.name),
                error: relDir && !resolved ? '目录创建结果缺失，未上传（请重试）' : ''
            };
        }));
        await resolveDuplicates(entries);
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
    const upload = async ({ file, item, duplicateAction, folderId, relPath }) => {
        const status = item.querySelector('.queue-status');
        const bar = item.querySelector('.progress-track');
        const fail = (detail, retry = true) => {
            status.textContent = detail;
            status.title = detail;
            item.classList.add('failed');
            // 重试必须保留目标文件夹与相对路径，否则重试会落到当前目录
            if (retry) addRetry({ file, item, duplicateAction, folderId, relPath });
            return false;
        };
        const setProgress = percent => {
            const value = Math.min(98, Math.max(0, percent));
            bar.value = Math.max(bar.value, value);
            status.textContent = `上传中 ${Math.round(value)}%`;
        };

        // 目标文件夹以条目自带的为准（文件夹上传时每个文件落在各自子目录）；散落文件仍落当前浏览的文件夹
        const targetFolderId = folderId ?? document.body.dataset.folder ?? '0';
        const startFields = extra => ({ action: 'start', filename: file.name, size: file.size, duplicate_action: duplicateAction || '', folder_id: String(targetFolderId || '0'), ...extra });
        let started;
        try {
            started = await controlRequest(startFields());
        } catch (_) {
            return fail('网络连接失败，未能与服务器通信');
        }
        // 服务器暂存额度已满：询问是否改为「在线上传」（文件直传 Notion、不经服务器暂存）
        if (!started.ok && started.data.code === 'server_disk_exceeded') {
            const goOnline = await askConfirm(`${started.data.message || '服务器暂存空间已满，无可用额度。'}\n\n是否改为「在线上传 Notion」？上传不经服务器暂存，但无法进行自动内容审核。`);
            // 拒绝或额度随后释放：保留重试按钮，用户可稍后再试（额度可能已恢复）
            if (!goOnline) return fail(failureText(started.status, started.data));
            status.textContent = '正在准备在线上传';
            try {
                started = await controlRequest(startFields({ direct: '1' }));
            } catch (_) {
                return fail('网络连接失败，未能与服务器通信');
            }
        }
        if (!started.ok || !started.data.ok) {
            // 关键词拦截：属于确定性拒绝，重试也不会通过，改为提示申诉号
            if (started.data.code === 'moderation_rejected') {
                held += 1;
                return fail(failureText(started.status, started.data), false);
            }
            // 在线上传不可用：确定性拒绝，重试也不会通过
            if (['direct_not_allowed', 'direct_unavailable'].includes(started.data.code)) {
                return fail(failureText(started.status, started.data), false);
            }
            return fail(failureText(started.status, started.data));
        }
        const onlineUpload = started.data.online === true;

        const token = started.data.upload_token;
        const chunkSize = Number(started.data.chunk_size) || file.size || 1;
        let total = Number(started.data.total) || 1;
        // 服务端可能因重名把文件自动编号，以服务端返回的名称为准
        const replacedOld = started.data.duplicate === 'overwrite';
        if (replacedOld) overwritten += 1;
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
        status.textContent = onlineUpload ? '正在完成在线上传' : '正在写入存储';
        let finished;
        try {
            finished = await controlRequest({ action: 'finish', upload_token: token });
        } catch (_) {
            return fail('网络连接中断，文件已送达服务器但未确认结果，请刷新页面查看是否已上传成功');
        }
        if (!finished.ok || !finished.data.ok) return fail(failureText(finished.status, finished.data));

        uploaded += 1;
        // 递减剩余空间：文件夹上传会一次排很多文件，这样后续文件能在入队/上传前就报「空间不足」，
        // 而不是全传完才失败。覆盖旧文件会释放原有占用，故不递减。
        if (remaining !== null && !replacedOld) remaining = Math.max(0, remaining - file.size);
        // 在线上传：finish 时已直接写入并落库（active），无需再轮询写入进度
        if (finished.data.writing === false) {
            onlineDone += 1;
            bar.value = 100;
            status.textContent = '已在线上传完成';
            item.classList.add('complete');
            return true;
        }

        // 文件已入库：进度条交给「写入存储」阶段继续走（0% → 100%），由 waitForWrites() 轮询刷新；
        // 内容审核也在这个阶段之后由服务端完成，结论稍后可在文件列表 / 账户面板查看
        const uploadId = Number(finished.data.upload_id) || 0;
        bar.value = 0;
        status.textContent = '已提交，正在写入存储 0%';
        if (uploadId > 0) pendingWrite.push({ uploadId, item, bar, status });
        return true;
    };

    // 提交后继续轮询「写入 Notion」的真实进度：每个文件一条进度条，全部结束（或超时）后交给 runQueue() 刷新页面
    const WRITE_POLL_INTERVAL = 1500;
    const WRITE_POLL_TIMEOUT = 20 * 60 * 1000;
    const waitForWrites = async () => {
        const deadline = Date.now() + WRITE_POLL_TIMEOUT;
        while (pendingWrite.length && Date.now() < deadline) {
            const items = await fetchWriteStatus(pendingWrite.map(entry => entry.uploadId));
            if (items !== null) {
                for (const entry of [...pendingWrite]) {
                    const info = items[String(entry.uploadId)];
                    if (!info) continue;
                    if (info.status === 'writing') {
                        const percent = Math.max(0, Math.min(100, Number(info.progress) || 0));
                        entry.bar.value = Math.max(entry.bar.value, percent);
                        entry.status.textContent = `正在写入存储 ${entry.bar.value}%`;
                        continue;
                    }

                    pendingWrite.splice(pendingWrite.indexOf(entry), 1);
                    if (info.status === 'active') {
                        entry.bar.value = 100;
                        entry.status.textContent = '已写入存储';
                        entry.item.classList.add('complete');
                    } else if (info.status === 'quarantined') {
                        // 写入完成但内容审核命中：与改造前的语义一致，文件被暂停使用
                        entry.bar.value = 100;
                        entry.status.textContent = '已写入存储，但内容审核命中，已暂停使用（可在首页查看申诉号）';
                        entry.item.classList.add('failed');
                        held += 1;
                    } else if (info.status === 'write_failed') {
                        writeFailed += 1;
                        entry.status.textContent = '写入存储失败，文件仍可下载；请在列表中点击「重试写入」';
                        entry.item.classList.add('failed');
                    } else {
                        // 其它终态（如在别的页面已把该文件删除）：不再提示写入失败
                        entry.status.textContent = '该文件已被删除';
                        entry.item.classList.add('failed');
                    }
                }
            }
            if (!pendingWrite.length) break;
            await new Promise(resolve => window.setTimeout(resolve, WRITE_POLL_INTERVAL));
        }

        // 超时仍未写完：不无限阻塞页面，提示可稍后刷新查看（后台仍在继续写入）
        for (const entry of pendingWrite) {
            entry.status.textContent = '仍在写入存储，可稍后刷新页面查看进度';
        }
        pendingWrite.length = 0;
    };

    async function runQueue() {
        if (busy) return;
        busy = true;
        const uploadedBefore = uploaded;
        while (pending.length) await upload(pending.shift());
        busy = false;

        // 仍有可重试的失败条目：保留错误文案与「重试」按钮，不刷新页面（刷新会把失败原因冲掉）。
        // 此时不等待写入完成，已提交的条目继续在后台写入，用户稍后刷新即可看到结果
        const failedNow = queue.querySelectorAll('[data-retry]').length;
        if (failedNow > 0) {
            for (const entry of pendingWrite) {
                entry.status.textContent = '已提交，正在后台写入存储，可稍后刷新页面查看';
            }
            pendingWrite.length = 0;
            const parts = [];
            const okCount = uploaded - uploadedBefore;
            if (okCount > 0) parts.push(`成功 ${okCount} 个`);
            parts.push(`失败 ${failedNow} 个`);
            notify(`上传结束：${parts.join('，')}，可点击失败条目的「重试」重新上传`, 'error');
            return;
        }

        // 全部提交成功：等写入进度条走完（或超时）再刷新页面，让用户看到真实的写入进度
        if (pendingWrite.length) await waitForWrites();

        if (uploaded > 0) {
            const extras = [];
            if (onlineDone) extras.push(`${onlineDone} 个文件通过「在线上传」直接写入 Notion`);
            if (overwritten) extras.push(`覆盖 ${overwritten} 个同名文件`);
            if (renamed) extras.push(`自动编号 ${renamed} 个同名文件`);
            if (held) extras.push(`${held} 个文件未通过内容审核，已暂停使用（首页可查看申诉号）`);
            if (writeFailed) extras.push(`${writeFailed} 个文件写入存储失败，可在列表中点击「重试写入」`);
            const suffix = extras.length ? `（${extras.join('，')}）` : '';
            // 全部为在线上传时已同步写完，不再说「正在写入存储」，避免与事实不符
            const headline = uploaded - onlineDone > 0
                ? `已提交 ${uploaded} 个文件，正在写入存储，完成后自动转为 Notion 副本。`
                : `已在线上传 Notion 完成 ${uploaded} 个文件。`;
            window.sessionStorage.setItem('uploadNotice', `上传成功：${headline}${suffix}`);
            // 新文件按时间倒序排在第一页，若停在后面的页码会看不到刚上传的文件，因此回到第一页
            const url = new URL(window.location.href);
            url.searchParams.delete('page');
            window.location.replace(url.toString());
        }
    }

    browse.addEventListener('click', event => { event.stopPropagation(); input.click(); });
    if (browseFolder) browseFolder.addEventListener('click', async event => {
        event.stopPropagation();
        // 支持 webkitdirectory 的浏览器走原生目录选择；否则退回 File System Access API，避免退化成「选文件」
        if (dirPickerSupported) {
            folderInput.click();
            return;
        }
        if (!dirPickerFallback) {
            notify('当前浏览器不支持选择文件夹，请把文件夹直接拖进来，或改用 Chrome / Edge。', 'error');
            return;
        }
        try {
            const picked = await pickDirectoryFiles();
            if (picked.length) addFolderFiles(picked);
        } catch (error) {
            // 用户自己关掉选择窗口属正常取消，不提示
            if (!error || error.name !== 'AbortError') notify('无法读取该文件夹，请改用拖拽方式上传。', 'error');
        }
    });
    // 只认用户真实点击：browseFolder 里 folderInput.click() 产生的合成点击会冒泡到这里，目标不是 button，
    // 旧写法会顺手再点一次 #fileInput，弹出的文件窗把文件夹窗顶掉（现象就是「选择文件夹」变成了选文件）
    dropZone.addEventListener('click', event => {
        if (!event.isTrusted || event.target.closest('button')) return;
        input.click();
    });
    dropZone.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); input.click(); } });
    input.addEventListener('change', () => { addFiles(input.files); input.value = ''; });
    if (folderInput) folderInput.addEventListener('change', () => {
        addFolderFiles([...folderInput.files].map(file => ({ file, relDir: relativeDirOf(file) })));
        folderInput.value = '';
    });
    ['dragenter', 'dragover'].forEach(type => dropZone.addEventListener(type, event => { event.preventDefault(); dropZone.classList.add('dragging'); }));
    ['dragleave', 'drop'].forEach(type => dropZone.addEventListener(type, event => { event.preventDefault(); dropZone.classList.remove('dragging'); }));
    dropZone.addEventListener('drop', async event => {
        // 优先用 items 递归读取：拖入文件夹时 dataTransfer.files 拿不到目录内的文件
        const items = event.dataTransfer ? event.dataTransfer.items : null;
        if (items && items.length && typeof items[0].webkitGetAsEntry === 'function') {
            const entries = [];
            for (const item of items) {
                const entry = item.webkitGetAsEntry ? item.webkitGetAsEntry() : null;
                if (entry) entries.push(entry);
            }
            const collected = await collectDropped(entries);
            const fromDirs = collected.filter(item => item.relDir !== '');
            if (fromDirs.length) {
                // 混拖时先把目录内的文件按目录建树上传，散落文件仍走原路径
                addFolderFiles(fromDirs);
                const loose = collected.filter(item => item.relDir === '').map(item => item.file);
                if (loose.length) addFiles(loose);
                return;
            }
            addFiles(collected.map(item => item.file));
            return;
        }
        addFiles(event.dataTransfer.files);
    });
})();
