<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Operative;
use App\Modules\Security\Models\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Models\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperativeController extends Controller
{
    private const ALWAYS_BLOCKED_ROLE_NAMES = ['Super Usuario', 'super_usuario', 'super_admin'];
    private const TENANT_BLOCKED_ADMIN_ROLE_NAMES = ['Administrador Propiedad', 'administrador_propiedad', 'admin_condominio'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'q' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'contract_type' => ['nullable', Rule::in(['contratista', 'planta'])],
        ]);

        $activeCondominiumId = $this->resolveActiveCondominiumIdForIndex($request);

        if ($activeCondominiumId <= 0) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => (int) ($validated['per_page'] ?? 10),
                'total' => 0,
            ]);
        }

        $operatives = Operative::query()
            ->with(['user.roles' => fn ($q) => $q->select('roles.id', 'roles.name')])
            ->where('condominium_id', $activeCondominiumId)
            ->when(
                ! empty($validated['q']),
                function ($query) use ($validated) {
                    $search = trim((string) $validated['q']);
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery->whereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('full_name', 'like', '%' . $search . '%')
                                ->orWhere('document_number', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%');
                        })->orWhere('position', 'like', '%' . $search . '%');
                    });
                }
            )
            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where('is_active', (bool) $validated['is_active'])
            )
            ->when(
                ! empty($validated['contract_type']),
                fn ($query) => $query->where('contract_type', $validated['contract_type'])
            )
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        $operatives->setCollection(
            $operatives->getCollection()->map(
                fn (Operative $operative) => $this->formatOperative($operative, $activeCondominiumId)
            )
        );

        return response()->json($operatives);
    }

    public function roles(Request $request): JsonResponse
    {
        $isPlatformAdmin = (bool) $request->user()?->is_platform_admin;
        $blockedRoles = $isPlatformAdmin
            ? self::ALWAYS_BLOCKED_ROLE_NAMES
            : array_values(array_unique([
                ...self::ALWAYS_BLOCKED_ROLE_NAMES,
                ...self::TENANT_BLOCKED_ADMIN_ROLE_NAMES,
            ]));

        $roles = Role::query()
            ->where('is_active', true)
            ->whereNotIn('name', $blockedRoles)
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        return response()->json($roles);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromPayload($request);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'document_number' => ['required', 'string', 'max:50', 'unique:users,document_number'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'regex:/^\d+$/', 'between:10,15'],
            'birth_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'position' => ['nullable', 'string', 'max:120'],
            'contract_type' => ['required', Rule::in(['contratista', 'planta'])],
            'salary' => ['nullable', 'numeric', 'gt:0'],
            'financial_institution' => ['nullable', 'string', 'max:120'],
            'account_type' => ['nullable', Rule::in(['ahorros', 'corriente'])],
            'account_number' => [
                'nullable',
                'string',
                'max:60',
                Rule::unique('operatives', 'account_number')
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId)),
            ],
            'eps' => ['nullable', 'string', 'max:120'],
            'arl' => ['nullable', 'string', 'max:120'],
            'contract_start_date' => ['nullable', 'date', 'before_or_equal:today'],
        ], $this->validationMessages());
        $validated = $this->normalizeOperativePayload($validated);

        $role = $this->resolveOperativeRole(
            (int) $validated['role_id'],
            (bool) $request->user()?->is_platform_admin
        );

        try {
            $operative = DB::transaction(function () use ($validated, $activeCondominiumId, $role) {
                $user = User::query()->create([
                    'full_name' => $validated['full_name'],
                    'document_number' => $validated['document_number'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'phone' => $validated['phone'] ?? null,
                    'birth_date' => $validated['birth_date'] ?? null,
                    'is_active' => $validated['is_active'] ?? true,
                    'is_platform_admin' => false,
                ]);

                $newOperative = Operative::query()->create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'condominium_id' => $activeCondominiumId,
                    'position' => $validated['position'] ?? $role->name,
                    'contract_type' => $validated['contract_type'],
                    'salary' => $validated['salary'] ?? null,
                    'financial_institution' => $validated['financial_institution'] ?? null,
                    'account_type' => $validated['account_type'] ?? null,
                    'account_number' => $validated['account_number'] ?? null,
                    'eps' => $validated['eps'] ?? null,
                    'arl' => $validated['arl'] ?? null,
                    'contract_start_date' => $validated['contract_start_date'] ?? null,
                    'is_active' => $validated['is_active'] ?? true,
                ]);

                UserRole::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'condominium_id' => $activeCondominiumId,
                    ],
                    [
                        'role_id' => $role->id,
                    ]
                );

                return $newOperative;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe un operativo para ese usuario en el condominio activo.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json(
            $this->formatOperative($operative->fresh()->load(['user', 'user.roles']), $activeCondominiumId),
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromPayload($request);

        $operative = Operative::query()
            ->with('user')
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->first();

        if (! $operative) {
            return response()->json([
                'message' => 'Operativo no encontrado para el condominio activo.',
            ], 404);
        }

        $currentUserId = (int) ($operative->user?->id ?? $operative->user_id ?? 0);

        $validated = $request->validate([
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'position' => ['sometimes', 'string', 'max:120'],
            'contract_type' => ['sometimes', Rule::in(['contratista', 'planta'])],
            'salary' => ['nullable', 'numeric', 'gt:0'],
            'financial_institution' => ['nullable', 'string', 'max:120'],
            'account_type' => ['nullable', Rule::in(['ahorros', 'corriente'])],
            'account_number' => [
                'nullable',
                'string',
                'max:60',
                Rule::unique('operatives', 'account_number')
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId))
                    ->ignore($operative->id, 'id'),
            ],
            'eps' => ['nullable', 'string', 'max:120'],
            'arl' => ['nullable', 'string', 'max:120'],
            'contract_start_date' => ['nullable', 'date', 'before_or_equal:today'],
            'is_active' => ['sometimes', 'boolean'],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'document_number' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('users', 'document_number')->ignore($currentUserId, 'id'),
            ],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($currentUserId, 'id'),
            ],
            'phone' => ['nullable', 'string', 'regex:/^\d+$/', 'between:10,15'],
            'birth_date' => ['nullable', 'date'],
        ], $this->validationMessages());
        $validated = $this->normalizeOperativePayload($validated);

        $selectedRole = null;
        if (isset($validated['role_id'])) {
            $selectedRole = $this->resolveOperativeRole(
                (int) $validated['role_id'],
                (bool) $request->user()?->is_platform_admin
            );
        }

        DB::transaction(function () use ($operative, $validated, $activeCondominiumId, $selectedRole) {
            $userData = collect($validated)->only([
                'full_name',
                'document_number',
                'email',
                'phone',
                'birth_date',
                'is_active',
            ])->all();

            if (! empty($userData)) {
                $operative->user()->update($userData);
            }

            $operativeData = collect($validated)->only([
                'role_id',
                'position',
                'contract_type',
                'salary',
                'financial_institution',
                'account_type',
                'account_number',
                'eps',
                'arl',
                'contract_start_date',
                'is_active',
            ])->all();

            if (! empty($operativeData)) {
                $operative->update($operativeData);
            }

            if ($selectedRole) {
                UserRole::query()->updateOrCreate(
                    [
                        'user_id' => $operative->user_id,
                        'condominium_id' => $activeCondominiumId,
                    ],
                    [
                        'role_id' => $selectedRole->id,
                    ]
                );

                if (! isset($validated['position'])) {
                    $operative->update([
                        'position' => $selectedRole->name,
                    ]);
                }
            }
        });

        return response()->json(
            $this->formatOperative($operative->fresh()->load(['user', 'user.roles']), $activeCondominiumId)
        );
    }

    public function import(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromPayload($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], $this->validationMessages());

        $file = $validated['file'];
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => ['No fue posible leer el archivo CSV cargado.'],
            ]);
        }

        [$created, $updated, $failed, $errors] = $this->importCsvFile(
            $file,
            $activeCondominiumId,
            (bool) $request->user()?->is_platform_admin
        );

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
                'condominium' => ['No hay condominio activo resuelto para esta operación.'],
            ]);
        }

        return $activeCondominiumId;
    }

    private function resolveActiveCondominiumIdForIndex(Request $request): int
    {
        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

        if ($activeCondominiumId > 0) {
            return $activeCondominiumId;
        }

        $user = $request->user();
        if (! $user) {
            return 0;
        }

        // Super admin can set tenant context by header.
        if ($user->is_platform_admin) {
            $headerCondominiumId = (int) $request->header('X-Active-Condominium-Id', 0);
            return $headerCondominiumId > 0 ? $headerCondominiumId : 0;
        }

        // Tenant user fallback: resolve from pivot in case middleware context is missing.
        $role = $user->roles()->first();
        if ($role && isset($role->pivot->condominium_id)) {
            return (int) $role->pivot->condominium_id;
        }

        return 0;
    }

    private function rejectCondominiumIdFromPayload(Request $request): void
    {
        if ($request->exists('condominium_id')) {
            throw ValidationException::withMessages([
                'condominium_id' => ['No se permite enviar condominium_id en este endpoint.'],
            ]);
        }
    }

    private function resolveOperativeRole(int $roleId, bool $isPlatformAdmin): Role
    {
        $role = Role::query()->findOrFail($roleId);

        if (in_array($role->name, self::ALWAYS_BLOCKED_ROLE_NAMES, true)) {
            throw ValidationException::withMessages([
                'role_id' => ['No se permite asignar roles administrativos a operativos.'],
            ]);
        }

        if (! $isPlatformAdmin && in_array($role->name, self::TENANT_BLOCKED_ADMIN_ROLE_NAMES, true)) {
            throw ValidationException::withMessages([
                'role_id' => ['No se permite asignar roles administrativos a operativos.'],
            ]);
        }

        if (! $role->is_active) {
            throw ValidationException::withMessages([
                'role_id' => ['El rol seleccionado no esta activo.'],
            ]);
        }

        return $role;
    }

    private function formatOperative(Operative $operative, int $activeCondominiumId): array
    {
        $matchedRole = $operative->user?->roles
            ?->first(fn ($role) => (int) $role->pivot->condominium_id === $activeCondominiumId);

        return [
            'id' => $operative->id,
            'user_id' => $operative->user_id,
            'condominium_id' => $operative->condominium_id,
            'position' => $operative->position,
            'contract_type' => $operative->contract_type,
            'salary' => $operative->salary,
            'financial_institution' => $operative->financial_institution,
            'account_type' => $operative->account_type,
            'account_number' => $operative->account_number,
            'eps' => $operative->eps,
            'arl' => $operative->arl,
            'contract_start_date' => $operative->contract_start_date,
            'is_active' => $operative->is_active,
            'created_at' => $operative->created_at,
            'updated_at' => $operative->updated_at,
            'role' => $matchedRole ? ['id' => $matchedRole->id, 'name' => $matchedRole->name] : null,
            'user' => $operative->user,
        ];
    }

    private function validationMessages(): array
    {
        return [
            'account_number.unique' => 'Ya existe un operativo con este numero de cuenta en el condominio activo.',
            'document_number.unique' => 'Ya existe un operativo con este número de documento.',
            'phone.regex' => 'El celular debe contener solo números.',
            'phone.between' => 'El celular debe tener entre 10 y 15 dígitos.',
            'salary.gt' => 'El salario debe ser mayor a cero.',
            'contract_start_date.before_or_equal' => 'La fecha de inicio no puede ser futura.',
        ];
    }

    private function normalizeOperativePayload(array $validated): array
    {
        foreach ([
            'full_name',
            'document_number',
            'email',
            'phone',
            'position',
            'financial_institution',
            'account_number',
            'eps',
            'arl',
        ] as $field) {
            if (array_key_exists($field, $validated) && is_string($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        if (isset($validated['email'])) {
            $validated['email'] = Str::lower($validated['email']);
        }

        foreach (['phone', 'position', 'financial_institution', 'account_number', 'eps', 'arl'] as $nullableField) {
            if (array_key_exists($nullableField, $validated) && $validated[$nullableField] === '') {
                $validated[$nullableField] = null;
            }
        }

        return $validated;
    }

    private function importCsvFile(UploadedFile $file, int $activeCondominiumId, bool $isPlatformAdmin): array
    {
        $defaultRole = $this->resolveDefaultImportRole($isPlatformAdmin);
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
                    'file' => ['El archivo CSV está vacío o no tiene encabezados válidos.'],
                ]);
            }

            $normalizedHeader = array_map([$this, 'normalizeCsvHeader'], $header);
            $required = [
                'nombre_completo',
                'documento',
                'email',
                'celular',
                'contrasena',
                'cargo_rol',
                'tipo_contrato',
                'salario',
                'inicio_contrato',
            ];

            foreach ($required as $column) {
                if (! in_array($column, $normalizedHeader, true)) {
                    throw ValidationException::withMessages([
                        'file' => ['El archivo debe incluir las columnas requeridas del formato de operativos.'],
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
                        'document_number' => $this->csvValue($row, $columnIndex, 'documento'),
                        'email' => $this->csvValue($row, $columnIndex, 'email'),
                        'phone' => $this->csvValue($row, $columnIndex, 'celular'),
                        'birth_date' => $this->normalizeCsvDate(
                            $this->csvValue($row, $columnIndex, 'fecha_nacimiento'),
                            'fecha_nacimiento'
                        ),
                        'password' => $this->csvValue($row, $columnIndex, 'contrasena'),
                        'position' => $this->csvValue($row, $columnIndex, 'cargo_interno'),
                        'contract_type' => $this->normalizeContractType(
                            $this->csvValue($row, $columnIndex, 'tipo_contrato')
                        ),
                        'salary' => $this->csvValue($row, $columnIndex, 'salario'),
                        'financial_institution' => $this->csvValue($row, $columnIndex, 'institucion_financiera'),
                        'account_type' => Str::lower($this->csvValue($row, $columnIndex, 'tipo_cuenta')),
                        'account_number' => $this->csvValue($row, $columnIndex, 'numero_cuenta'),
                        'eps' => $this->csvValue($row, $columnIndex, 'eps'),
                        'arl' => $this->csvValue($row, $columnIndex, 'arl'),
                        'contract_start_date' => $this->normalizeCsvDate(
                            $this->csvValue($row, $columnIndex, 'inicio_contrato'),
                            'inicio_contrato'
                        ),
                    ];
                    $payload['role_id'] = $this->resolveImportRoleId(
                        $this->csvValue($row, $columnIndex, 'cargo_rol'),
                        $defaultRole,
                        $isPlatformAdmin
                    );
                    $payload['position'] = $payload['position'] !== '' ? $payload['position'] : $defaultRole->name;
                    $payload['is_active'] = true;
                } catch (ValidationException $exception) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: " . collect($exception->errors())->flatten()->first();
                    continue;
                }

                if (isset($seenDocuments[$payload['document_number']])) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: el documento {$payload['document_number']} está duplicado dentro del archivo.";
                    continue;
                }

                $seenDocuments[$payload['document_number']] = true;

                if (isset($seenEmails[Str::lower($payload['email'])])) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: el correo {$payload['email']} está duplicado dentro del archivo.";
                    continue;
                }

                $seenEmails[Str::lower($payload['email'])] = true;

                $existingUserByDocument = User::query()
                    ->where('document_number', $payload['document_number'])
                    ->first();
                $existingUserByEmail = User::query()
                    ->where('email', Str::lower($payload['email']))
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
                    'document_number' => ['required', 'string', 'max:50'],
                    'email' => ['required', 'email', 'max:255'],
                    'password' => ['required', 'string', 'min:8'],
                    'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
                    'phone' => ['required', 'string', 'regex:/^\d+$/', 'between:10,15'],
                    'role_id' => ['required', 'integer', 'exists:roles,id'],
                    'position' => ['nullable', 'string', 'max:120'],
                    'contract_type' => ['required', Rule::in(['contratista', 'planta'])],
                    'salary' => ['required', 'numeric', 'gt:0'],
                    'financial_institution' => ['nullable', 'string', 'max:120'],
                    'account_type' => ['nullable', Rule::in(['ahorros', 'corriente'])],
                    'account_number' => ['nullable', 'string', 'max:60'],
                    'eps' => ['nullable', 'string', 'max:120'],
                    'arl' => ['nullable', 'string', 'max:120'],
                    'contract_start_date' => ['required', 'date', 'before_or_equal:today'],
                ];

                if ($existingUser) {
                    $validationRules['document_number'][] = Rule::unique('users', 'document_number')->ignore($existingUser->id, 'id');
                    $validationRules['email'][] = Rule::unique('users', 'email')->ignore($existingUser->id, 'id');
                } else {
                    $validationRules['document_number'][] = 'unique:users,document_number';
                    $validationRules['email'][] = 'unique:users,email';
                }

                $validator = validator(
                    $this->normalizeOperativePayload($payload),
                    $validationRules,
                    $this->validationMessages()
                );

                if ($validator->fails()) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: " . $validator->errors()->first();
                    continue;
                }

                try {
                    $wasUpdated = false;
                    DB::transaction(function () use ($payload, $activeCondominiumId, $defaultRole, $existingUser, &$wasUpdated): void {
                        $user = $existingUser;

                        if ($user) {
                            $user->update([
                                'full_name' => $payload['full_name'],
                                'document_number' => $payload['document_number'],
                                'email' => $payload['email'],
                                'password' => $payload['password'],
                                'phone' => $payload['phone'],
                                'birth_date' => $payload['birth_date'] ?: null,
                                'is_active' => true,
                            ]);
                            $wasUpdated = true;
                        } else {
                            $user = User::query()->create([
                                'full_name' => $payload['full_name'],
                                'document_number' => $payload['document_number'],
                                'email' => $payload['email'],
                                'password' => $payload['password'],
                                'phone' => $payload['phone'],
                                'birth_date' => $payload['birth_date'] ?: null,
                                'is_active' => true,
                                'is_platform_admin' => false,
                            ]);
                        }

                        $operative = Operative::query()
                            ->where('user_id', $user->id)
                            ->where('condominium_id', $activeCondominiumId)
                            ->first();

                        if ($operative) {
                            $operative->update([
                                'role_id' => $payload['role_id'],
                                'position' => $payload['position'] ?: $defaultRole->name,
                                'contract_type' => $payload['contract_type'],
                                'salary' => $payload['salary'],
                                'financial_institution' => $payload['financial_institution'] ?: null,
                                'account_type' => $payload['account_type'] ?: null,
                                'account_number' => $payload['account_number'] ?: null,
                                'eps' => $payload['eps'] ?: null,
                                'arl' => $payload['arl'] ?: null,
                                'contract_start_date' => $payload['contract_start_date'],
                                'is_active' => true,
                            ]);
                            $wasUpdated = true;
                        } else {
                            Operative::query()->create([
                                'user_id' => $user->id,
                                'role_id' => $payload['role_id'],
                                'condominium_id' => $activeCondominiumId,
                                'position' => $payload['position'] ?: $defaultRole->name,
                                'contract_type' => $payload['contract_type'],
                                'salary' => $payload['salary'],
                                'financial_institution' => $payload['financial_institution'] ?: null,
                                'account_type' => $payload['account_type'] ?: null,
                                'account_number' => $payload['account_number'] ?: null,
                                'eps' => $payload['eps'] ?: null,
                                'arl' => $payload['arl'] ?: null,
                                'contract_start_date' => $payload['contract_start_date'],
                                'is_active' => true,
                            ]);
                        }

                        UserRole::query()->updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'condominium_id' => $activeCondominiumId,
                            ],
                            [
                                'role_id' => $payload['role_id'],
                            ]
                        );
                    });

                    if ($wasUpdated) {
                        $updated++;
                    } else {
                        $created++;
                    }
                } catch (\Throwable) {
                    $failed++;
                    $errors[] = "Fila {$rowNumber}: no fue posible crear el operativo.";
                }
            }
        } finally {
            fclose($handle);
        }

        return [$created, $updated, $failed, $errors];
    }

    private function resolveDefaultImportRole(bool $isPlatformAdmin): Role
    {
        $blockedRoles = $isPlatformAdmin
            ? self::ALWAYS_BLOCKED_ROLE_NAMES
            : array_values(array_unique([
                ...self::ALWAYS_BLOCKED_ROLE_NAMES,
                ...self::TENANT_BLOCKED_ADMIN_ROLE_NAMES,
            ]));

        $role = Role::query()
            ->where('is_active', true)
            ->whereNotIn('name', $blockedRoles)
            ->orderByRaw("case when name = 'Seguridad' then 0 else 1 end")
            ->orderBy('name')
            ->first();

        if (! $role) {
            throw ValidationException::withMessages([
                'file' => ['No existe un rol operativo activo disponible para la importación.'],
            ]);
        }

        return $role;
    }

    private function resolveImportRoleId(string $roleName, Role $defaultRole, bool $isPlatformAdmin): int
    {
        $normalizedRoleName = trim($roleName);
        if ($normalizedRoleName === '') {
            return $defaultRole->id;
        }

        $role = Role::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($normalizedRoleName)])
            ->first();

        if (! $role) {
            throw ValidationException::withMessages([
                'cargo_rol' => ["El rol '{$roleName}' no existe."],
            ]);
        }

        $this->resolveOperativeRole($role->id, $isPlatformAdmin);

        return $role->id;
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
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $text);
        $text = preg_replace('/\s+/u', '_', $text) ?? $text;

        return trim($text, '_');
    }

    private function normalizeCsvCell(mixed $value): string
    {
        $text = trim((string) $value);
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim($text);
    }

    private function normalizeCsvDate(string $value, string $fieldName): ?string
    {
        $normalizedValue = trim($value);

        if ($normalizedValue === '') {
            return null;
        }

        foreach (['!Y-m-d', '!j/n/Y', '!j/m/Y', '!d/n/Y', '!d/m/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $normalizedValue);

                if ($date !== false && ! Carbon::hasFormatWithModifiers($normalizedValue, $format)) {
                    continue;
                }

                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $timestamp = strtotime($normalizedValue);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        throw ValidationException::withMessages([
            $fieldName => ["La fecha '{$normalizedValue}' no es válida. Formatos permitidos: YYYY-MM-DD o DD/MM/YYYY."],
        ]);
    }

    private function normalizeContractType(string $value): string
    {
        $normalizedValue = Str::lower(trim($value));

        if (in_array($normalizedValue, ['planta', 'contratista'], true)) {
            return $normalizedValue;
        }

        throw ValidationException::withMessages([
            'tipo_contrato' => [
                "El tipo de contrato '{$value}' no es válido. Valores permitidos: planta, contratista.",
            ],
        ]);
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
}


