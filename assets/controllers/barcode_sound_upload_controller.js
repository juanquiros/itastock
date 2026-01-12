import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['soundFile', 'soundPath', 'preview', 'clearButton'];

    connect() {
        if (this.hasSoundFileTarget) {
            this.soundFileTarget.addEventListener('change', (event) => this.onFileChange(event));
        }
        if (this.hasClearButtonTarget) {
            this.clearButtonTarget.addEventListener('click', () => this.clearSound());
        }
    }

    async onFileChange(event) {
        const file = event.target.files?.[0];
        if (!file) {
            return;
        }

        try {
            const dataUrl = await this.readAsDataUrl(file);
            if (this.hasSoundPathTarget) {
                this.soundPathTarget.value = dataUrl;
            }
            if (this.hasPreviewTarget) {
                this.previewTarget.src = dataUrl;
                this.previewTarget.classList.remove('d-none');
            }
        } catch (error) {
            console.error('Error reading sound file', error);
        }
    }

    clearSound() {
        if (this.hasSoundPathTarget) {
            this.soundPathTarget.value = '';
        }
        if (this.hasSoundFileTarget) {
            this.soundFileTarget.value = '';
        }
        if (this.hasPreviewTarget) {
            this.previewTarget.src = '';
            this.previewTarget.classList.add('d-none');
        }
    }

    readAsDataUrl(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }
}
