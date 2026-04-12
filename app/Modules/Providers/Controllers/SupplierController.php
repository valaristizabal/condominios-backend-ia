<?php

namespace App\Modules\Providers\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Providers\Models\Supplier;
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
}





