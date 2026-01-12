import { Controller } from '@hotwired/stimulus';

// Patrón de uso:
// - Marcar el input con data-barcode-scanner-input="true" (opcionalmente data-barcode-scanner-target="input").
// - Agregar un botón con data-action="barcode-scanner#open" y data-barcode-scanner-input-id-param="ID_DEL_INPUT".
// - Incluir el modal reutilizable con data-barcode-scanner-target="modal" y el contenedor de cámara
//   con data-barcode-scanner-target="scanner" dentro del mismo scope del controller.
export default class extends Controller {
    static targets = ['input', 'modal', 'scanner'];

    connect() {
        this.activeInput = null;
        this.modalInstance = null;
        this.html5QrCode = null;
        this.modalListenerAttached = false;
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

        if (!window.Html5Qrcode) {
            window.alert('El escáner no está disponible en este navegador. Podés ingresar el código manualmente.');
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

    startScanner() {
        if (!window.Html5Qrcode || !this.hasScannerTarget) {
            return;
        }

        if (this.html5QrCode && this.html5QrCode.isScanning) {
            return;
        }

        const scannerId = this.scannerTarget.id;
        if (!scannerId) {
            return;
        }

        this.html5QrCode = new window.Html5Qrcode(scannerId);
        const config = {
            fps: 10,
            qrbox: { width: 280, height: 180 },
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

        this.activeInput.value = decodedText;
        this.activeInput.dispatchEvent(new Event('input', { bubbles: true }));
        this.activeInput.dispatchEvent(new Event('change', { bubbles: true }));
        this.activeInput.dispatchEvent(new Event('blur', { bubbles: true }));

        if (this.modalInstance) {
            this.modalInstance.hide();
        }

        this.stopScanner();
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
}
