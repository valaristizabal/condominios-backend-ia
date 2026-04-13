<?php

namespace App\Modules\Providers\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Providers\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'active' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(['all', 'active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $query = Supplier::query()
            ->where('condominium_id', $activeCondominiumId)
            ->orderBy('name');

        if (array_key_exists('active', $validated)) {
            $query->where('is_active', (int) $validated['active'] === 1);
            return response()->json(
                $query->get()->map(fn (Supplier $supplier) => $this->present($supplier))
            );
        }

        $hasPaginationOrFilters = $request->query->has('page')
            || $request->query->has('per_page')
            || $request->query->has('q')
            || $request->query->has('status');

        if (! $hasPaginationOrFilters) {
            return response()->json(
                $query->get()->map(fn (Supplier $supplier) => $this->present($supplier))
            );
        }

        $status = (string) ($validated['status'] ?? 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if (! empty($validated['q'])) {
            $search = trim((string) $validated['q']);
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', '%' . $search . '%')
                    ->orWhere('contact_name', 'like', '%' . $search . '%');
            });
        }

        $suppliers = $query->paginate(
            (int) ($validated['per_page'] ?? 12),
            ['*'],
            'page',
            (int) ($validated['page'] ?? 1),
        );

        $suppliers->setCollection(
            $suppliers->getCollection()->map(fn (Supplier $supplier) => $this->present($supplier))
        );

        return response()->json($suppliers);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('suppliers', 'name')
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId)),
            ],
            'rut' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'certificacion_bancaria' => ['nullable', 'string', 'max:2048'],
            'certificacion_bancaria_file' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
            'documento_representante_legal' => ['nullable', 'string', 'max:2048'],
            'documento_representante_legal_file' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $supplier = Supplier::query()->create([
            'condominium_id' => $activeCondominiumId,
            'name' => trim((string) $validated['name']),
            'rut' => $this->nullableTrim($validated['rut'] ?? null),
            'contact_name' => $validated['contact_name'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'certificacion_bancaria' => $this->resolveUploadedDocument(
                $request,
                'certificacion_bancaria_file',
                'certificacion_bancaria',
                $activeCondominiumId
            ),
            'documento_representante_legal' => $this->resolveUploadedDocument(
                $request,
                'documento_representante_legal_file',
                'documento_representante_legal',
                $activeCondominiumId
            ),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json($this->present($supplier->fresh()), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $supplier = $this->resolveSupplierInActiveCondominium($id, $activeCondominiumId);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('suppliers', 'name')
                    ->ignore($supplier->id)
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId)),
            ],
            'rut' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'certificacion_bancaria' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'certificacion_bancaria_file' => ['sometimes', 'nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
            'documento_representante_legal' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'documento_representante_legal_file' => ['sometimes', 'nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim((string) $validated['name']);
        }
        if (array_key_exists('rut', $validated)) {
            $validated['rut'] = $this->nullableTrim($validated['rut']);
        }

        if ($request->hasFile('certificacion_bancaria_file') || array_key_exists('certificacion_bancaria', $validated)) {
            $validated['certificacion_bancaria'] = $this->resolveUploadedDocument(
                $request,
                'certificacion_bancaria_file',
                'certificacion_bancaria',
                $activeCondominiumId,
                $supplier->certificacion_bancaria
            );
        }

        if ($request->hasFile('documento_representante_legal_file') || array_key_exists('documento_representante_legal', $validated)) {
            $validated['documento_representante_legal'] = $this->resolveUploadedDocument(
                $request,
                'documento_representante_legal_file',
                'documento_representante_legal',
                $activeCondominiumId,
                $supplier->documento_representante_legal
            );
        }

        $supplier->update($validated);

        return response()->json($this->present($supplier->fresh()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $supplier = $this->resolveSupplierInActiveCondominium($id, $activeCondominiumId);
        $supplier->is_active = false;
        $supplier->save();

        return response()->json([
            'message' => 'Proveedor desactivado.',
            'data' => $this->present($supplier->fresh()),
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $file = $validated['file'];
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => ['No fue posible leer el archivo CSV cargado.'],
            ]);
        }

        [$total, $created, $failed, $errors] = $this->importCsvFile($file, $activeCondominiumId);

        return response()->json([
            'total' => $total,
            'created' => $created,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function resolveUploadedDocument(
        Request $request,
        string $fileField,
        string $pathField,
        int $activeCondominiumId,
        ?string $currentPath = null
    ): ?string {
        if ($request->hasFile($fileField)) {
            if ($currentPath && ! Str::startsWith($currentPath, ['http://', 'https://'])) {
                Storage::disk('public')->delete($currentPath);
            }

            return $request->file($fileField)->store(
                sprintf('condominiums/%d/suppliers', $activeCondominiumId),
                'public'
            );
        }

        if ($request->exists($pathField)) {
            return $this->nullableTrim($request->input($pathField));
        }

        return $currentPath;
    }

    private function present(Supplier $supplier): array
    {
        $data = $supplier->toArray();
        $data['certificacion_bancaria_url'] = $this->resolvePublicStorageUrl($supplier->certificacion_bancaria);
        $data['documento_representante_legal_url'] = $this->resolvePublicStorageUrl($supplier->documento_representante_legal);

        return $data;
    }

    private function resolvePublicStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }

    private function activeCondominium(Request $request): int
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

    private function resolveSupplierInActiveCondominium(int $supplierId, int $activeCondominiumId): Supplier
    {
        $supplier = Supplier::query()
            ->where('id', $supplierId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $supplier) {
            throw ValidationException::withMessages([
                'supplier_id' => ['El proveedor no pertenece al condominio activo.'],
            ]);
        }

        return $supplier;
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
            if (! $this->hasRequiredCsvColumns($normalizedHeader)) {
                throw ValidationException::withMessages([
                    'file' => ['El archivo debe incluir las columnas: nombre_proveedor, rut, contacto, telefono, email, direccion, activo.'],
                ]);
            }

            $columnIndex = array_flip($normalizedHeader);
            $existingRuts = Supplier::query()
                ->where('condominium_id', $activeCondominiumId)
                ->whereNotNull('rut')
                ->pluck('rut')
                ->map(fn ($rut) => mb_strtolower(trim((string) $rut)))
                ->filter()
                ->all();
            $existingRutsLookup = array_fill_keys($existingRuts, true);
            $fileRutsLookup = [];

            $total = 0;
            $created = 0;
            $failed = 0;
            $errors = [];
            $rowNumber = 1;

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;

                if (! is_array($row) || $this->isCsvRowEmpty($row)) {
                    continue;
                }

                $total++;

                $name = $this->csvValue($row, $columnIndex, 'nombre_proveedor');
                $rut = $this->csvValue($row, $columnIndex, 'rut');
                $contactName = $this->csvValue($row, $columnIndex, 'contacto');
                $phone = $this->csvValue($row, $columnIndex, 'telefono');
                $email = $this->csvValue($row, $columnIndex, 'email');
                $address = $this->csvValue($row, $columnIndex, 'direccion');
                $active = $this->csvValue($row, $columnIndex, 'activo');

                if ($name === '' || $rut === '' || $active === '') {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: nombre_proveedor, rut y activo son obligatorios.";
                    continue;
                }

                if ($active !== '0' && $active !== '1') {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: activo solo admite valores 1 o 0.";
                    continue;
                }

                if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: email no tiene un formato valido.";
                    continue;
                }

                $normalizedRut = mb_strtolower(trim($rut));
                if (isset($existingRutsLookup[$normalizedRut])) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: el rut '{$rut}' ya existe en la base de datos.";
                    continue;
                }

                if (isset($fileRutsLookup[$normalizedRut])) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: el rut '{$rut}' esta repetido dentro del archivo.";
                    continue;
                }

                $validator = validator([
                    'name' => $name,
                    'rut' => $rut,
                    'contact_name' => $contactName !== '' ? $contactName : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'email' => $email !== '' ? $email : null,
                    'address' => $address !== '' ? $address : null,
                    'is_active' => $active === '1',
                ], [
                    'name' => ['required', 'string', 'max:255'],
                    'rut' => ['required', 'string', 'max:100'],
                    'contact_name' => ['nullable', 'string', 'max:255'],
                    'phone' => ['nullable', 'string', 'max:50'],
                    'email' => ['nullable', 'email', 'max:255'],
                    'address' => ['nullable', 'string', 'max:255'],
                    'is_active' => ['required', 'boolean'],
                ]);

                if ($validator->fails()) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: " . $validator->errors()->first();
                    continue;
                }

                Supplier::query()->create([
                    'condominium_id' => $activeCondominiumId,
                    'name' => trim($name),
                    'rut' => trim($rut),
                    'contact_name' => $contactName !== '' ? $contactName : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'email' => $email !== '' ? $email : null,
                    'address' => $address !== '' ? $address : null,
                    'is_active' => $active === '1',
                ]);

                $fileRutsLookup[$normalizedRut] = true;
                $existingRutsLookup[$normalizedRut] = true;
                $created++;
            }

            return [$total, $created, $failed, $errors];
        } finally {
            fclose($handle);
        }
    }

    private function hasRequiredCsvColumns(array $header): bool
    {
        $required = ['nombre_proveedor', 'rut', 'contacto', 'telefono', 'email', 'direccion', 'activo'];

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





