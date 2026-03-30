<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Apartment;
use App\Models\Correspondence;
use App\Models\Resident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CorrespondenceController extends Controller
{
    public function bootstrapData(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectForbiddenFieldsFromRequest($request);

        $apartments = Apartment::query()
            ->with(['unitType:id,name'])
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->orderBy('tower')
            ->orderBy('number')
            ->get(['id', 'condominium_id', 'unit_type_id', 'tower', 'number', 'floor', 'is_active']);

        $residents = Resident::query()
            ->with(['user:id,full_name,email,document_number'])
            ->whereHas('apartment', function ($query) use ($activeCondominiumId) {
                $query->where('condominium_id', $activeCondominiumId);
            })
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'apartment_id', 'type', 'is_active']);

        return response()->json([
            'apartments' => $apartments,
            'residents' => $residents,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectForbiddenFieldsFromRequest($request);
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        $items = Correspondence::query()
            ->with([
                'apartment.unitType:id,name',
                'receivedBy:id,full_name,email,document_number',
                'residentReceiver:id,user_id,apartment_id,type,is_active',
                'residentReceiver.user:id,full_name,email,document_number',
                'deliveredBy:id,full_name,email,document_number',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $items->setCollection(
            $items->getCollection()->map(fn (Correspondence $item) => $this->present($item))
        );

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectForbiddenFieldsFromRequest($request);
        $this->rejectCreateForbiddenFieldsFromRequest($request);

        $validated = $request->validate([
            'courier_company' => ['required', 'string', 'max:255'],
            'package_type' => ['required', 'string', Rule::in(['documento', 'paquete'])],
            'evidence_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'digital_signature' => ['nullable', 'string'],
            'deliver_immediately' => ['nullable', 'boolean'],
            'resident_receiver_id' => ['nullable', 'integer', 'exists:residents,id'],
            'apartment_id' => ['required', 'integer', 'exists:apartments,id'],
        ]);

        $apartment = $this->resolveApartmentInActiveCondominium((int) $validated['apartment_id'], $activeCondominiumId);

        $evidencePhotoPath = $request->hasFile('evidence_photo')
            ? $request->file('evidence_photo')->store(
                sprintf('correspondence/condominium_%d/%s', $activeCondominiumId, now()->format('Y/m/d')),
                'public'
            )
            : null;

        $residentReceiver = null;
        $hasImmediateDelivery = (bool) ($validated['deliver_immediately'] ?? false) || ! empty($validated['resident_receiver_id']);
        if (! empty($validated['resident_receiver_id'])) {
            $residentReceiver = $this->resolveResidentInActiveCondominium(
                (int) $validated['resident_receiver_id'],
                $activeCondominiumId
            );

            if ((int) $residentReceiver->apartment_id !== (int) $apartment->id) {
                throw ValidationException::withMessages([
                    'resident_receiver_id' => ['El residente no pertenece a la unidad seleccionada.'],
                ]);
            }
        }

        if ($hasImmediateDelivery && empty($validated['digital_signature'])) {
            throw ValidationException::withMessages([
                'digital_signature' => ['La firma digital es obligatoria para entrega inmediata.'],
            ]);
        }

        $storedSignature = ! empty($validated['digital_signature'])
            ? $this->storeDigitalSignatureIfBase64((string) $validated['digital_signature'], $activeCondominiumId)
            : null;

        $item = Correspondence::query()->create([
            'condominium_id' => $activeCondominiumId,
            'apartment_id' => (int) $validated['apartment_id'],
            'courier_company' => $validated['courier_company'],
            'package_type' => $validated['package_type'],
            'evidence_photo' => $evidencePhotoPath,
            'digital_signature' => $storedSignature,
            'status' => $hasImmediateDelivery ? Correspondence::STATUS_DELIVERED : Correspondence::STATUS_RECEIVED,
            'received_by_id' => $request->user()?->id,
            'resident_receiver_id' => $residentReceiver?->id,
            'delivered_by_id' => $hasImmediateDelivery ? $request->user()?->id : null,
            'delivered_at' => $hasImmediateDelivery ? now() : null,
        ]);

        $freshItem = $item->fresh()->load([
            'apartment.unitType:id,name',
            'receivedBy:id,full_name,email,document_number',
            'residentReceiver:id,user_id,apartment_id,type,is_active',
            'residentReceiver.user:id,full_name,email,document_number',
            'deliveredBy:id,full_name,email,document_number',
        ]);

        return response()->json($this->present($freshItem), 201);
    }

    public function deliver(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectForbiddenFieldsFromRequest($request);

        $validated = $request->validate([
            'digital_signature' => ['required', 'string'],
            'resident_receiver_id' => ['required', 'integer', 'exists:residents,id'],
        ]);

        $item = Correspondence::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        if ($item->status === Correspondence::STATUS_DELIVERED) {
            return response()->json([
                'message' => 'La correspondencia ya fue entregada.',
            ], 400);
        }

        if ($item->status !== Correspondence::STATUS_RECEIVED) {
            return response()->json([
                'message' => 'La correspondencia no se encuentra en estado RECEIVED_BY_SECURITY.',
            ], 400);
        }

        $digitalSignature = $this->storeDigitalSignatureIfBase64(
            (string) $validated['digital_signature'],
            $activeCondominiumId
        );

        $resident = $this->resolveResidentInActiveCondominium(
            (int) $validated['resident_receiver_id'],
            $activeCondominiumId
        );

        $item->update([
            'status' => Correspondence::STATUS_DELIVERED,
            'digital_signature' => $digitalSignature,
            'resident_receiver_id' => $resident->id,
            'delivered_by_id' => $request->user()?->id,
            'delivered_at' => now(),
        ]);

        $freshItem = $item->fresh()->load([
            'apartment.unitType:id,name',
            'receivedBy:id,full_name,email,document_number',
            'residentReceiver:id,user_id,apartment_id,type,is_active',
            'residentReceiver.user:id,full_name,email,document_number',
            'deliveredBy:id,full_name,email,document_number',
        ]);

        return response()->json($this->present($freshItem));
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

    private function rejectForbiddenFieldsFromRequest(Request $request): void
    {
        $forbiddenFields = [
            'condominium_id',
            'delivered',
            'received_by_id',
            'delivered_by_id',
            'status',
            'delivered_at',
        ];

        foreach ($forbiddenFields as $field) {
            if ($request->query->has($field) || $request->request->has($field)) {
                throw ValidationException::withMessages([
                    $field => ["No se permite enviar {$field} en este endpoint."],
                ]);
            }
        }
    }

    private function resolveApartmentInActiveCondominium(int $apartmentId, int $activeCondominiumId): Apartment
    {
        $apartment = Apartment::query()
            ->where('id', $apartmentId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $apartment) {
            throw ValidationException::withMessages([
                'apartment_id' => ['El apartamento no pertenece al condominio activo.'],
            ]);
        }

        return $apartment;
    }

    private function rejectCreateForbiddenFieldsFromRequest(Request $request): void
    {
        $forbiddenCreateFields = [
            'delivered',
        ];

        foreach ($forbiddenCreateFields as $field) {
            if ($request->query->has($field) || $request->request->has($field)) {
                throw ValidationException::withMessages([
                    $field => ["No se permite enviar {$field} en la creación de correspondencia."],
                ]);
            }
        }
    }

    private function storeDigitalSignatureIfBase64(string $signature, int $activeCondominiumId): string
    {
        if (! Str::startsWith($signature, 'data:image')) {
            return $signature;
        }

        if (! preg_match('/^data:image\/(\w+);base64,/', $signature, $matches)) {
            throw ValidationException::withMessages([
                'digital_signature' => ['Formato de firma inválido.'],
            ]);
        }

        $extension = strtolower($matches[1]);
        $allowed = ['png', 'jpg', 'jpeg', 'webp'];

        if (! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'digital_signature' => ['Formato de imagen de firma no permitido.'],
            ]);
        }

        $base64Data = substr($signature, strpos($signature, ',') + 1);
        $binaryData = base64_decode($base64Data, true);

        if ($binaryData === false) {
            throw ValidationException::withMessages([
                'digital_signature' => ['No fue posible procesar la firma digital.'],
            ]);
        }

        $path = sprintf(
            'correspondence/signatures/condominium_%d/%s/signature_%s.%s',
            $activeCondominiumId,
            now()->format('Y/m/d'),
            Str::uuid()->toString(),
            $extension
        );

        Storage::disk('public')->put($path, $binaryData);

        return $path;
    }

    private function present(Correspondence $item): array
    {
        $data = $item->toArray();
        $data['delivered'] = $item->status === Correspondence::STATUS_DELIVERED;
        $data['signature_url'] = $this->resolvePublicStorageUrl($item->digital_signature);

        return $data;
    }

    private function resolveResidentInActiveCondominium(int $residentId, int $activeCondominiumId): Resident
    {
        $resident = Resident::query()
            ->where('id', $residentId)
            ->whereHas('apartment', fn ($query) => $query->where('condominium_id', $activeCondominiumId))
            ->first();

        if (! $resident) {
            throw ValidationException::withMessages([
                'resident_receiver_id' => ['El residente no pertenece al condominio activo.'],
            ]);
        }

        return $resident;
    }

    private function resolvePublicStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', 'data:image'])) {
            return $path;
        }

        return asset('storage/'.ltrim($path, '/'));
    }
}
