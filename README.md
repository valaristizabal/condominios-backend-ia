# Condominios Backend IA

API Laravel para el SaaS multi-condominio de administracion operativa.

## Reglas clave

- Multi-tenant por `activeCondominiumId`.
- No se debe enviar `condominium_id` desde frontend.
- El tenant se resuelve con el middleware `resolve.active.condominium`.
- Los administradores de plataforma pueden operar sobre un tenant usando el header `X-Active-Condominium-Id`.

## Unit Types parametrizables

La logica de tipos de unidad ya no depende del nombre visible.

Cada `unit_type` define su comportamiento con dos flags:

- `allows_residents`
  - `true`: la unidad permite registrar residentes directos.
  - `false`: la unidad no permite registrar residentes directos.
- `requires_parent`
  - `true`: la unidad debe quedar asociada a un inmueble principal.
  - `false`: la unidad no necesita padre.

Defaults de base de datos:

- `allows_residents = false`
- `requires_parent = false`
- ambos campos son `NOT NULL`

Combinacion invalida:

- `allows_residents = true` y `requires_parent = true`

El backend bloquea esa combinacion en `UnitTypeController`.

## Reglas operativas

### Recaudo y cartera mensual

- Endpoint seguro de generacion:
  - `POST /api/portfolio/generate-current`
- No recibe `period` desde frontend.
- El backend calcula internamente el periodo actual con fecha del servidor (`now()->format('Y-m')`).
- Si ya existe cartera para el condominio en el mes actual:
  - no crea nuevos registros
  - responde:
    - `total_creados = 0`
    - `total_omitidos`
    - `message = "La cartera del mes actual ya fue generada"`
- Si no existe cartera del mes actual:
  - genera por residentes activos con `administration_fee` y `administration_due_day`
  - evita duplicados por `apartment_id + period`
  - responde `period`, `total_creados`, `total_omitidos` y mensaje de confirmacion
- Evidencias de recaudo:
  - los archivos se guardan en disco `public` (ej: `portfolio-collections/...`)
  - la API responde `evidence_url` publico usando `asset('storage/' . evidence_path)`
  - no se exponen rutas internas tipo `storage/app/public/...`
  - requiere enlace de storage (`php artisan storage:link`)

### Residentes

- Un residente solo puede registrarse sobre un inmueble cuyo `unit_type.allows_residents = true`.
- Esta validacion se ejecuta en `ResidentController`.
- No se confia en el frontend para esta regla.

#### Campos extendidos de residentes

Se agregaron campos de negocio en `residents`:

- `administration_fee` (nullable, numerico)
- `administration_due_day` (nullable, integer de 1 a 31)
- `property_owner_full_name` (nullable)
- `property_owner_document_number` (nullable)
- `property_owner_email` (nullable)
- `property_owner_phone` (nullable)
- `property_owner_birth_date` (nullable, date)

Reglas en `store/update`:

- Si `type = arrendatario`:
  - `property_owner_full_name` requerido
  - `property_owner_document_number` requerido
  - `property_owner_email` requerido y valido
- Si `type = propietario`:
  - los campos `property_owner_*` se limpian a `null`.

#### Importacion CSV de residentes

Se mantiene compatibilidad con archivos CSV actuales.

Columnas nuevas opcionales soportadas:

- `administration_fee`
- `administration_due_day`
- `property_owner_full_name`
- `property_owner_document_number`
- `property_owner_email`
- `property_owner_phone`
- `property_owner_birth_date`

Reglas de importacion:

- Si la fila es `arrendatario`, se exige como minimo `property_owner_full_name`.
- Si la fila es `propietario`, las columnas `property_owner_*` se ignoran y se guardan en `null`.

### Inmuebles

- Si `unit_type.requires_parent = true`, el inmueble debe enviarse con `parent_id`.
- Si `unit_type.requires_parent = false`, no debe enviarse `parent_id`.
- El inmueble padre debe pertenecer al condominio activo.
- El inmueble padre debe ser de un tipo que permita residentes directos.
- Estas validaciones se ejecutan en `ApartmentController`.

## Ejemplo de configuracion

- `Apartamento`
  - `allows_residents = true`
  - `requires_parent = false`
- `Parqueadero`
  - `allows_residents = false`
  - `requires_parent = true`
- `Deposito`
  - `allows_residents = false`
  - `requires_parent = true`

Flujo:

1. Crear el tipo de unidad.
2. Crear el inmueble principal.
3. Crear inmuebles anexos si el tipo requiere padre.
4. Registrar residentes solo sobre unidades que permitan residentes.

## Seguridad de cambios en tipos de unidad

No se permiten cambios que dejen datos inconsistentes. Por ejemplo:

- desactivar `allows_residents` si ya hay residentes asociados
- activar `requires_parent` si ya existen unidades principales de ese tipo
- quitar `requires_parent` si ya existen unidades hijas usando ese tipo

## Tests agregados

Se agrego cobertura para:

- impedir residentes en unidades con `allows_residents = false`
- obligar `parent_id` cuando `requires_parent = true`
- bloquear actualizaciones inconsistentes de `unit_types`

Archivo:

- `tests/Feature/UnitTypeBehaviorTest.php`
