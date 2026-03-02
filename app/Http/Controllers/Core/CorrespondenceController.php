<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Apartment;
use App\Models\Correspondence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CorrespondenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectForbiddenFieldsFromRequest($request);

        $items = Correspondence::query()
            ->with([
                'apartment.unitType:id,name',
                'receivedBy:id,full_name,email,document_number',
                'deliveredBy:id,full_name,email,document_number',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->orderByDesc('id')
            ->get();

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectForbiddenFieldsFromRequest($request);

        $validated = $request->validate([
            'courier_company' => ['required', 'string', 'max:255'],
            'package_type' => ['required', 'string', 'max:255'],
            'evidence_photo' => ['nullable', 'image', 'max:5120'],
            'apartment_id' => ['required', 'integer', 'exists:apartments,id'],
        ]);

        $this->resolveApartmentInActiveCondominium((int) $validated['apartment_id'], $activeCondominiumId);

        $evidencePhotoPath = $request->hasFile('evidence_photo')
            ? $request->file('evidence_photo')->store(
                sprintf('correspondence/condominium_%d/%s', $activeCondominiumId, now()->format('Y/m/d')),
                'public'
            )
            : null;

        $item = Correspondence::query()->create([
            'condominium_id' => $activeCondominiumId,
            'apartment_id' => (int) $validated['apartment_id'],
            'courier_company' => $validated['courier_company'],
            'package_type' => $validated['package_type'],
            'evidence_photo' => $evidencePhotoPath,
            'delivered' => false,
            'received_by_id' => $request->user()?->id,
        ]);

        return response()->json(
            $item->fresh()->load([
                'apartment.unitType:id,name',
                'receivedBy:id,full_name,email,document_number',
                'deliveredBy:id,full_name,email,document_number',
            ]),
            201
        );
    }

    public function deliver(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectForbiddenFieldsFromRequest($request);

        $validated = $request->validate([
            'digital_signature' => ['required', 'string'],
        ]);

        $item = Correspondence::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $digitalSignature = $this->storeDigitalSignatureIfBase64(
            (string) $validated['digital_signature'],
            $activeCondominiumId
        );

        $item->update([
            'delivered' => true,
            'digital_signature' => $digitalSignature,
            'delivered_by_id' => $request->user()?->id,
        ]);

        return response()->json(
            $item->fresh()->load([
                'apartment.unitType:id,name',
                'receivedBy:id,full_name,email,document_number',
                'deliveredBy:id,full_name,email,document_number',
            ])
        );
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
}
