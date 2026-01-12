import { Controller } from '@hotwired/stimulus';

// Convención para inputs escaneables:
// - El input debe tener data-barcode-scanner-target="input" y data-role="barcode-input".
// - El botón debe tener data-action="barcode-scanner#open" y data-barcode-scanner-input-id="<id_del_input>".
// - Alternativamente, el botón puede vivir en el mismo input-group que el input target.
export default class extends Controller {
    static targets = ['modal', 'reader', 'status', 'input'];

    connect() {
        this.activeInput = null;
        this.codeReader = null;
        this.isScanning = false;
        this.modalReady = false;
        this.markScannerAvailability();
        this.setupModal();
    }

    setupModal() {
        if (!this.hasModalTarget || !window.bootstrap?.Modal) {
            return;
        }

        if (!this.modalInstance) {
            this.modalInstance = new window.bootstrap.Modal(this.modalTarget);
        }

        if (!this.modalReady) {
            this.modalTarget.addEventListener('shown.bs.modal', () => this.startScanner());
            this.modalTarget.addEventListener('hidden.bs.modal', () => this.stopScanner());
            this.modalReady = true;
        }
    }

    async open(event) {
        event.preventDefault();
        this.activeInput = this.resolveInput(event.currentTarget);

        if (!this.activeInput) {
            this.showStatus('No se encontró el input asociado para completar el código.');
            return;
        }

        this.setupModal();
        if (!this.modalInstance) {
            this.notifyUser('No se pudo abrir el escáner. Actualizá la página e intentá nuevamente.');
            return;
        }

        if (!(await this.ensureLibrary())) {
            this.notifyUser('La librería de escaneo no se cargó correctamente.');
            return;
        }

        if (!this.isCameraSupported()) {
            this.notifyUser('Este dispositivo no soporta cámara o no permite acceso.');
            return;
        }

        this.clearStatus();
        this.modalInstance?.show();
    }

    resolveInput(button) {
        const inputId = button.getAttribute('data-barcode-scanner-input-id');
        if (inputId) {
            return document.getElementById(inputId);
        }

        const container = button.closest('[data-barcode-scanner-scope]') || button.closest('.input-group');
        if (container) {
            return container.querySelector('[data-barcode-scanner-target="input"]');
        }

        return null;
    }

    startScanner() {
        if (!this.activeInput || this.isScanning || !window.ZXingBrowser?.BrowserMultiFormatReader) {
            return;
        }

        const videoElement = this.ensureVideoElement();
        if (!videoElement) {
            this.showStatus('No se pudo iniciar la cámara. Verificá el dispositivo.');
            return;
        }

        this.codeReader = new window.ZXingBrowser.BrowserMultiFormatReader();
        this.codeReader
            .decodeFromVideoDevice(null, videoElement, (result, error) => {
                if (result) {
                    this.applyScan(result.getText());
                } else if (error && !(error instanceof window.ZXingBrowser.NotFoundException)) {
                    this.showStatus('No se pudo leer el código. Intentá nuevamente.');
                }
            })
            .then(() => {
                this.isScanning = true;
            })
            .catch((error) => {
                if (this.isPermissionDenied(error)) {
                    this.notifyUser('Permiso de cámara denegado. Podés ingresar el código manualmente.');
                    this.modalInstance?.hide();
                    return;
                }
                this.showStatus('No se pudo iniciar la cámara. Verificá permisos o el dispositivo.');
            });
    }

    applyScan(decodedText) {
        if (!this.activeInput) {
            return;
        }

        this.activeInput.value = decodedText;
        ['input', 'change', 'blur'].forEach((type) => {
            this.activeInput.dispatchEvent(new Event(type, { bubbles: true }));
        });

        this.modalInstance?.hide();
    }

    ensureVideoElement() {
        if (!this.hasReaderTarget) {
            return null;
        }

        const existingVideo = this.readerTarget.querySelector('video');
        if (existingVideo) {
            return existingVideo;
        }

        this.readerTarget.innerHTML = '';
        const video = document.createElement('video');
        video.setAttribute('playsinline', 'true');
        video.classList.add('w-100');
        this.readerTarget.appendChild(video);
        return video;
    }

    stopScanner() {
        if (!this.codeReader || !this.isScanning) {
            return;
        }

        try {
            this.codeReader.reset();
        } catch (error) {
            // Ignore cleanup errors to avoid blocking the modal close flow.
        } finally {
            this.codeReader = null;
            this.isScanning = false;
        }
    }

    ensureLibrary() {
        if (typeof window.ZXingBrowser !== 'undefined') {
            return Promise.resolve(true);
        }

        const existingScript = document.querySelector('script[src*="@zxing/browser"]');
        if (existingScript) {
            if (existingScript.src.includes('index.min.js')) {
                const [primarySource] = this.getLibrarySources();
                existingScript.dataset.loaded = 'false';
                existingScript.src = primarySource;
                this.libraryPromise = null;
                return new Promise((resolve) => {
                    existingScript.addEventListener('load', () => resolve(typeof window.ZXingBrowser !== 'undefined'), { once: true });
                    existingScript.addEventListener('error', () => resolve(false), { once: true });
                    setTimeout(() => resolve(typeof window.ZXingBrowser !== 'undefined'), 2500);
                }).then((loaded) => loaded ? true : this.loadLibraryFallback());
            }

            return new Promise((resolve) => {
                if (existingScript.dataset.loaded === 'true' || existingScript.readyState === 'complete' || existingScript.readyState === 'loaded') {
                    resolve(typeof window.ZXingBrowser !== 'undefined');
                    return;
                }

                let resolved = false;
                const finalize = (value) => {
                    if (resolved) {
                        return;
                    }
                    resolved = true;
                    resolve(value);
                };

                existingScript.addEventListener('load', () => finalize(typeof window.ZXingBrowser !== 'undefined'), { once: true });
                existingScript.addEventListener('error', () => finalize(false), { once: true });
                setTimeout(() => finalize(typeof window.ZXingBrowser !== 'undefined'), 2500);
            }).then((loaded) => loaded ? true : this.loadLibraryFallback());
        }

        if (this.libraryPromise) {
            return this.libraryPromise.then((loaded) => loaded ? true : this.loadLibraryFallback());
        }

        this.libraryPromise = this.loadLibraryFallback();

        return this.libraryPromise;
    }

    loadLibraryFallback() {
        const sources = this.getLibrarySources();

        const tryLoad = (index) => new Promise((resolve) => {
            if (typeof window.ZXingBrowser !== 'undefined') {
                resolve(true);
                return;
            }

            const script = document.createElement('script');
            script.src = sources[index];
            script.async = true;
            script.onload = () => {
                script.dataset.loaded = 'true';
                resolve(typeof window.ZXingBrowser !== 'undefined');
            };
            script.onerror = () => resolve(false);
            document.head.appendChild(script);
        }).then((loaded) => {
            if (loaded || index >= sources.length - 1) {
                return loaded;
            }

            return tryLoad(index + 1);
        });

        return tryLoad(0);
    }

    getLibrarySources() {
        return [
            'https://cdn.jsdelivr.net/npm/@zxing/browser@0.1.5/umd/zxing-browser.min.js',
            'https://unpkg.com/@zxing/browser@0.1.5/umd/zxing-browser.min.js',
        ];
    }

    isCameraSupported() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    markScannerAvailability() {
        const hasTouch = navigator.maxTouchPoints > 0 || window.matchMedia('(pointer: coarse)').matches;
        if (hasTouch && this.isCameraSupported()) {
            document.body.classList.add('barcode-scanner-available');
        } else {
            document.body.classList.remove('barcode-scanner-available');
        }
    }

    isPermissionDenied(error) {
        const message = error?.message || '';
        return message.includes('NotAllowedError') || message.includes('Permission');
    }

    showStatus(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
            this.statusTarget.classList.remove('d-none');
        }
    }

    clearStatus() {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = '';
            this.statusTarget.classList.add('d-none');
        }
    }

    notifyUser(message) {
        window.alert(message);
    }
}
