<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\HealthIncident;
use App\Models\EmergencyType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EmergencyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_emergency_can_be_created()
    {
        /** @var \App\Models\User $user */
        $user = User::factory()->create();

        $type = EmergencyType::factory()->create([
            'condominium_id' => 1
        ]);

        $response = $this->actingAs($user)->postJson('/api/emergencies', [
            'emergency_type_id' => $type->id,
            'event_type' => 'Incendio',
            'event_location' => 'Lobby',
            'description' => 'Prueba emergencia',
            'event_date' => now(),
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('health_incidents', [
            'event_location' => 'Lobby',
            'description' => 'Prueba emergencia',
        ]);
    }
}
