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
