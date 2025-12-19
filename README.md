# ItaStock MVP (Sprint 1)

Aplicación Symfony + Bootstrap para gestión de comercio.

## Inicio de sesión

1. Ejecutá las migraciones y dependencias según tu entorno (`composer install`, `php bin/console doctrine:migrations:migrate`).
2. Usá las credenciales sembradas:
   - **Administrador**: `admin@itastock.local` / `Admin1234!`
   - **Vendedor**: `seller@itastock.local` / `Seller1234!`
3. Ingresá en `/login` y, una vez autenticado, navegá a `/app`.

## Roles y accesos

- `ROLE_ADMIN`: acceso completo al backoffice (/app/admin/*) y al POS/Caja.
- `ROLE_SELLER`: acceso únicamente a POS (/app/pos/*) y Caja (/app/cash/*).

Las credenciales inválidas muestran un mensaje de error, y las rutas protegidas devuelven un 403 cuando no se tienen permisos.

## Dashboard operativo (ROLE_BUSINESS_ADMIN / ROLE_ADMIN)

- Ruta: `/app/dashboard` (alias `/app`), protegido para administradores del comercio y platform admins.
- Muestra KPIs diarios/mensuales, gráficos (ventas por hora, últimos 7 días, distribución por medio de pago), alertas de stock bajo/deudores y actividad reciente.
- Actualización automática por polling cada 15s desde `/app/dashboard/data/summary` con cache de 10s por comercio.
- Ajustá la frecuencia cambiando la variable `pollMs` en `DashboardController` (se envía al JS del template `templates/dashboard/index.html.twig`).
