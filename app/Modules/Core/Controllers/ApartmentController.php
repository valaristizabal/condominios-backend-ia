<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Apartment;
use App\Modules\Core\Models\UnitType;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'q' => ['nullable', 'string', 'max:100'],
            'tower' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $apartments = Apartment::query()
            ->with([
                'unitType:id,name,allows_residents,requires_parent',
                'parent:id,unit_type_id,tower,number,floor',
                'parent.unitType:id,name,allows_residents,requires_parent',
                'children:id,parent_id,unit_type_id,tower,number,floor,is_active',
                'children.unitType:id,name,allows_residents,requires_parent',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->when(
                ! empty($validated['q']),
                function ($query) use ($validated) {
                    $q = trim((string) $validated['q']);
                    $query->where(function ($subQuery) use ($q) {
                        $subQuery->where('number', 'like', '%' . $q . '%')
                            ->orWhere('tower', 'like', '%' . $q . '%');
                    });
                }
            )
            ->when(
                ! empty($validated['tower']) && trim((string) $validated['tower']) !== 'all',
                fn ($query) => $query->where('tower', trim((string) $validated['tower']))
            )
            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where('is_active', (bool) $validated['is_active'])
            )
            ->orderBy('tower')
            ->orderBy('number')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json($apartments);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'unit_type_id' => ['required', 'integer', 'exists:unit_types,id'],
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('apartments', 'number')->where(
                    fn ($q) => $q
                        ->where('condominium_id', $activeCondominiumId)
                        ->where('tower', $request->input('tower'))
                ),
            ],
            'tower' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer', 'exists:apartments,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $unitType = $this->resolveUnitTypeInActiveCondominium((int) $validated['unit_type_id'], $activeCondominiumId);
        $parent = $this->resolveParentApartmentForUnit(
            $validated['parent_id'] ?? null,
            $unitType,
            $activeCondominiumId
        );

        try {
            $apartment = Apartment::query()->create([
                'condominium_id' => $activeCondominiumId,
                'unit_type_id' => $validated['unit_type_id'],
                'parent_id' => $parent?->id,
                'number' => $validated['number'],
                'tower' => $validated['tower'] ?? null,
                'floor' => $validated['floor'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe ese numero de apartamento en la torre indicada del condominio activo.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($apartment->fresh()->load([
            'unitType:id,name,allows_residents,requires_parent',
            'parent:id,unit_type_id,tower,number,floor',
            'parent.unitType:id,name,allows_residents,requires_parent',
            'children:id,parent_id,unit_type_id,tower,number,floor,is_active',
            'children.unitType:id,name,allows_residents,requires_parent',
        ]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $apartment = Apartment::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'unit_type_id' => ['sometimes', 'required', 'integer', 'exists:unit_types,id'],
            'number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('apartments', 'number')
                    ->where(fn ($q) => $q
                        ->where('condominium_id', $activeCondominiumId)
                        ->where('tower', $request->input('tower', $apartment->tower)))
                    ->ignore($apartment->id),
            ],
            'tower' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer', 'exists:apartments,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $unitType = null;
        if (isset($validated['unit_type_id'])) {
            $unitType = $this->resolveUnitTypeInActiveCondominium((int) $validated['unit_type_id'], $activeCondominiumId);
        }

        $resolvedUnitType = $unitType ?? $apartment->unitType;
        $parent = $this->resolveParentApartmentForUnit(
            $validated['parent_id'] ?? $apartment->parent_id,
            $resolvedUnitType,
            $activeCondominiumId,
            $apartment->id
        );

        $validated['parent_id'] = $parent?->id;

        try {
            $apartment->update($validated);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe ese numero de apartamento en la torre indicada del condominio activo.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($apartment->fresh()->load([
            'unitType:id,name,allows_residents,requires_parent',
            'parent:id,unit_type_id,tower,number,floor',
            'parent.unitType:id,name,allows_residents,requires_parent',
            'children:id,parent_id,unit_type_id,tower,number,floor,is_active',
            'children.unitType:id,name,allows_residents,requires_parent',
        ]));
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $apartment = Apartment::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $apartment->is_active = ! $apartment->is_active;
        $apartment->save();

        return response()->json([
            'message' => $apartment->is_active ? 'Apartamento activado.' : 'Apartamento desactivado.',
            'data' => $apartment,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $file = $validated['file'];
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => ['No fue posible leer el archivo cargado.'],
            ]);
        }

        [$created, $updated, $errors] = $this->importCsvFile($file, $activeCondominiumId);

        return response()->json([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    private function resolveActiveCondominiumId(Request $request): int
    {
        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

        if ($activeCondominiumId <= 0) {
            throw ValidationException::withMessages([
                'condominium' => ['No hay condominio activo resuelto para esta operacion.'],
            ]);
        }

        return $activeCondominiumId;
    }

    private function rejectCondominiumIdFromRequest(Request $request): void
    {
        if ($request->query->has('condominium_id') || $request->request->has('condominium_id')) {
            throw ValidationException::withMessages([
                'condominium_id' => ['No se permite enviar condominium_id en este endpoint.'],
            ]);
        }
    }

    private function resolveUnitTypeInActiveCondominium(int $unitTypeId, int $activeCondominiumId): UnitType
    {
        $unitType = UnitType::query()
            ->where('id', $unitTypeId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $unitType) {
            throw ValidationException::withMessages([
                'unit_type_id' => ['El tipo de unidad no pertenece al condominio activo.'],
            ]);
        }

        return $unitType;
    }

    private function resolveParentApartmentForUnit(
        mixed $parentId,
        ?UnitType $unitType,
        int $activeCondominiumId,
        ?int $currentApartmentId = null
    ): ?Apartment {
        $normalizedParentId = $parentId !== null && $parentId !== '' ? (int) $parentId : null;

        if (! $unitType?->needsParentApartment()) {
            if ($normalizedParentId !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Este tipo de unidad no debe depender de otro inmueble.'],
                ]);
            }

            return null;
        }

        if (! $normalizedParentId) {
            throw ValidationException::withMessages([
                'parent_id' => ['Este tipo de unidad debe estar asociado a un inmueble principal.'],
            ]);
        }

        if ($currentApartmentId !== null && $normalizedParentId === $currentApartmentId) {
            throw ValidationException::withMessages([
                'parent_id' => ['Un inmueble no puede ser padre de si mismo.'],
            ]);
        }

        $parent = Apartment::query()
            ->with('unitType:id,name,allows_residents,requires_parent')
            ->where('id', $normalizedParentId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_id' => ['El inmueble principal no pertenece al condominio activo.'],
            ]);
        }

        if (! $parent->unitType?->canHaveResidents()) {
            throw ValidationException::withMessages([
                'parent_id' => ['La unidad principal debe permitir residentes directos.'],
            ]);
        }

        return $parent;
    }

    private function importCsvFile(UploadedFile $file, int $activeCondominiumId): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if (! $handle) {
            throw ValidationException::withMessages([
                'file' => ['No fue posible abrir el archivo CSV.'],
            ]);
        }

        try {
            $this->skipUtf8Bom($handle);

            $delimiter = $this->detectCsvDelimiter($handle);
            $header = fgetcsv($handle, 0, $delimiter);
            if (! is_array($header)) {
                throw ValidationException::withMessages([
                    'file' => ['El archivo CSV esta vacio o no tiene encabezados validos.'],
                ]);
            }

            $normalizedHeader = array_map([$this, 'normalizeCsvHeader'], $header);
            Log::info('CSV apartment import headers detected', [
                'delimiter' => $delimiter,
                'raw_headers' => $header,
                'normalized_headers' => $normalizedHeader,
            ]);

            if (! $this->hasRequiredCsvColumns($normalizedHeader)) {
                throw ValidationException::withMessages([
                    'file' => ['El archivo debe incluir las columnas: torre, numero, tipo_unidad, piso.'],
                ]);
            }

            $columnIndex = array_flip($normalizedHeader);
            $unitTypesByName = UnitType::query()
                ->where('condominium_id', $activeCondominiumId)
                ->get(['id', 'name'])
                ->mapWithKeys(fn (UnitType $item) => [mb_strtolower(trim($item->name)) => $item])
                ->all();

            $existingApartments = Apartment::query()
                ->where('condominium_id', $activeCondominiumId)
                ->get(['id', 'tower', 'number', 'parent_id']);
            $existingLookup = $existingApartments
                ->mapWithKeys(fn (Apartment $item) => [$this->buildApartmentDuplicateKey($item->tower, $item->number) => $item])
                ->all();

            $created = 0;
            $updated = 0;
            $errors = [];
            $rowNumber = 1;

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;

                if (! is_array($row) || $this->isCsvRowEmpty($row)) {
                    continue;
                }

                $tower = $this->csvValue($row, $columnIndex, 'torre');
                $number = $this->csvValue($row, $columnIndex, 'numero');
                $unitTypeName = $this->csvValue($row, $columnIndex, 'tipo_unidad');
                $floorText = $this->csvValue($row, $columnIndex, 'piso');

                if ($tower === '' || $number === '' || $unitTypeName === '' || $floorText === '') {
                    $errors[] = "Fila {$rowNumber}: todas las columnas obligatorias deben tener valor.";
                    continue;
                }

                if (mb_strlen($tower) > 50 || mb_strlen($number) > 50) {
                    $errors[] = "Fila {$rowNumber}: torre y numero no pueden superar 50 caracteres.";
                    continue;
                }

                if (filter_var($floorText, FILTER_VALIDATE_INT) === false) {
                    $errors[] = "Fila {$rowNumber}: piso debe ser un numero entero.";
                    continue;
                }

                $unitType = $unitTypesByName[mb_strtolower($unitTypeName)] ?? null;
                if (! $unitType) {
                    $errors[] = "Fila {$rowNumber}: tipo_unidad '{$unitTypeName}' no existe.";
                    continue;
                }

                $duplicateKey = $this->buildApartmentDuplicateKey($tower, $number);
                $existingApartment = $existingLookup[$duplicateKey] ?? null;

                if ($existingApartment instanceof Apartment && $existingApartment->parent_id !== null) {
                    $errors[] = "Fila {$rowNumber}: no se actualiza el inmueble '{$number}' porque es una unidad hija.";
                    continue;
                }

                if ($existingApartment instanceof Apartment) {
                    $existingApartment->update([
                        'unit_type_id' => $unitType->id,
                        'floor' => (int) $floorText,
                        'is_active' => true,
                    ]);
                    $updated++;
                    continue;
                }

                $newApartment = Apartment::query()->create([
                    'condominium_id' => $activeCondominiumId,
                    'unit_type_id' => $unitType->id,
                    'tower' => $tower,
                    'number' => $number,
                    'floor' => (int) $floorText,
                    'is_active' => true,
                ]);
                $existingLookup[$duplicateKey] = $newApartment;
                $created++;
            }

            return [$created, $updated, $errors];
        } finally {
            fclose($handle);
        }
    }

    private function hasRequiredCsvColumns(array $header): bool
    {
        $required = ['torre', 'numero', 'tipo_unidad', 'piso'];

        foreach ($required as $column) {
            if (! in_array($column, $header, true)) {
                return false;
            }
        }

        return true;
    }

    private function csvValue(array $row, array $columnIndex, string $column): string
    {
        $index = $columnIndex[$column] ?? null;
        if ($index === null) {
            return '';
        }

        return $this->normalizeCsvCell($row[$index] ?? '');
    }

    private function normalizeCsvCell(mixed $value): string
    {
        $text = (string) $value;

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;

        return trim($text);
    }

    private function normalizeCsvHeader(mixed $value): string
    {
        $text = $this->normalizeCsvCell($value);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/u', '_', $text) ?? $text;

        return trim($text, '_');
    }

    private function isCsvRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizeCsvCell($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function buildApartmentDuplicateKey(?string $tower, string $number): string
    {
        return mb_strtolower(trim((string) $tower)) . '|' . mb_strtolower(trim($number));
    }

    private function skipUtf8Bom($handle): void
    {
        $bom = "\xEF\xBB\xBF";
        $firstBytes = fread($handle, 3);

        if ($firstBytes !== $bom) {
            rewind($handle);
        }
    }

    private function detectCsvDelimiter($handle): string
    {
        $position = ftell($handle);
        $sample = fgets($handle);

        if ($sample === false) {
            fseek($handle, $position);
            return ',';
        }

        $commaCount = substr_count($sample, ',');
        $semicolonCount = substr_count($sample, ';');

        fseek($handle, $position);

        return $semicolonCount > $commaCount ? ';' : ',';
    }
}




