(function () {
    function assignFieldNames(row, mode, index) {
        row.querySelectorAll('[data-field]').forEach((field) => {
            const fieldName = field.dataset.field;
            field.name = mode === 'single'
                ? `historical_immunization[${fieldName}]`
                : `historical_immunizations[${index}][${fieldName}]`;
        });
    }

    function updateRow(row) {
        const precision = row.querySelector('[data-date-precision]').value;
        const exactDate = row.querySelector('[data-exact-date]');
        const monthDates = row.querySelectorAll('[data-month-date]');
        const exactInput = row.querySelector('[data-field="administered_at"]');
        const monthInput = row.querySelector('[data-field="administered_month"]');
        const yearInput = row.querySelector('[data-field="administered_year"]');
        const isExact = precision === 'DAY';

        exactDate.hidden = !isExact;
        exactInput.required = isExact;
        exactInput.disabled = !isExact;
        monthDates.forEach((field) => {
            field.hidden = isExact;
            const input = field.querySelector('input, select');
            input.required = !isExact;
            input.disabled = isExact;
        });
        if (isExact) {
            monthInput.value = '';
            yearInput.value = '';
        } else {
            exactInput.value = '';
        }

        const vaccineSelect = row.querySelector('[data-historical-vaccine]');
        const selectedOption = vaccineSelect.selectedOptions[0];
        const doseCount = Number(selectedOption?.dataset.doseCount || 3);
        const doseSelect = row.querySelector('[data-field="dose_number"]');
        Array.from(doseSelect.options).forEach((option) => {
            if (option.value) option.disabled = Number(option.value) > doseCount;
        });
        if (Number(doseSelect.value) > doseCount) doseSelect.value = '';
    }

    document.querySelectorAll('[data-historical-immunizations]').forEach((container) => {
        const mode = container.dataset.mode;
        const list = container.querySelector('[data-historical-dose-list]');
        const template = container.querySelector('[data-historical-dose-template]');

        const addRow = () => {
            const row = template.content.firstElementChild.cloneNode(true);
            assignFieldNames(row, mode, list.children.length);
            row.querySelector('[data-date-precision]').addEventListener('change', () => updateRow(row));
            row.querySelector('[data-historical-vaccine]').addEventListener('change', () => updateRow(row));
            row.querySelector('[data-remove-historical-dose]').addEventListener('click', () => {
                row.remove();
                Array.from(list.children).forEach((item, index) => assignFieldNames(item, mode, index));
            });
            list.append(row);
            updateRow(row);
        };

        container.querySelector('[data-add-historical-dose]').addEventListener('click', addRow);
        if (mode === 'single') addRow();
    });
})();
