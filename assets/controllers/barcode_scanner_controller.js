import { Controller } from '@hotwired/stimulus';

// Patrón de uso:
// - Marcar el input con data-barcode-scanner-input="true" (opcionalmente data-barcode-scanner-target="input").
// - Agregar un botón con data-action="barcode-scanner#open" y data-barcode-scanner-input-id-param="ID_DEL_INPUT".
// - Incluir el modal reutilizable con data-barcode-scanner-target="modal" y el contenedor de cámara
//   con data-barcode-scanner-target="scanner" dentro del mismo scope del controller.
export default class extends Controller {
    static targets = ['input', 'modal', 'scanner'];
    static values = {
        scriptUrl: String,
        successSoundUrl: String,
        continuous: Boolean,
    };

    connect() {
        this.activeInput = null;
        this.modalInstance = null;
        this.html5QrCode = null;
        this.libraryLoadingPromise = null;
        this.modalListenerAttached = false;
        this.lastScan = { text: null, time: 0 };
        this.successSound = null;
        this.handleModalHidden = this.handleModalHidden.bind(this);
    }

    disconnect() {
        this.stopScanner();
        this.detachModalListener();
    }

    open(event) {
        const inputId = event.params.inputId;
        this.activeInput = inputId ? document.getElementById(inputId) : null;

        if (!this.activeInput) {
            const fallbackInput = event.currentTarget.closest('[data-barcode-scanner-input-group]')?.querySelector('[data-barcode-scanner-input]');
            this.activeInput = fallbackInput || null;
        }

        if (!this.activeInput) {
            return;
        }

        if (!this.resolveLibrary()) {
            this.ensureLibraryLoaded()
                .then(() => {
                    if (!this.resolveLibrary()) {
                        throw new Error('Html5Qrcode missing');
                    }
                    this.open(event);
                })
                .catch(() => {
                    window.alert('La librería de escaneo no se cargó correctamente. Podés ingresar el código manualmente.');
                });
            return;
        }

        if (!this.hasModalTarget || !this.hasScannerTarget) {
            window.alert('No se encontró el modal de escaneo. Podés ingresar el código manualmente.');
            return;
        }

        const bootstrapModal = window.bootstrap?.Modal;
        if (!bootstrapModal) {
            window.alert('No se pudo inicializar el modal de escaneo. Podés ingresar el código manualmente.');
            return;
        }

        this.modalInstance = bootstrapModal.getOrCreateInstance(this.modalTarget);
        this.attachModalListener();
        this.modalInstance.show();

        window.setTimeout(() => {
            this.startScanner();
        }, 200);
    }

    ensureLibraryLoaded() {
        if (this.libraryLoadingPromise) {
            return this.libraryLoadingPromise;
        }

        const scriptUrl = this.hasScriptUrlValue ? this.scriptUrlValue : '/vendor/html5-qrcode.min.js';
        this.libraryLoadingPromise = new Promise((resolve, reject) => {
            const existingScript = document.querySelector(`script[src="${scriptUrl}"]`);
            if (existingScript) {
                existingScript.addEventListener('load', () => resolve(), { once: true });
                existingScript.addEventListener('error', () => reject(new Error('Failed to load script')), { once: true });
                if (window.Html5Qrcode) {
                    resolve();
                }
                return;
            }

            const script = document.createElement('script');
            script.src = scriptUrl;
            script.defer = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Failed to load script'));
            document.head.appendChild(script);
        });

        return this.libraryLoadingPromise;
    }

    resolveLibrary() {
        if (window.Html5Qrcode) {
            return window.Html5Qrcode;
        }

        const fallbackLibrary = window.__Html5QrcodeLibrary__?.Html5Qrcode;
        if (fallbackLibrary) {
            window.Html5Qrcode = fallbackLibrary;
            return window.Html5Qrcode;
        }

        return null;
    }

    startScanner() {
        const Html5Qrcode = this.resolveLibrary();
        if (!Html5Qrcode || !this.hasScannerTarget) {
            return;
        }

        if (this.html5QrCode && this.html5QrCode.isScanning) {
            return;
        }

        const scannerId = this.scannerTarget.id;
        if (!scannerId) {
            return;
        }

        this.html5QrCode = new Html5Qrcode(scannerId, {
            useBarCodeDetectorIfSupported: false,
            experimentalFeatures: { useBarCodeDetectorIfSupported: false },
        });
        const config = {
            fps: 10,
            qrbox: { width: 280, height: 180 },
            useBarCodeDetectorIfSupported: false,
            experimentalFeatures: { useBarCodeDetectorIfSupported: false },
        };

        this.html5QrCode
            .start({ facingMode: 'environment' }, config, this.onScanSuccess.bind(this), this.onScanFailure.bind(this))
            .catch((error) => {
                this.handleScannerError(error);
            });
    }

    onScanSuccess(decodedText) {
        if (!this.activeInput) {
            return;
        }

        const now = Date.now();
        if (decodedText === this.lastScan.text && now - this.lastScan.time < 800) {
            return;
        }
        this.lastScan = { text: decodedText, time: now };

        this.activeInput.value = decodedText;
        this.activeInput.dispatchEvent(new Event('input', { bubbles: true }));
        this.activeInput.dispatchEvent(new Event('change', { bubbles: true }));
        this.activeInput.dispatchEvent(new Event('blur', { bubbles: true }));
        this.activeInput.dispatchEvent(new CustomEvent('barcode-scanner:scan', { bubbles: true, detail: { code: decodedText } }));

        this.playSuccessSound();

        if (!this.continuousValue) {
            if (this.modalInstance) {
                this.modalInstance.hide();
            }
            this.stopScanner();
        }
    }

    onScanFailure() {
        // Silencioso: la librería dispara muchos eventos mientras busca.
    }

    handleScannerError() {
        if (this.modalInstance) {
            this.modalInstance.hide();
        }
        this.stopScanner();
        window.alert('No se pudo acceder a la cámara. Verificá los permisos o ingresá el código manualmente.');
    }

    handleModalHidden() {
        this.stopScanner();
    }

    attachModalListener() {
        if (this.modalListenerAttached || !this.hasModalTarget) {
            return;
        }

        this.modalTarget.addEventListener('hidden.bs.modal', this.handleModalHidden);
        this.modalListenerAttached = true;
    }

    detachModalListener() {
        if (!this.modalListenerAttached || !this.hasModalTarget) {
            return;
        }

        this.modalTarget.removeEventListener('hidden.bs.modal', this.handleModalHidden);
        this.modalListenerAttached = false;
    }

    stopScanner() {
        if (!this.html5QrCode) {
            return;
        }

        const qrCode = this.html5QrCode;
        this.html5QrCode = null;

        if (qrCode.isScanning) {
            qrCode
                .stop()
                .catch(() => null)
                .finally(() => {
                    qrCode.clear().catch(() => null);
                });
        } else {
            qrCode.clear().catch(() => null);
        }
    }

    playSuccessSound() {
        if (!this.hasSuccessSoundUrlValue) {
            return;
        }

        if (!this.successSound || this.successSound.src !== this.successSoundUrlValue) {
            this.successSound = new Audio(this.successSoundUrlValue);
        }

        try {
            this.successSound.currentTime = 0;
            const playback = this.successSound.play();
            if (playback && typeof playback.catch === 'function') {
                playback.catch(() => null);
            }
        } catch (error) {
            console.warn('Unable to play scan sound', error);
        }
    }
}
