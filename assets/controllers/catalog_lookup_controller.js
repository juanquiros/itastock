import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['barcode', 'lookupName', 'name', 'catalogProductId', 'suggestions'];
    static values = {
        barcodeUrl: String,
        nameUrl: String,
    };

    connect() {
        this.debounceTimer = null;
        if (this.hasBarcodeTarget) {
            this.barcodeTarget.addEventListener('change', () => this.onBarcodeLookup());
            this.barcodeTarget.addEventListener('blur', () => this.onBarcodeLookup());
        }

        if (this.hasLookupNameTarget) {
            this.lookupNameTarget.addEventListener('input', () => this.onLookupInput());
            this.lookupNameTarget.addEventListener('blur', () => this.hideSuggestions());
        }
    }

    onBarcodeLookup() {
        if (!this.hasBarcodeTarget) {
            return;
        }

        const barcode = this.barcodeTarget.value.trim();
        if (barcode.length === 0) {
            this.clearCatalogProduct();
            return;
        }

        fetch(`${this.barcodeUrlValue}?barcode=${encodeURIComponent(barcode)}`)
            .then((response) => response.ok ? response.json() : Promise.reject(new Error('Lookup failed')))
            .then((payload) => {
                if (!payload.found) {
                    this.clearCatalogProduct();
                    return;
                }

                this.applyCatalogProduct(payload.product);
            })
            .catch(() => {
                this.clearCatalogProduct();
            });
    }

    onLookupInput() {
        if (!this.hasLookupNameTarget) {
            return;
        }

        const query = this.lookupNameTarget.value.trim();
        this.clearCatalogProduct();

        if (query.length < 3) {
            this.hideSuggestions();
            return;
        }

        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => {
            fetch(`${this.nameUrlValue}?q=${encodeURIComponent(query)}`)
                .then((response) => response.ok ? response.json() : Promise.reject(new Error('Lookup failed')))
                .then((results) => this.renderSuggestions(results))
                .catch(() => this.hideSuggestions());
        }, 250);
    }

    renderSuggestions(results) {
        if (!this.hasSuggestionsTarget) {
            return;
        }

        this.suggestionsTarget.innerHTML = '';

        if (!Array.isArray(results) || results.length === 0) {
            this.hideSuggestions();
            return;
        }

        results.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action';
            button.textContent = item.label;
            button.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.applyCatalogProduct(item);
                this.lookupNameTarget.value = item.label;
                this.hideSuggestions();
            });
            this.suggestionsTarget.appendChild(button);
        });

        this.suggestionsTarget.style.display = 'block';
    }

    hideSuggestions() {
        if (!this.hasSuggestionsTarget) {
            return;
        }

        this.suggestionsTarget.style.display = 'none';
    }

    applyCatalogProduct(product) {
        if (this.hasCatalogProductIdTarget) {
            this.catalogProductIdTarget.value = product.id ?? '';
        }

        if (this.hasNameTarget) {
            const presentation = product.presentation ? ` ${product.presentation}` : '';
            this.nameTarget.value = `${product.name}${presentation}`.trim();
        }

        if (this.hasBarcodeTarget && product.barcode) {
            this.barcodeTarget.value = product.barcode;
        }

        if (this.hasLookupNameTarget && product.label) {
            this.lookupNameTarget.value = product.label;
        }
    }

    clearCatalogProduct() {
        if (this.hasCatalogProductIdTarget) {
            this.catalogProductIdTarget.value = '';
        }
    }
}
