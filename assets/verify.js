(() => {
    'use strict';

    const csrf = document.body.dataset.csrf || '';
    const buttons = document.querySelectorAll('[data-send-code]');
    if (!buttons.length) return;

    const setHint = (button, message, type) => {
        const form = button.closest('form');
        const hint = form ? form.querySelector('[data-code-hint]') : null;
        if (!hint) return;
        hint.textContent = message;
        hint.className = 'code-hint' + (type ? ' ' + type : '');
    };

    const startCountdown = (button) => {
        let seconds = 60;
        button.disabled = true;
        const tick = () => {
            if (seconds <= 0) {
                button.disabled = false;
                button.textContent = '重新获取验证码';
                return;
            }
            button.textContent = `重新获取（${seconds} 秒）`;
            seconds -= 1;
            window.setTimeout(tick, 1000);
        };
        tick();
    };

    buttons.forEach((button) => {
        button.addEventListener('click', async () => {
            const form = button.closest('form');
            if (!form) return;
            const emailInput = form.querySelector('[name="email"]');
            const email = emailInput ? emailInput.value.trim() : '';
            if (!email) {
                setHint(button, '请先填写邮箱地址。', 'error');
                if (emailInput) emailInput.focus();
                return;
            }

            button.disabled = true;
            setHint(button, '正在发送验证码…', '');

            try {
                const response = await fetch('api/verify.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': csrf,
                    },
                    body: new URLSearchParams({ purpose: button.dataset.purpose || '', email }),
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.message || '验证码发送失败，请稍后再试。');
                }
                setHint(button, data.message, 'success');
                startCountdown(button);
            } catch (error) {
                setHint(button, error.message, 'error');
                button.disabled = false;
                button.textContent = '获取验证码';
            }
        });
    });
})();
