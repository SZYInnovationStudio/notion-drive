const userSearch = document.getElementById('user-search');
if (userSearch) {
    const rows = Array.from(document.querySelectorAll('[data-user-search]'));
    const empty = document.getElementById('user-search-empty');

    const applyUserSearch = () => {
        const keyword = userSearch.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const matched = keyword === '' || (row.dataset.userSearch || '').includes(keyword);
            row.classList.toggle('is-filtered-out', !matched);
            if (matched) visible += 1;
        });
        if (empty) empty.hidden = visible !== 0;
    };

    userSearch.addEventListener('input', applyUserSearch);
    applyUserSearch();
}

document.querySelectorAll('[data-grant-toggle]').forEach(toggle => {
    const row = toggle.closest('.admin-user-row');
    const panel = row ? row.querySelector('.user-row-grant') : null;
    const fold = row ? row.querySelector('details') : null;
    if (!panel || !fold) return;

    const setGrantOpen = open => {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle.addEventListener('click', event => {
        // summary 内的按钮要拦住默认行为，否则会同时触发「编辑」的折叠切换
        event.preventDefault();
        event.stopPropagation();
        setGrantOpen(panel.hidden);
    });

    // 「编辑」与「续费订阅」是两个并列入口：只要折叠状态发生变化就收起开通面板，避免两块同时展开
    fold.addEventListener('toggle', () => setGrantOpen(false));

    const cancel = panel.querySelector('[data-grant-cancel]');
    if (cancel) cancel.addEventListener('click', () => setGrantOpen(false));
});
