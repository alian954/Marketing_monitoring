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
