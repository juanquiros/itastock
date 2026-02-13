# Importación / Exportación de Productos v2

## Columnas soportadas (CSV con `;`)

### Requeridas
- `sku`
- `name`
- `basePrice`

### Opcionales (core)
- `barcode`
- `cost`
- `stockMin`
- `isActive`
- `category`
- `brand`
- `characteristics`
- `ivaRate`
- `targetStock`
- `uomBase`
- `allowsFractionalQty`
- `qtyStep`
- `supplierSku`
- `purchasePrice`
- `searchText`

## Ejemplo CSV completo

```csv
sku;barcode;name;cost;basePrice;stockMin;isActive;category;brand;characteristics;ivaRate;targetStock;uomBase;allowsFractionalQty;qtyStep;supplierSku;purchasePrice;searchText
REP-0001;7791234567890;Amortiguador delantero;8500.00;12500.00;2.000;1;Suspensión;Monroe;{"marca_vehiculo":"Peugeot","modelo_vehiculo":"208","lado":"Der-Izq"};21.00;5.000;UNIT;0;;;amortiguador delantero monroe
```

## Ejemplo CSV minimalista

```csv
sku;name;basePrice
REP-0002;Buje parrilla;4200.00
```

## Ejemplo `characteristics` JSON

```text
{"marca_vehiculo":"Peugeot","modelo_vehiculo":"208","lado":"Der-Izq"}
```

## Ejemplo `characteristics` key=value

```text
marca_vehiculo=Peugeot|modelo_vehiculo=208|lado=Der-Izq
```

## Dry run

En la pantalla de importación, activar **Dry run** para validar filas sin aplicar cambios en base.

- No crea productos.
- No crea categorías.
- No crea marcas.
- Devuelve conteo de `created/updated/failed` simulado y motivos por fila.

## Reindexar búsqueda (si hace falta)

Normalmente no es necesario porque la importación dispara actualización de `search_text`.

Si necesitás forzar recálculo:

```bash
php bin/console app:products:reindex-search
```
