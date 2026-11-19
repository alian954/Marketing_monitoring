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

const monitorSelectAll = document.querySelector('[data-monitor-select-all]');
const monitorSelections = Array.from(document.querySelectorAll('[data-monitor-select]'));

if (monitorSelectAll instanceof HTMLInputElement && monitorSelections.length > 0) {
    const synchronizeMonitorSelection = () => {
        const selectedCount = monitorSelections.filter((selection) => selection.checked).length;
        monitorSelectAll.checked = selectedCount === monitorSelections.length;
        monitorSelectAll.indeterminate = selectedCount > 0 && selectedCount < monitorSelections.length;
    };

    monitorSelectAll.addEventListener('change', () => {
        monitorSelections.forEach((selection) => {
            selection.checked = monitorSelectAll.checked;
        });
        synchronizeMonitorSelection();
    });

    monitorSelections.forEach((selection) => {
        selection.addEventListener('change', synchronizeMonitorSelection);
    });

    synchronizeMonitorSelection();
}
