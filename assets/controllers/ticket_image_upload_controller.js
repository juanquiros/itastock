import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['file', 'path', 'preview', 'clearButton'];

    connect() {
        if (this.hasFileTarget) {
            this.fileTarget.addEventListener('change', (event) => this.onFileChange(event));
        }
        if (this.hasClearButtonTarget) {
            this.clearButtonTarget.addEventListener('click', () => this.clearImage());
        }
    }

    async onFileChange(event) {
        const file = event.target.files?.[0];
        if (!file) {
            return;
        }

        try {
            const dataUrl = await this.optimizeImage(file);
            if (this.hasPathTarget) {
                this.pathTarget.value = dataUrl;
            }
            if (this.hasPreviewTarget) {
                this.previewTarget.src = dataUrl;
                this.previewTarget.classList.remove('d-none');
            }
        } catch (error) {
            console.error('Error optimizing ticket image', error);
        }
    }

    clearImage() {
        if (this.hasPathTarget) {
            this.pathTarget.value = '';
        }
        if (this.hasFileTarget) {
            this.fileTarget.value = '';
        }
        if (this.hasPreviewTarget) {
            this.previewTarget.src = '';
            this.previewTarget.classList.add('d-none');
        }
    }

    optimizeImage(file) {
        const maxSize = 640;

        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => {
                URL.revokeObjectURL(img.src);
                const scale = Math.min(1, maxSize / Math.max(img.width, img.height));
                const width = Math.round(img.width * scale);
                const height = Math.round(img.height * scale);

                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;

                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    reject(new Error('Canvas not supported'));
                    return;
                }

                ctx.drawImage(img, 0, 0, width, height);

                const exportBlob = (type) => {
                    canvas.toBlob((blob) => {
                        if (!blob) {
                            reject(new Error('Unable to export image'));
                            return;
                        }
                        const reader = new FileReader();
                        reader.onload = () => resolve(reader.result);
                        reader.onerror = reject;
                        reader.readAsDataURL(blob);
                    }, type, 0.82);
                };

                canvas.toBlob((blob) => {
                    if (!blob) {
                        exportBlob('image/png');
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = () => resolve(reader.result);
                    reader.onerror = reject;
                    reader.readAsDataURL(blob);
                }, 'image/webp', 0.82);
            };

            img.onerror = reject;
            img.src = URL.createObjectURL(file);
        });
    }
}
