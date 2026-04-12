<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Apartment;
use App\Modules\Core\Models\Condominium;
use App\Modules\Core\Models\UnitType;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnitTypeBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_resident_creation_for_unit_types_that_do_not_allow_residents(): void
    {
        $user = $this->platformAdmin();
        $condominium = $this->condominium();
        $parkingType = $this->unitType($condominium->id, [
            'name' => 'Parqueaderos visitantes',
            'allows_residents' => false,
            'requires_parent' => true,
        ]);
        $primaryType = $this->unitType($condominium->id, [
            'name' => 'Apartamento base',
            'allows_residents' => true,
            'requires_parent' => false,
        ]);
        $primaryApartment = $this->apartment($condominium->id, $primaryType->id, ['number' => 'A-101']);
        $parkingApartment = $this->apartment($condominium->id, $parkingType->id, [
            'number' => 'PQ-01',
            'parent_id' => $primaryApartment->id,
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Active-Condominium-Id', (string) $condominium->id)
            ->postJson('/api/residents', [
                'full_name' => 'Residente prueba',
                'email' => 'residente.prueba@example.test',
                'document_number' => '9000001',
                'apartment_id' => $parkingApartment->id,
                'type' => 'propietario',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['apartment_id']);
    }

    public function test_it_requires_parent_for_unit_types_marked_as_dependent(): void
    {
        $user = $this->platformAdmin();
        $condominium = $this->condominium();
        $parkingType = $this->unitType($condominium->id, [
            'name' => 'Parqueadero premium',
            'allows_residents' => false,
            'requires_parent' => true,
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Active-Condominium-Id', (string) $condominium->id)
            ->postJson('/api/apartments', [
                'unit_type_id' => $parkingType->id,
                'number' => 'PQ-02',
                'tower' => 'A',
                'floor' => 1,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_it_blocks_invalid_unit_type_behavior_updates_when_data_already_exists(): void
    {
        $user = $this->platformAdmin();
        $condominium = $this->condominium();
        $primaryType = $this->unitType($condominium->id, [
            'name' => 'Apartamento',
            'allows_residents' => true,
            'requires_parent' => false,
        ]);
        $apartment = $this->apartment($condominium->id, $primaryType->id, ['number' => 'A-201']);

        $this->actingAs($user, 'api')
            ->withHeader('X-Active-Condominium-Id', (string) $condominium->id)
            ->postJson('/api/residents', [
                'full_name' => 'Titular existente',
                'email' => 'titular.existente@example.test',
                'document_number' => '9000002',
                'apartment_id' => $apartment->id,
                'type' => 'propietario',
            ])
            ->assertStatus(201);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Active-Condominium-Id', (string) $condominium->id)
            ->putJson('/api/unit-types/' . $primaryType->id, [
                'allows_residents' => false,
                'requires_parent' => false,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allows_residents']);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'document_number' => (string) random_int(1000000, 9999999),
            'is_platform_admin' => true,
            'is_active' => true,
            'api_token' => hash('sha256', Str::random(40)),
        ]);
    }

    private function condominium(): Condominium
    {
        return Condominium::query()->create([
            'name' => 'Condominio Test',
            'tenant_code' => 'test-' . Str::lower(Str::random(8)),
            'type' => 'residencial',
            'is_active' => true,
        ]);
    }

    private function unitType(int $condominiumId, array $attributes): UnitType
    {
        return UnitType::query()->create(array_merge([
            'condominium_id' => $condominiumId,
            'name' => 'Tipo ' . Str::random(6),
            'allows_residents' => false,
            'requires_parent' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function apartment(int $condominiumId, int $unitTypeId, array $attributes): Apartment
    {
        return Apartment::query()->create(array_merge([
            'condominium_id' => $condominiumId,
            'unit_type_id' => $unitTypeId,
            'number' => 'APT-' . Str::upper(Str::random(4)),
            'tower' => 'A',
            'floor' => 1,
            'is_active' => true,
        ], $attributes));
    }
}
