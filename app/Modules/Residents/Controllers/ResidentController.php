<?php

namespace App\Modules\Residents\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Apartment;
use App\Modules\Residents\Models\Resident;
use App\Modules\Security\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResidentController extends Controller
{
    public function debtSummary(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $chargesSubquery = DB::table('portfolio_charges')
            ->selectRaw('condominium_id, apartment_id, SUM(amount_total) as total_charges')
            ->where('condominium_id', $activeCondominiumId)
            ->groupBy('condominium_id', 'apartment_id');

        $paymentsSubquery = DB::table('portfolio_collections')
            ->selectRaw('condominium_id, apartment_id, SUM(amount) as total_payments')
            ->where('condominium_id', $activeCondominiumId)
            ->groupBy('condominium_id', 'apartment_id');

        $rows = Resident::query()
            ->join('users', 'users.id', '=', 'residents.user_id')
            ->join('apartments', 'apartments.id', '=', 'residents.apartment_id')
            ->leftJoinSub($chargesSubquery, 'charges', function ($join) {
                $join->on('charges.apartment_id', '=', 'residents.apartment_id')
                    ->on('charges.condominium_id', '=', 'apartments.condominium_id');
            })
            ->leftJoinSub($paymentsSubquery, 'payments', function ($join) {
                $join->on('payments.apartment_id', '=', 'residents.apartment_id')
                    ->on('payments.condominium_id', '=', 'apartments.condominium_id');
            })
            ->where('apartments.condominium_id', $activeCondominiumId)
            ->selectRaw('
                residents.id as resident_id,
                users.full_name as name,
                apartments.tower as apartment_tower,
                apartments.number as apartment_number,
                COALESCE(charges.total_charges, 0) as total_charges,
                COALESCE(payments.total_payments, 0) as total_payments
            ')
            ->orderBy('users.full_name')
            ->orderBy('residents.id')
            ->get();

        $payload = $rows->map(function ($row) {
            $totalCharges = round((float) $row->total_charges, 2);
            $totalPayments = round((float) $row->total_payments, 2);
            $debt = round($totalCharges - $totalPayments, 2);

            return [
                'resident_id' => (int) $row->resident_id,
                'name' => (string) $row->name,
                'apartment' => $this->formatApartmentDebtLabel(
                    $row->apartment_tower,
                    $row->apartment_number
                ),
                'total_charges' => $totalCharges,
                'total_payments' => $totalPayments,
                'debt' => $debt,
                'status' => $debt > 0 ? 'en_mora' : 'al_dia',
            ];
        })->values();

        return response()->json($payload);
    }

    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'q' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'type' => ['nullable', Rule::in(['propietario', 'arrendatario'])],
            'unit_type_name' => ['nullable', 'string', 'max:100'],
        ]);

        $residents = Resident::query()
            ->with([
                'user',
                'apartment.unitType:id,name,allows_residents,requires_parent',
                'apartment.children:id,parent_id,unit_type_id,tower,number,floor,is_active',
                'apartment.children.unitType:id,name,allows_residents,requires_parent',
            ])
            ->whereHas('apartment', fn ($q) => $q->where('condominium_id', $activeCondominiumId))
            ->when(
                ! empty($validated['q']),
                function ($query) use ($validated) {
                    $search = trim((string) $validated['q']);
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery->whereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('full_name', 'like', '%' . $search . '%')
                                ->orWhere('document_number', 'like', '%' . $search . '%');
                        })->orWhereHas('apartment', function ($apartmentQuery) use ($search) {
                            $apartmentQuery->where('number', 'like', '%' . $search . '%')
                                ->orWhere('tower', 'like', '%' . $search . '%');
                        });
                    });
                }
            )
            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where('is_active', (bool) $validated['is_active'])
            )
            ->when(
                ! empty($validated['type']),
                fn ($query) => $query->where('type', $validated['type'])
            )
            ->when(
                ! empty($validated['unit_type_name']),
                fn ($query) => $query->whereHas(
                    'apartment.unitType',
                    fn ($unitTypeQuery) => $unitTypeQuery->where('name', trim((string) $validated['unit_type_name']))
                )
            )
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json($residents);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'document_number' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'apartment_id' => ['required', 'integer', 'exists:apartments,id'],
            'type' => ['required', Rule::in(['propietario', 'arrendatario'])],
            'administration_fee' => ['nullable', 'numeric'],
            'administration_due_day' => ['nullable', 'integer', 'between:1,31'],
            'property_owner_full_name' => ['nullable', 'string', 'max:255', 'required_if:type,arrendatario'],
            'property_owner_document_number' => ['nullable', 'string', 'max:50', 'required_if:type,arrendatario'],
            'property_owner_email' => ['nullable', 'email', 'max:255', 'required_if:type,arrendatario'],
            'property_owner_phone' => ['nullable', 'string', 'max:30'],
            'property_owner_birth_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $validated = $this->normalizeResidentExtendedFields($validated, (string) $validated['type']);

        $apartment = $this->resolvePrimaryApartmentInActiveCondominium(
            (int) $validated['apartment_id'],
            $activeCondominiumId
        );
        $resolvedIsActive = (bool) ($validated['is_active'] ?? true);
        if ((string) $validated['type'] === 'propietario' && $resolvedIsActive) {
            $this->ensureSingleActiveOwnerForApartment(
                apartmentId: $apartment->id,
                activeCondominiumId: $activeCondominiumId
            );
        }

        try {
            $resident = DB::transaction(function () use ($validated, $apartment) {
                $user = $this->resolveOrCreateUser($validated);

                $newResident = Resident::query()->create([
                    'user_id' => $user->id,
                    'apartment_id' => $apartment->id,
                    'type' => $validated['type'],
                    'administration_fee' => $validated['administration_fee'] ?? null,
                    'administration_due_day' => $validated['administration_due_day'] ?? null,
                    'property_owner_full_name' => $validated['property_owner_full_name'] ?? null,
                    'property_owner_document_number' => $validated['property_owner_document_number'] ?? null,
                    'property_owner_email' => $validated['property_owner_email'] ?? null,
                    'property_owner_phone' => $validated['property_owner_phone'] ?? null,
                    'property_owner_birth_date' => $validated['property_owner_birth_date'] ?? null,
                    'is_active' => $validated['is_active'] ?? true,
                ]);

                return $newResident;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe un residente para ese usuario en el apartamento indicado.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($resident->fresh()->load([
            'user',
            'apartment.unitType:id,name,allows_residents,requires_parent',
            'apartment.children:id,parent_id,unit_type_id,tower,number,floor,is_active',
            'apartment.children.unitType:id,name,allows_residents,requires_parent',
        ]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $resident = Resident::query()
            ->with(['user', 'apartment.unitType:id,name,allows_residents,requires_parent'])
            ->whereHas('apartment', fn ($q) => $q->where('condominium_id', $activeCondominiumId))
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($resident->user_id),
            ],
            'document_number' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('users', 'document_number')->ignore($resident->user_id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'apartment_id' => ['sometimes', 'integer', 'exists:apartments,id'],
            'type' => ['sometimes', Rule::in(['propietario', 'arrendatario'])],
            'administration_fee' => ['sometimes', 'nullable', 'numeric'],
            'administration_due_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'property_owner_full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'property_owner_document_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'property_owner_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'property_owner_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'property_owner_birth_date' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $resolvedType = (string) ($validated['type'] ?? $resident->type);
        $validated = $this->normalizeResidentExtendedFields($validated, $resolvedType);
        $this->ensureRequiredOwnerFieldsForArrendatario($validated, $resident, $resolvedType);

        $targetApartmentId = (int) ($validated['apartment_id'] ?? $resident->apartment_id);
        if (isset($validated['apartment_id'])) {
            $targetApartment = $this->resolvePrimaryApartmentInActiveCondominium(
                (int) $validated['apartment_id'],
                $activeCondominiumId
            );
            $targetApartmentId = (int) $targetApartment->id;
        }
        $resolvedIsActive = (bool) ($validated['is_active'] ?? $resident->is_active);
        if ($resolvedType === 'propietario' && $resolvedIsActive) {
            $this->ensureSingleActiveOwnerForApartment(
                apartmentId: $targetApartmentId,
                activeCondominiumId: $activeCondominiumId,
                excludeResidentId: $resident->id
            );
        }

        try {
            DB::transaction(function () use ($resident, $validated) {
                $userData = collect($validated)->only([
                    'full_name',
                    'email',
                    'document_number',
                    'phone',
                    'birth_date',
                ])->all();

                if (! empty($userData)) {
                    $resident->user()->update($userData);
                }

                $residentData = collect($validated)->only([
                    'apartment_id',
                    'type',
                    'administration_fee',
                    'administration_due_day',
                    'property_owner_full_name',
                    'property_owner_document_number',
                    'property_owner_email',
                    'property_owner_phone',
                    'property_owner_birth_date',
                    'is_active',
                ])->all();

                if (! empty($residentData)) {
                    $resident->update($residentData);
                }
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe un residente para ese usuario en el apartamento indicado.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($resident->fresh()->load([
            'user',
            'apartment.unitType:id,name,allows_residents,requires_parent',
            'apartment.children:id,parent_id,unit_type_id,tower,number,floor,is_active',
            'apartment.children.unitType:id,name,allows_residents,requires_parent',
        ]));
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
                'file' => ['No fue posible leer el archivo CSV cargado.'],
            ]);
        }

        [$created, $updated, $failed, $errors] = $this->importCsvFile($file, $activeCondominiumId);

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
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

    private function resolvePrimaryApartmentInActiveCondominium(int $apartmentId, int $activeCondominiumId): Apartment
    {
        $apartment = Apartment::query()
            ->with('unitType:id,name,allows_residents,requires_parent')
            ->where('id', $apartmentId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $apartment) {
            throw ValidationException::withMessages([
                'apartment_id' => ['El apartamento no pertenece al condominio activo.'],
            ]);
        }

        if (! $apartment->isPrimaryApartment()) {
            throw ValidationException::withMessages([
                'apartment_id' => ['Solo se pueden registrar residentes en inmuebles cuyo tipo permita residentes directos.'],
            ]);
        }

        return $apartment;
    }

    private function ensureSingleActiveOwnerForApartment(
        int $apartmentId,
        int $activeCondominiumId,
        ?int $excludeResidentId = null
    ): void {
        $alreadyHasActiveOwner = Resident::query()
            ->where('apartment_id', $apartmentId)
            ->where('type', 'propietario')
            ->where('is_active', true)
            ->whereHas('apartment', fn ($query) => $query->where('condominium_id', $activeCondominiumId))
            ->when(
                $excludeResidentId !== null,
                fn ($query) => $query->where('id', '!=', $excludeResidentId)
            )
            ->exists();

        if ($alreadyHasActiveOwner) {
            throw ValidationException::withMessages([
                'type' => ['Este inmueble ya tiene un propietario registrado'],
            ]);
        }
    }

    private function resolveOrCreateUser(array $validated): User
    {
        $email = $validated['email'];
        $documentNumber = $validated['document_number'];

        $userByEmail = User::query()->where('email', $email)->first();
        $userByDocument = User::query()->where('document_number', $documentNumber)->first();

        if ($userByEmail && $userByDocument && $userByEmail->id !== $userByDocument->id) {
            throw ValidationException::withMessages([
                'email' => ['El email pertenece a un usuario diferente al del documento enviado.'],
                'document_number' => ['El documento pertenece a un usuario diferente al del email enviado.'],
            ]);
        }

        $existingUser = $userByEmail ?: $userByDocument;

        if ($existingUser) {
            $existingUser->update([
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'] ?? $existingUser->phone,
                'birth_date' => $validated['birth_date'] ?? $existingUser->birth_date,
            ]);

            return $existingUser;
        }

        return User::query()->create([
            'full_name' => $validated['full_name'],
            'email' => $email,
            'document_number' => $documentNumber,
            'phone' => $validated['phone'] ?? null,
            'birth_date' => $validated['birth_date'] ?? null,
            'password' => Str::random(24),
            'is_active' => true,
            'is_platform_admin' => false,
        ]);
    }

    private function normalizeResidentExtendedFields(array $validated, string $resolvedType): array
    {
        $ownerFields = [
            'property_owner_full_name',
            'property_owner_document_number',
            'property_owner_email',
            'property_owner_phone',
            'property_owner_birth_date',
        ];

        foreach (['property_owner_full_name', 'property_owner_document_number', 'property_owner_email', 'property_owner_phone'] as $field) {
            if (array_key_exists($field, $validated) && is_string($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        if (array_key_exists('property_owner_email', $validated) && is_string($validated['property_owner_email'])) {
            $validated['property_owner_email'] = Str::lower($validated['property_owner_email']);
        }

        foreach (['administration_fee', 'administration_due_day', ...$ownerFields] as $nullableField) {
            if (array_key_exists($nullableField, $validated) && $validated[$nullableField] === '') {
                $validated[$nullableField] = null;
            }
        }

        if ($resolvedType === 'propietario') {
            foreach ($ownerFields as $ownerField) {
                $validated[$ownerField] = null;
            }
        }

        return $validated;
    }

    private function ensureRequiredOwnerFieldsForArrendatario(
        array $validated,
        Resident $resident,
        string $resolvedType
    ): void {
        if ($resolvedType !== 'arrendatario') {
            return;
        }

        $requiredOwnerFields = [
            'property_owner_full_name' => 'El nombre del propietario es obligatorio para arrendatarios.',
            'property_owner_document_number' => 'El documento del propietario es obligatorio para arrendatarios.',
            'property_owner_email' => 'El email del propietario es obligatorio para arrendatarios.',
        ];

        foreach ($requiredOwnerFields as $field => $message) {
            $value = array_key_exists($field, $validated)
                ? $validated[$field]
                : $resident->{$field};

            if ($value === null || trim((string) $value) === '') {
                throw ValidationException::withMessages([
                    $field => [$message],
                ]);
            }
        }

        $ownerEmail = array_key_exists('property_owner_email', $validated)
            ? $validated['property_owner_email']
            : $resident->property_owner_email;

        if (! filter_var((string) $ownerEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'property_owner_email' => ['El email del propietario no tiene un formato valido.'],
            ]);
        }
    }

    private function importCsvFile(UploadedFile $file, int $activeCondominiumId): array
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if (! $handle) {
            throw ValidationException::withMessages([
                'file' => ['No fue posible abrir el archivo CSV.'],
            ]);
        }

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

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
            $requiredColumns = [
                'nombre_completo',
                'email',
                'documento',
                'celular',
                'fecha_nacimiento',
                'tipo_residente',
                'tipo_inmueble',
                'torre',
                'numero',
                'activo',
            ];
            // Columnas opcionales compatibles para nuevos datos de residentes:
            // - administration_fee
            // - administration_due_day
            // - property_owner_full_name
            // - property_owner_document_number
            // - property_owner_email
            // - property_owner_phone
            // - property_owner_birth_date

            foreach ($requiredColumns as $requiredColumn) {
                if (! in_array($requiredColumn, $normalizedHeader, true)) {
                    throw ValidationException::withMessages([
                        'file' => ['El archivo debe incluir todas las columnas requeridas para residentes.'],
                    ]);
                }
            }

            $columnIndex = array_flip($normalizedHeader);
            $rowNumber = 1;
            $seenDocuments = [];
            $seenEmails = [];

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;

                if (! is_array($row) || $this->isCsvRowEmpty($row)) {
                    continue;
                }

                try {
                    $payload = [
                        'full_name' => $this->csvValue($row, $columnIndex, 'nombre_completo'),
                        'email' => Str::lower($this->csvValue($row, $columnIndex, 'email')),
                        'document_number' => $this->csvValue($row, $columnIndex, 'documento'),
                        'phone' => $this->csvValue($row, $columnIndex, 'celular'),
                        'birth_date' => $this->normalizeCsvDate(
                            $this->csvValue($row, $columnIndex, 'fecha_nacimiento'),
                            'fecha_nacimiento'
                        ),
                        'type' => $this->normalizeResidentType($this->csvValue($row, $columnIndex, 'tipo_residente')),
                        'unit_type_name' => $this->csvValue($row, $columnIndex, 'tipo_inmueble'),
                        'tower' => $this->csvValue($row, $columnIndex, 'torre'),
                        'number' => $this->csvValue($row, $columnIndex, 'numero'),
                        'is_active' => $this->normalizeBooleanCsvValue($this->csvValue($row, $columnIndex, 'activo'), 'activo'),
                        'administration_fee' => $this->normalizeOptionalCsvNumeric(
                            $this->csvValue($row, $columnIndex, 'administration_fee'),
                            'administration_fee'
                        ),
                        'administration_due_day' => $this->normalizeOptionalCsvDueDay(
                            $this->csvValue($row, $columnIndex, 'administration_due_day'),
                            'administration_due_day'
                        ),
                        'property_owner_full_name' => $this->csvValue($row, $columnIndex, 'property_owner_full_name'),
                        'property_owner_document_number' => $this->csvValue($row, $columnIndex, 'property_owner_document_number'),
                        'property_owner_email' => Str::lower($this->csvValue($row, $columnIndex, 'property_owner_email')),
                        'property_owner_phone' => $this->csvValue($row, $columnIndex, 'property_owner_phone'),
                        'property_owner_birth_date' => $this->normalizeCsvDate(
                            $this->csvValue($row, $columnIndex, 'property_owner_birth_date'),
                            'property_owner_birth_date'
                        ),
                    ];
                } catch (ValidationException $exception) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: " . collect($exception->errors())->flatten()->first();
                    continue;
                }

                // Regla de negocio:
                // - arrendatario: requiere al menos nombre del propietario
                // - propietario: ignorar columnas property_owner_*
                if ($payload['type'] === 'arrendatario') {
                    if (mb_strlen(trim((string) $payload['property_owner_full_name'])) < 3) {
                        $failed++;
                        $errors[] = "Fila {$rowNumber}: property_owner_full_name es obligatorio para arrendatarios (minimo 3 caracteres).";
                        continue;
                    }
                } else {
                    $payload['property_owner_full_name'] = null;
                    $payload['property_owner_document_number'] = null;
                    $payload['property_owner_email'] = null;
                    $payload['property_owner_phone'] = null;
                    $payload['property_owner_birth_date'] = null;
                }

                if (isset($seenDocuments[$payload['document_number']])) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: el documento {$payload['document_number']} esta duplicado dentro del archivo.";
                    continue;
                }
                $seenDocuments[$payload['document_number']] = true;

                if (isset($seenEmails[$payload['email']])) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: el correo {$payload['email']} esta duplicado dentro del archivo.";
                    continue;
                }
                $seenEmails[$payload['email']] = true;

                $apartment = Apartment::query()
                    ->with('unitType:id,name,allows_residents,requires_parent')
                    ->where('condominium_id', $activeCondominiumId)
                    ->where('number', $payload['number'])
                    ->where(function ($query) use ($payload) {
                        $tower = trim((string) $payload['tower']);
                        if ($tower === '') {
                            $query->whereNull('tower')->orWhere('tower', '');
                            return;
                        }

                        $query->where('tower', $tower);
                    })
                    ->first();

                if (! $apartment) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: no existe un inmueble con torre '{$payload['tower']}' y numero '{$payload['number']}' en el condominio activo.";
                    continue;
                }

                if (! $apartment->isPrimaryApartment()) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: el inmueble '{$payload['number']}' no permite registrar residentes directos.";
                    continue;
                }

                $payloadUnitTypeName = $this->normalizeUnitTypeNameForComparison($payload['unit_type_name']);
                if ($payloadUnitTypeName !== '') {
                    $actualUnitTypeName = $this->normalizeUnitTypeNameForComparison((string) $apartment->unitType?->name);
                    if ($payloadUnitTypeName !== $actualUnitTypeName) {
                        $failed++;
                        $errors[] = "Fila {$rowNumber}: el tipo de inmueble no coincide con la unidad encontrada.";
                        continue;
                    }
                }

                $existingUserByDocument = User::query()
                    ->where('document_number', $payload['document_number'])
                    ->first();
                $existingUserByEmail = User::query()
                    ->where('email', $payload['email'])
                    ->first();

                if (
                    $existingUserByDocument &&
                    $existingUserByEmail &&
                    (int) $existingUserByDocument->id !== (int) $existingUserByEmail->id
                ) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: el documento y el correo pertenecen a usuarios diferentes.";
                    continue;
                }

                $existingUser = $existingUserByDocument ?: $existingUserByEmail;
                $validationRules = [
                    'full_name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'max:255'],
                    'document_number' => ['required', 'string', 'max:50'],
                    'phone' => ['nullable', 'string', 'max:30'],
                    'birth_date' => ['nullable', 'date'],
                    'type' => ['required', Rule::in(['propietario', 'arrendatario'])],
                    'is_active' => ['required', 'boolean'],
                    'administration_fee' => ['nullable', 'numeric'],
                    'administration_due_day' => ['nullable', 'integer', 'between:1,31'],
                    'property_owner_full_name' => ['nullable', 'string', 'max:255'],
                    'property_owner_document_number' => ['nullable', 'string', 'max:50'],
                    'property_owner_email' => ['nullable', 'email', 'max:255'],
                    'property_owner_phone' => ['nullable', 'string', 'max:30'],
                    'property_owner_birth_date' => ['nullable', 'date'],
                ];

                if ($existingUser) {
                    $validationRules['email'][] = Rule::unique('users', 'email')->ignore($existingUser->id);
                    $validationRules['document_number'][] = Rule::unique('users', 'document_number')->ignore($existingUser->id);
                } else {
                    $validationRules['email'][] = 'unique:users,email';
                    $validationRules['document_number'][] = 'unique:users,document_number';
                }

                $validator = validator($payload, $validationRules);

                if ($validator->fails()) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: " . $validator->errors()->first();
                    continue;
                }

                try {
                    $wasUpdated = false;

                    DB::transaction(function () use ($payload, $apartment, &$wasUpdated): void {
                        $user = $this->resolveOrCreateUser($payload);

                        $resident = Resident::query()
                            ->where('user_id', $user->id)
                            ->where('apartment_id', $apartment->id)
                            ->first();

                        if ($resident) {
                            $resident->update([
                                'type' => $payload['type'],
                                'administration_fee' => $payload['administration_fee'],
                                'administration_due_day' => $payload['administration_due_day'],
                                'property_owner_full_name' => $payload['property_owner_full_name'],
                                'property_owner_document_number' => $payload['property_owner_document_number'],
                                'property_owner_email' => $payload['property_owner_email'],
                                'property_owner_phone' => $payload['property_owner_phone'],
                                'property_owner_birth_date' => $payload['property_owner_birth_date'],
                                'is_active' => $payload['is_active'],
                            ]);
                            $wasUpdated = true;
                            return;
                        }

                        Resident::query()->create([
                            'user_id' => $user->id,
                            'apartment_id' => $apartment->id,
                            'type' => $payload['type'],
                            'administration_fee' => $payload['administration_fee'],
                            'administration_due_day' => $payload['administration_due_day'],
                            'property_owner_full_name' => $payload['property_owner_full_name'],
                            'property_owner_document_number' => $payload['property_owner_document_number'],
                            'property_owner_email' => $payload['property_owner_email'],
                            'property_owner_phone' => $payload['property_owner_phone'],
                            'property_owner_birth_date' => $payload['property_owner_birth_date'],
                            'is_active' => $payload['is_active'],
                        ]);
                    });

                    if ($wasUpdated) {
                        $updated++;
                    } else {
                        $created++;
                    }
                } catch (\Throwable) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: no fue posible crear o actualizar el residente.";
                }
            }
        } finally {
            fclose($handle);
        }

        return [$created, $updated, $failed, $errors];
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

    private function normalizeCsvHeader(mixed $value): string
    {
        $text = $this->normalizeCsvCell($value);
        $text = Str::of($text)->lower()->ascii()->value();
        $text = preg_replace('/\s+/u', '_', $text) ?? $text;

        return trim($text, '_');
    }

    private function normalizeCsvCell(mixed $value): string
    {
        $text = trim((string) $value);
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim($text);
    }

    private function csvValue(array $row, array $columnIndex, string $column): string
    {
        $index = $columnIndex[$column] ?? null;

        if ($index === null) {
            return '';
        }

        return $this->normalizeCsvCell($row[$index] ?? '');
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

    private function normalizeCsvDate(string $value, string $fieldName): ?string
    {
        $normalizedValue = trim($value);

        if ($normalizedValue === '') {
            return null;
        }

        foreach (['Y-m-d', 'j/n/Y', 'j/m/Y', 'd/n/Y', 'd/m/Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $normalizedValue);
            if ($date instanceof \DateTime) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($normalizedValue);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        throw ValidationException::withMessages([
            $fieldName => ["La fecha '{$normalizedValue}' no es valida. Formatos permitidos: YYYY-MM-DD o DD/MM/YYYY."],
        ]);
    }

    private function normalizeOptionalCsvNumeric(string $value, string $fieldName): ?float
    {
        $normalizedValue = trim($value);

        if ($normalizedValue === '') {
            return null;
        }

        if (! is_numeric($normalizedValue)) {
            throw ValidationException::withMessages([
                $fieldName => ["El campo '{$fieldName}' debe ser numerico."],
            ]);
        }

        return (float) $normalizedValue;
    }

    private function normalizeOptionalCsvDueDay(string $value, string $fieldName): ?int
    {
        $normalizedValue = trim($value);

        if ($normalizedValue === '') {
            return null;
        }

        if (! ctype_digit($normalizedValue)) {
            throw ValidationException::withMessages([
                $fieldName => ["El campo '{$fieldName}' debe ser un numero entero entre 1 y 31."],
            ]);
        }

        $day = (int) $normalizedValue;
        if ($day < 1 || $day > 31) {
            throw ValidationException::withMessages([
                $fieldName => ["El campo '{$fieldName}' debe estar entre 1 y 31."],
            ]);
        }

        return $day;
    }

    private function normalizeResidentType(string $value): string
    {
        $normalized = Str::of($value)->lower()->ascii()->trim()->value();

        return match ($normalized) {
            'propietario', 'propietaria' => 'propietario',
            'arrendatario', 'arrendataria', 'inquilino', 'inquilina' => 'arrendatario',
            default => throw ValidationException::withMessages([
                'tipo_residente' => ["El tipo_residente '{$value}' no es valido. Valores permitidos: propietario o arrendatario."],
            ]),
        };
    }

    private function normalizeBooleanCsvValue(string $value, string $fieldName): bool
    {
        $normalized = Str::of($value)->lower()->ascii()->trim()->value();

        if (in_array($normalized, ['1', 'true', 'si', 'yes', 'activo'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'inactivo'], true)) {
            return false;
        }

        throw ValidationException::withMessages([
            $fieldName => ["El campo {$fieldName} debe ser 1/0, true/false o activo/inactivo."],
        ]);
    }

    private function normalizeUnitTypeNameForComparison(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->replace(' ', '')
            ->value();
    }

    private function formatApartmentDebtLabel(?string $tower, ?string $number): string
    {
        $normalizedTower = trim((string) ($tower ?? ''));
        $normalizedNumber = trim((string) ($number ?? ''));

        if ($normalizedTower !== '' && $normalizedNumber !== '') {
            return sprintf('Torre %s-%s', $normalizedTower, $normalizedNumber);
        }

        if ($normalizedNumber !== '') {
            return 'Apto ' . $normalizedNumber;
        }

        return 'Unidad sin definir';
    }
}
