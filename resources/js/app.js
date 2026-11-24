document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
        return;
    }

    if (!window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});

document.querySelectorAll('[data-secret-toggle]').forEach((toggle) => {
    if (!(toggle instanceof HTMLButtonElement)) {
        return;
    }

    const inputId = toggle.getAttribute('aria-controls');
    const input = inputId ? document.getElementById(inputId) : null;

    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const isVisible = input.type === 'password';
        input.type = isVisible ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', String(isVisible));
        toggle.textContent = isVisible ? toggle.dataset.hideLabel : toggle.dataset.showLabel;
    });
});

const setupCheckboxSelection = (selectAllSelector, selectionSelector) => {
    const selectAll = document.querySelector(selectAllSelector);
    const selections = Array.from(document.querySelectorAll(selectionSelector));

    if (!(selectAll instanceof HTMLInputElement) || selections.length === 0) {
        return;
    }

    const synchronizeSelection = () => {
        const selectedCount = selections.filter((selection) => selection.checked).length;
        selectAll.checked = selectedCount === selections.length;
        selectAll.indeterminate = selectedCount > 0 && selectedCount < selections.length;
    };

    selectAll.addEventListener('change', () => {
        selections.forEach((selection) => {
            selection.checked = selectAll.checked;
        });
        synchronizeSelection();
    });

    selections.forEach((selection) => {
        selection.addEventListener('change', synchronizeSelection);
    });

    synchronizeSelection();
};

setupCheckboxSelection('[data-monitor-select-all]', '[data-monitor-select]');
setupCheckboxSelection('[data-incident-select-all]', '[data-incident-select]');

const copyButtons = Array.from(document.querySelectorAll('button[command="--copy"][commandfor]'));

if (copyButtons.length > 0) {
    const feedbackTimers = new WeakMap();
    let previousCopyMessage = '';

    const reportCopyResult = (button, succeeded) => {
        const status = button.nextElementSibling;

        if (!(status instanceof HTMLElement)) {
            return;
        }

        clearTimeout(feedbackTimers.get(button));
        status.textContent = '';

        let message = succeeded ? '已複製連結' : '複製失敗，請手動選取連結';

        if (message === previousCopyMessage) {
            message += '\u00a0';
        }

        previousCopyMessage = succeeded ? '已複製連結' : '複製失敗，請手動選取連結';
        requestAnimationFrame(() => {
            status.textContent = message;
        });
        feedbackTimers.set(button, setTimeout(() => {
            status.textContent = '';
        }, 2000));
    };

    const copyText = async (button, target) => {
        const text = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement
            ? target.value
            : target.innerText.trim();

        if (!text) {
            return;
        }

        try {
            await navigator.clipboard.writeText(text);
            reportCopyResult(button, true);
        } catch {
            reportCopyResult(button, false);
        }
    };

    copyButtons.forEach((button) => {
        button.hidden = false;
    });

    if ('CommandEvent' in window) {
        document.addEventListener('command', (event) => {
            if (!(event instanceof CommandEvent) || event.command !== '--copy') {
                return;
            }

            if (event.source instanceof HTMLButtonElement && event.target instanceof HTMLElement) {
                void copyText(event.source, event.target);
            }
        }, true);
    } else {
        document.addEventListener('click', (event) => {
            if (event.defaultPrevented || !(event.target instanceof Element)) {
                return;
            }

            const button = event.target.closest('button[command="--copy"][commandfor]');

            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            const targetId = button.getAttribute('commandfor');
            const target = targetId === null ? null : document.getElementById(targetId);

            if (target instanceof HTMLElement) {
                void copyText(button, target);
            }
        });
    }
}

document.querySelectorAll('[data-print-page]').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    button.addEventListener('click', () => window.print());
});
