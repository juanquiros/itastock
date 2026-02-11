import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list', 'prototype', 'error'];

    connect() {
        this.index = this.listTarget.querySelectorAll('[data-characteristic-row]').length;
        this.validate();
    }

    addRow() {
        const template = this.prototypeTarget.innerHTML.trim().replace(/__name__/g, this.index);
        this.listTarget.insertAdjacentHTML('beforeend', template);
        this.index += 1;
        this.validate();
    }

    removeRow(event) {
        const row = event.currentTarget.closest('[data-characteristic-row]');
        if (row) {
            row.remove();
        }
        this.validate();
    }

    validate() {
        const rows = this.listTarget.querySelectorAll('[data-characteristic-row]');
        const seen = new Set();
        let hasError = false;

        rows.forEach((row) => {
            const keyInput = row.querySelector('[data-characteristic-key]');
            const valueInput = row.querySelector('[data-characteristic-value]');
            const key = (keyInput?.value || '').trim();
            const value = (valueInput?.value || '').trim();

            keyInput?.classList.remove('is-invalid');
            valueInput?.classList.remove('is-invalid');

            if ((key && !value) || (!key && value)) {
                hasError = true;
                keyInput?.classList.add('is-invalid');
                valueInput?.classList.add('is-invalid');
            }

            if (key) {
                const normalized = key.toLowerCase();
                if (seen.has(normalized)) {
                    hasError = true;
                    keyInput?.classList.add('is-invalid');
                }
                seen.add(normalized);
            }
        });

        if (this.hasErrorTarget) {
            this.errorTarget.classList.toggle('d-none', !hasError);
            this.errorTarget.textContent = hasError ? 'Revisá características: clave/valor completos y claves sin duplicados.' : '';
        }
    }
}
