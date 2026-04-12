<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Condominium;
use App\Modules\Security\Models\Role;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperativeValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_duplicate_document_number_when_creating_an_operative(): void
    {
        $user = $this->platformAdmin();
        $condominium = $this->condominium();
        $role = $this->operativeRole();

        User::factory()->create([
            'document_number' => '1234567890',
            'email' => 'existente@example.test',
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Active-Condominium-Id', (string) $condominium->id)
            ->postJson('/api/operatives', [
                'full_name' => 'Operativo Repetido',
                'document_number' => '1234567890',
                'email' => 'nuevo@example.test',
                'password' => 'password123',
                'phone' => '3001234567',
                'role_id' => $role->id,
                'contract_type' => 'planta',
                'salary' => 1500000,
                'contract_start_date' => now()->toDateString(),
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document_number']);
    }

    public function test_it_rejects_future_contract_start_date(): void
    {
        $user = $this->platformAdmin();
        $condominium = $this->condominium();
        $role = $this->operativeRole();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Active-Condominium-Id', (string) $condominium->id)
            ->postJson('/api/operatives', [
                'full_name' => 'Operativo Futuro',
                'document_number' => '5555555555',
                'email' => 'futuro@example.test',
                'password' => 'password123',
                'phone' => '3001234567',
                'role_id' => $role->id,
                'contract_type' => 'planta',
                'salary' => 1500000,
                'contract_start_date' => now()->addDay()->toDateString(),
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contract_start_date']);
    }

    public function test_it_imports_operatives_from_csv_and_reports_invalid_rows(): void
    {
        $user = $this->platformAdmin();
        $condominium = $this->condominium();
        $this->operativeRole();

        $csv = implode("\n", [
            'nombre,documento,celular,salario,fecha_inicio',
            'Juan Perez,10001,3001234567,1800000,2026-01-10',
            'Ana Error,10002,ABC123,1900000,2026-01-10',
        ]);

        $file = UploadedFile::fake()->createWithContent('operativos.csv', $csv);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Active-Condominium-Id', (string) $condominium->id)
            ->post('/api/operatives/import', [
                'file' => $file,
            ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'created' => 1,
                'failed' => 1,
            ]);

        $this->assertDatabaseHas('users', [
            'document_number' => '10001',
        ]);
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
            'name' => 'Condominio Operativos',
            'tenant_code' => 'operativos-' . Str::lower(Str::random(8)),
            'type' => 'residencial',
            'is_active' => true,
        ]);
    }

    private function operativeRole(): Role
    {
        return Role::query()->create([
            'name' => 'Seguridad',
            'description' => 'Rol operativo',
            'is_active' => true,
        ]);
    }
}
