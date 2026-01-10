import { startStimulusApp } from '@symfony/stimulus-bundle';
import CatalogLookupController from './controllers/catalog_lookup_controller.js';

const app = startStimulusApp();
app.register('catalog-lookup', CatalogLookupController);
