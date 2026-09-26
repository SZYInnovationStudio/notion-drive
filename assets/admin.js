document.querySelectorAll('[data-confirm]').forEach(button => {
    button.addEventListener('click', event => {
        if (!window.confirm(button.dataset.confirm)) event.preventDefault();
    });
});
