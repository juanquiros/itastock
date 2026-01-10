import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['logoFile', 'logoPath', 'preview'];

    connect() {
        if (this.hasLogoFileTarget) {
            this.logoFileTarget.addEventListener('change', (event) => this.onFileChange(event));
        }
    }

    async onFileChange(event) {
        const file = event.target.files?.[0];
        if (!file) {
            return;
        }

        try {
            const dataUrl = await this.optimizeImage(file);
            if (this.hasLogoPathTarget) {
                this.logoPathTarget.value = dataUrl;
            }
            if (this.hasPreviewTarget) {
                this.previewTarget.src = dataUrl;
            }
        } catch (error) {
            console.error('Error optimizing logo', error);
        }
    }

    optimizeImage(file) {
        const maxSize = 512;

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
