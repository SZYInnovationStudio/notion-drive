// 带 data-confirm 的按钮统一由 app.js 的自定义确认弹窗接管，这里不再拦截

// 用户管理页的搜索框：按行上的 data-user-search（用户名 + 邮箱）即时过滤
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

// 用户管理页的「开通订阅」：与「编辑」并列，点击后在该行展开时长选择，再点「确认开通」提交
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
