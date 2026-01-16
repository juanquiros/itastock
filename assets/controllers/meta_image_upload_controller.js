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
            console.error('Error optimizing meta image', error);
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
        const targetWidth = 1200;
        const targetHeight = 630;
        const targetRatio = targetWidth / targetHeight;

        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => {
                URL.revokeObjectURL(img.src);

                const sourceRatio = img.width / img.height;
                let sx = 0;
                let sy = 0;
                let sw = img.width;
                let sh = img.height;

                if (sourceRatio > targetRatio) {
                    sw = Math.round(img.height * targetRatio);
                    sx = Math.round((img.width - sw) / 2);
                } else if (sourceRatio < targetRatio) {
                    sh = Math.round(img.width / targetRatio);
                    sy = Math.round((img.height - sh) / 2);
                }

                const canvas = document.createElement('canvas');
                canvas.width = targetWidth;
                canvas.height = targetHeight;

                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    reject(new Error('Canvas not supported'));
                    return;
                }

                ctx.drawImage(img, sx, sy, sw, sh, 0, 0, targetWidth, targetHeight);

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
