import { Controller } from '@hotwired/stimulus';

// Convención para inputs escaneables:
// - El input debe tener data-barcode-scanner-target="input" y data-role="barcode-input".
// - El botón debe tener data-action="barcode-scanner#open" y data-barcode-scanner-input-id="<id_del_input>".
// - Alternativamente, el botón puede vivir en el mismo input-group que el input target.
export default class extends Controller {
    static targets = ['modal', 'reader', 'status', 'input'];

    connect() {
        this.activeInput = null;
        this.scanner = null;
        this.isScanning = false;

        if (this.hasModalTarget && window.bootstrap?.Modal) {
            this.modalInstance = new window.bootstrap.Modal(this.modalTarget);
            this.modalTarget.addEventListener('shown.bs.modal', () => this.startScanner());
            this.modalTarget.addEventListener('hidden.bs.modal', () => this.stopScanner());
        }
    }

    open(event) {
        event.preventDefault();
        this.activeInput = this.resolveInput(event.currentTarget);

        if (!this.activeInput) {
            this.showStatus('No se encontró el input asociado para completar el código.');
            return;
        }

        if (!this.ensureLibrary()) {
            this.showStatus('La librería de escaneo no se cargó correctamente.');
            return;
        }

        if (!this.isCameraSupported()) {
            this.showStatus('Este dispositivo no soporta cámara o no permite acceso.');
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
        if (!this.activeInput || this.isScanning || !this.ensureLibrary()) {
            return;
        }

        const readerId = this.readerTarget?.id || 'barcode-scanner-reader';
        if (this.readerTarget && !this.readerTarget.id) {
            this.readerTarget.id = readerId;
        }

        this.scanner = new window.Html5Qrcode(readerId);
        const config = {
            fps: 10,
            qrbox: { width: 280, height: 180 },
            rememberLastUsedCamera: true,
        };

        if (window.Html5QrcodeSupportedFormats) {
            config.formatsToSupport = [
                window.Html5QrcodeSupportedFormats.EAN_13,
                window.Html5QrcodeSupportedFormats.EAN_8,
                window.Html5QrcodeSupportedFormats.UPC_A,
                window.Html5QrcodeSupportedFormats.UPC_E,
                window.Html5QrcodeSupportedFormats.CODE_128,
                window.Html5QrcodeSupportedFormats.CODE_39,
                window.Html5QrcodeSupportedFormats.ITF,
            ];
        }

        this.scanner
            .start({ facingMode: 'environment' }, config, (decodedText) => this.applyScan(decodedText))
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

    stopScanner() {
        if (!this.scanner || !this.isScanning) {
            return;
        }

        this.scanner
            .stop()
            .then(() => this.scanner.clear())
            .catch(() => {})
            .finally(() => {
                this.isScanning = false;
            });
    }

    ensureLibrary() {
        return typeof window.Html5Qrcode !== 'undefined';
    }

    isCameraSupported() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
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
