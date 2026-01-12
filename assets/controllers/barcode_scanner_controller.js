import { Controller } from '@hotwired/stimulus';

// Convención para inputs escaneables:
// - El input debe tener data-barcode-scanner-target="input" y data-role="barcode-input".
// - El botón debe tener data-action="barcode-scanner#open" y data-barcode-scanner-input-id="<id_del_input>".
// - Alternativamente, el botón puede vivir en el mismo input-group que el input target.
export default class extends Controller {
    static targets = ['modal', 'scanner', 'status', 'input'];

    connect() {
        this.activeInput = null;
        this.html5Qrcode = null;
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

    open(event) {
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

        if (!this.ensureLibrary()) {
            this.notifyUser('No se pudo cargar el lector de códigos, verificá la conexión o usá el ingreso manual.');
            return;
        }

        if (!this.isCameraSupported()) {
            this.notifyUser('Este dispositivo no soporta cámara o no permite acceso.');
            return;
        }

        this.clearStatus();
        this.modalInstance?.show();
        window.setTimeout(() => this.startScanner(), 250);
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
        if (!this.activeInput || this.isScanning || !window.Html5Qrcode) {
            return;
        }

        const scannerId = this.resolveScannerId();
        if (!scannerId) {
            this.showStatus('No se pudo iniciar la cámara. Verificá el dispositivo.');
            return;
        }

        this.html5Qrcode = new window.Html5Qrcode(scannerId);
        const config = {
            fps: 10,
            qrbox: { width: 280, height: 180 },
        };

        this.html5Qrcode
            .start(
                { facingMode: 'environment' },
                config,
                (decodedText) => this.applyScan(decodedText),
                (errorMessage) => this.onScanFailure(errorMessage),
            )
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

        this.closeModal();
    }

    onScanFailure(_errorMessage) {
        // Lecturas fallidas son frecuentes; no interrumpir al usuario.
    }

    stopScanner() {
        if (!this.html5Qrcode || !this.isScanning) {
            return;
        }

        this.html5Qrcode
            .stop()
            .then(() => this.html5Qrcode.clear())
            .catch(() => {})
            .finally(() => {
                this.html5Qrcode = null;
                this.isScanning = false;
            });
    }

    ensureLibrary() {
        return typeof window.Html5Qrcode !== 'undefined';
    }

    isCameraSupported() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    resolveScannerId() {
        if (!this.hasScannerTarget) {
            return null;
        }

        if (!this.scannerTarget.id) {
            this.scannerTarget.id = 'barcode-scanner-reader';
        }

        return this.scannerTarget.id;
    }

    closeModal() {
        this.modalInstance?.hide();
        this.stopScanner();
    }

    markScannerAvailability() {
        const hasTouch = navigator.maxTouchPoints > 0 || window.matchMedia('(pointer: coarse)').matches;
        if (hasTouch && this.isCameraSupported()) {
            document.body.classList.add('barcode-scanner-available');
        } else {
            document.body.classList.remove('barcode-scanner-available');
        }
    }

    disconnect() {
        this.stopScanner();
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
