import { startStimulusApp } from '@symfony/stimulus-bundle';
import CatalogLookupController from './controllers/catalog_lookup_controller.js';
import BrandLogoUploadController from './controllers/brand_logo_upload_controller.js';
import BarcodeScannerController from './controllers/barcode_scanner_controller.js';
import BarcodeSoundUploadController from './controllers/barcode_sound_upload_controller.js';
import TicketImageUploadController from './controllers/ticket_image_upload_controller.js';

const app = startStimulusApp();
app.register('catalog-lookup', CatalogLookupController);
app.register('brand-logo-upload', BrandLogoUploadController);
app.register('barcode-scanner', BarcodeScannerController);
app.register('barcode-sound-upload', BarcodeSoundUploadController);
app.register('ticket-image-upload', TicketImageUploadController);
