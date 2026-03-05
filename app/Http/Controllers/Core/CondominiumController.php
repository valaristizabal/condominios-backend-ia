<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Condominium;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CondominiumController extends Controller
{
    public function active(Request $request): JsonResponse
    {
        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');
        if ($activeCondominiumId <= 0) {
            throw ValidationException::withMessages([
                'condominium' => ['No hay condominio activo resuelto para esta operacion.'],
            ]);
        }

        $condominium = Condominium::query()->findOrFail($activeCondominiumId);
        return response()->json($this->present($condominium));
    }

    public function index(): JsonResponse
    {
        $condominiums = Condominium::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (Condominium $item) => $this->present($item));

        return response()->json($condominiums->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tenant_code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:condominiums,tenant_code'],
            'type' => ['nullable', 'string', 'max:100'],
            'common_areas' => ['nullable', 'string'],
            'tower' => ['nullable', 'string', 'max:100'],
            'floors' => ['nullable', 'integer', 'min:1'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_info' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $condominium = Condominium::query()->create($this->onlyCondominiumFields($data));

        if ($request->hasFile('logo')) {
            $this->replaceLogo($condominium, $request);
        }

        return response()->json($this->present($condominium->fresh()), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $condominium = Condominium::query()->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tenant_code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('condominiums', 'tenant_code')->ignore($condominium->id),
            ],
            'type' => ['nullable', 'string', 'max:100'],
            'common_areas' => ['nullable', 'string'],
            'tower' => ['nullable', 'string', 'max:100'],
            'floors' => ['nullable', 'integer', 'min:1'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_info' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $condominium->update($this->onlyCondominiumFields($data));

        if ($request->hasFile('logo')) {
            $this->replaceLogo($condominium, $request);
        }

        return response()->json($this->present($condominium->fresh()));
    }

    public function toggle(int $id): JsonResponse
    {
        $condominium = Condominium::query()->findOrFail($id);

        $condominium->is_active = ! $condominium->is_active;
        $condominium->save();

        return response()->json([
            'message' => $condominium->is_active
                ? 'Condominio activado.'
                : 'Condominio desactivado.',
            'data' => $this->present($condominium->fresh()),
        ]);
    }

    private function onlyCondominiumFields(array $data): array
    {
        unset($data['logo']);
        return $data;
    }

    private function replaceLogo(Condominium $condominium, Request $request): void
    {
        if (! $request->hasFile('logo')) {
            return;
        }

        if (! $this->hasLogoColumn()) {
            return;
        }

        if (! empty($condominium->logo_path) && ! Str::startsWith($condominium->logo_path, ['http://', 'https://'])) {
            Storage::disk('public')->delete($condominium->logo_path);
        }

        $path = $request->file('logo')->store(sprintf('condominiums/%d/logo', $condominium->id), 'public');

        $condominium->logo_path = $path;
        $condominium->save();
    }

    private function present(Condominium $condominium): array
    {
        $data = $condominium->toArray();
        $data['logo_url'] = $this->hasLogoColumn()
            ? $this->resolvePublicStorageUrl($condominium->logo_path)
            : null;

        return $data;
    }

    private function hasLogoColumn(): bool
    {
        return Schema::hasColumn('condominiums', 'logo_path');
    }

    private function resolvePublicStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', 'data:image'])) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}
