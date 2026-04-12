<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Apartment;
use App\Modules\Cleaning\Models\CleaningArea;
use App\Modules\Cleaning\Models\CleaningAreaChecklist;
use App\Modules\Cleaning\Models\CleaningSchedule;
use App\\Modules\\Core\\Models\\Condominium;
use App\Modules\Emergencies\Models\EmergencyContact;
use App\Modules\Emergencies\Models\EmergencyType;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryCategory;
use App\\Modules\\Core\\Models\\Operative;
use App\Modules\Inventory\Models\Product;
use App\Modules\Residents\Models\Resident;
use App\Modules\Security\Models\Role;
use App\Modules\Providers\Models\Supplier;
use App\\Modules\\Core\\Models\\UnitType;
use App\Modules\Security\Models\User;
use App\Modules\Security\Models\UserModulePermission;
use App\Modules\Vehicles\Models\VehicleType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RealisticSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $condominium = Condominium::query()->firstOrCreate(
            ['tenant_code' => 'la-pastorita'],
            [
                'name' => 'La pastorita',
                'type' => 'residencial',
                'is_active' => true,
            ]
        );

        DB::transaction(function () use ($condominium) {
            $unitTypes = $this->seedUnitTypes($condominium->id);
            $apartments = $this->seedApartments($condominium->id, $unitTypes);
            $this->seedVehicleTypes($condominium->id);
            $this->seedEmergencyTypes($condominium->id);
            $this->seedEmergencyContacts($condominium->id);
            $this->seedCleaningSettings($condominium->id);
            $this->seedInventorySettings($condominium->id);
            $this->seedOperatives($condominium->id);
            $this->seedResidents($condominium->id, $apartments);
        });
    }

    /**
     * @return array<string, UnitType>
     */
    private function seedUnitTypes(int $condominiumId): array
    {
        $types = [
            'Apartamento',
            'Apartaestudio',
            'Local Comercial',
            'Oficina Administrativa',
        ];

        $result = [];
        foreach ($types as $name) {
            $result[$name] = UnitType::query()->updateOrCreate(
                ['condominium_id' => $condominiumId, 'name' => $name],
                ['is_active' => true]
            );
        }

        return $result;
    }

    /**
     * @param array<string, UnitType> $unitTypes
     * @return array<string, Apartment>
     */
    private function seedApartments(int $condominiumId, array $unitTypes): array
    {
        $definitions = [
            ['tower' => 'A', 'number' => 'A101', 'floor' => 1, 'type' => 'Apartamento'],
            ['tower' => 'A', 'number' => 'A102', 'floor' => 1, 'type' => 'Apartamento'],
            ['tower' => 'A', 'number' => 'A201', 'floor' => 2, 'type' => 'Apartamento'],
            ['tower' => 'A', 'number' => 'A202', 'floor' => 2, 'type' => 'Apartamento'],
            ['tower' => 'B', 'number' => 'B101', 'floor' => 1, 'type' => 'Apartamento'],
            ['tower' => 'B', 'number' => 'B102', 'floor' => 1, 'type' => 'Apartamento'],
            ['tower' => 'B', 'number' => 'B201', 'floor' => 2, 'type' => 'Apartamento'],
            ['tower' => 'B', 'number' => 'B202', 'floor' => 2, 'type' => 'Apartamento'],
            ['tower' => 'Torre Norte', 'number' => 'N301', 'floor' => 3, 'type' => 'Apartaestudio'],
            ['tower' => 'Torre Norte', 'number' => 'N302', 'floor' => 3, 'type' => 'Apartaestudio'],
            ['tower' => 'Planta Baja', 'number' => 'LC-01', 'floor' => 1, 'type' => 'Local Comercial'],
            ['tower' => 'Administracion', 'number' => 'ADM-01', 'floor' => 1, 'type' => 'Oficina Administrativa'],
        ];

        $result = [];
        foreach ($definitions as $row) {
            $apartment = Apartment::query()->updateOrCreate(
                ['condominium_id' => $condominiumId, 'number' => $row['number']],
                [
                    'unit_type_id' => $unitTypes[$row['type']]->id,
                    'tower' => $row['tower'],
                    'floor' => $row['floor'],
                    'is_active' => true,
                ]
            );
            $result[$row['number']] = $apartment;
        }

        return $result;
    }

    private function seedVehicleTypes(int $condominiumId): void
    {
        foreach (['Automovil', 'Camioneta', 'Motocicleta', 'Bicicleta', 'Furgon'] as $name) {
            VehicleType::query()->updateOrCreate(
                ['condominium_id' => $condominiumId, 'name' => $name],
                ['is_active' => true]
            );
        }
    }

    private function seedEmergencyTypes(int $condominiumId): void
    {
        $types = [
            ['name' => 'Incendio', 'level' => 'ALTO'],
            ['name' => 'Fuga de gas', 'level' => 'CRITICO'],
            ['name' => 'Emergencia medica', 'level' => 'ALTO'],
            ['name' => 'Inundacion', 'level' => 'ALTO'],
            ['name' => 'Corte electrico', 'level' => 'MEDIO'],
        ];

        foreach ($types as $type) {
            EmergencyType::query()->updateOrCreate(
                ['condominium_id' => $condominiumId, 'name' => $type['name']],
                [
                    'level' => $type['level'],
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedEmergencyContacts(int $condominiumId): void
    {
        $contacts = [
            ['name' => 'Linea de emergencias', 'phone_number' => '123', 'icon' => 'phone'],
            ['name' => 'Bomberos de Bogota', 'phone_number' => '119', 'icon' => 'flame'],
            ['name' => 'Policia Nacional', 'phone_number' => '112', 'icon' => 'shield'],
            ['name' => 'Cruz Roja', 'phone_number' => '132', 'icon' => 'heart-pulse'],
            ['name' => 'Ambulancia privada aliada', 'phone_number' => '6017442222', 'icon' => 'ambulance'],
        ];

        foreach ($contacts as $contact) {
            EmergencyContact::query()->updateOrCreate(
                ['condominium_id' => $condominiumId, 'name' => $contact['name']],
                [
                    'phone_number' => $contact['phone_number'],
                    'icon' => $contact['icon'],
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedCleaningSettings(int $condominiumId): void
    {
        $areas = [
            'Lobby Torre A' => [
                'Barrer y trapear piso principal',
                'Desinfectar manijas y botones del ascensor',
                'Limpiar ventanales y puerta de acceso',
            ],
            'Lobby Torre B' => [
                'Barrer y trapear piso principal',
                'Desinfectar barandas y pasamanos',
                'Limpieza de mueble de recepcion',
            ],
            'Parqueadero Sotano 1' => [
                'Recoleccion de residuos en circulaciones',
                'Barrido de zonas peatonales',
                'Limpieza de cuarto de shut',
            ],
            'Zona de basuras' => [
                'Lavado de canecas',
                'Desinfeccion del cuarto',
                'Control de lixiviados y olores',
            ],
            'Gimnasio y salon social' => [
                'Desinfeccion de maquinas',
                'Limpieza de espejos',
                'Trapear zona de acceso',
            ],
        ];

        $startDate = now()->startOfMonth()->toDateString();

        foreach ($areas as $areaName => $tasks) {
            $area = CleaningArea::query()->updateOrCreate(
                ['condominium_id' => $condominiumId, 'name' => $areaName],
                [
                    'description' => 'Rutina de aseo preventivo y mantenimiento de imagen.',
                    'is_active' => true,
                ]
            );

            foreach ($tasks as $task) {
                $check = CleaningAreaChecklist::query()->firstOrCreate(
                    ['cleaning_area_id' => $area->id, 'item_name' => $task]
                );

                CleaningSchedule::query()->updateOrCreate(
                    [
                        'condominium_id' => $condominiumId,
                        'cleaning_area_id' => $area->id,
                        'name' => 'Rutina diaria - ' . $areaName . ' - ' . Str::limit($task, 30, ''),
                    ],
                    [
                        'description' => '[checklist_item:' . $check->id . '] ' . $task,
                        'frequency_type' => CleaningSchedule::FREQUENCY_DAILY,
                        'repeat_interval' => 1,
                        'days_of_week' => null,
                        'start_date' => $startDate,
                        'end_date' => null,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private function seedInventorySettings(int $condominiumId): void
    {
        $inventories = [];
        foreach (['Bodega General', 'Cuarto de Aseo', 'Cuarto de Herramientas'] as $name) {
            $inventories[$name] = Inventory::query()->updateOrCreate(
                ['condominium_id' => $condominiumId, 'name' => $name],
                ['is_active' => true]
            );
        }

        $categories = [];
        foreach (['Limpieza', 'Ferreteria', 'Papeleria', 'Tecnologia'] as $name) {
            $categories[$name] = InventoryCategory::query()->updateOrCreate(
                ['condominium_id' => $condominiumId, 'name' => $name],
                ['is_active' => true]
            );
        }

        $suppliers = [];
        $supplierRows = [
            [
                'name' => 'LimpioPlus SAS',
                'contact_name' => 'Carolina Pardo',
                'phone' => '3205551122',
                'email' => 'ventas@limpioplus.co',
                'address' => 'Av. Boyaca # 45-21, Bogota',
            ],
            [
                'name' => 'FerreCentro Norte',
                'contact_name' => 'Julian Bernal',
                'phone' => '3114438899',
                'email' => 'comercial@ferrecentro.co',
                'address' => 'Cra. 15 # 102-30, Bogota',
            ],
            [
                'name' => 'Suministros Andinos',
                'contact_name' => 'Laura Mendoza',
                'phone' => '3156677788',
                'email' => 'pedidos@sandinos.co',
                'address' => 'Calle 80 # 22-10, Bogota',
            ],
        ];

        foreach ($supplierRows as $row) {
            $suppliers[$row['name']] = Supplier::query()->updateOrCreate(
                ['condominium_id' => $condominiumId, 'name' => $row['name']],
                [
                    'contact_name' => $row['contact_name'],
                    'phone' => $row['phone'],
                    'email' => $row['email'],
                    'address' => $row['address'],
                    'is_active' => true,
                ]
            );
        }

        $products = [
            [
                'inventory' => 'Cuarto de Aseo',
                'name' => 'Detergente liquido 5L',
                'category' => 'Limpieza',
                'supplier' => 'LimpioPlus SAS',
                'unit_measure' => 'unidad',
                'unit_cost' => 28500,
                'stock' => 22,
                'minimum_stock' => 8,
                'type' => Product::TYPE_CONSUMABLE,
            ],
            [
                'inventory' => 'Cuarto de Aseo',
                'name' => 'Desinfectante multiusos',
                'category' => 'Limpieza',
                'supplier' => 'LimpioPlus SAS',
                'unit_measure' => 'unidad',
                'unit_cost' => 19800,
                'stock' => 18,
                'minimum_stock' => 6,
                'type' => Product::TYPE_CONSUMABLE,
            ],
            [
                'inventory' => 'Cuarto de Herramientas',
                'name' => 'Juego de llaves mixtas',
                'category' => 'Ferreteria',
                'supplier' => 'FerreCentro Norte',
                'unit_measure' => 'set',
                'unit_cost' => 165000,
                'stock' => 2,
                'minimum_stock' => 0,
                'type' => Product::TYPE_ASSET,
                'asset_code' => 'ACT-HERR-001',
                'serial' => 'JSM-2026-0001',
                'location' => 'Cuarto de herramientas - Estante 2',
            ],
            [
                'inventory' => 'Bodega General',
                'name' => 'Resmas carta x500',
                'category' => 'Papeleria',
                'supplier' => 'Suministros Andinos',
                'unit_measure' => 'resma',
                'unit_cost' => 22900,
                'stock' => 14,
                'minimum_stock' => 5,
                'type' => Product::TYPE_CONSUMABLE,
            ],
            [
                'inventory' => 'Bodega General',
                'name' => 'Tablet registro porteria',
                'category' => 'Tecnologia',
                'supplier' => 'Suministros Andinos',
                'unit_measure' => 'unidad',
                'unit_cost' => 850000,
                'stock' => 1,
                'minimum_stock' => 0,
                'type' => Product::TYPE_ASSET,
                'asset_code' => 'ACT-TEC-004',
                'serial' => 'TBL-PORT-2026-04',
                'location' => 'Recepcion principal',
            ],
        ];

        foreach ($products as $productData) {
            Product::query()->updateOrCreate(
                [
                    'inventory_id' => $inventories[$productData['inventory']]->id,
                    'name' => $productData['name'],
                ],
                [
                    'category_id' => $categories[$productData['category']]->id,
                    'supplier_id' => $suppliers[$productData['supplier']]->id,
                    'category' => $productData['category'],
                    'unit_measure' => $productData['unit_measure'],
                    'unit_cost' => $productData['unit_cost'],
                    'stock' => $productData['stock'],
                    'minimum_stock' => $productData['minimum_stock'],
                    'type' => $productData['type'],
                    'asset_code' => $productData['asset_code'] ?? null,
                    'serial' => $productData['serial'] ?? null,
                    'location' => $productData['location'] ?? null,
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedOperatives(int $condominiumId): void
    {
        $roleMap = [
            'Seguridad' => 'Seguridad',
            'Aseo' => 'Aseo',
            'Mantenimiento' => 'Mantenimiento',
        ];

        $roleIds = [];
        foreach ($roleMap as $name) {
            $roleIds[$name] = Role::query()->where('name', $name)->value('id');
        }

        $rows = [
            [
                'full_name' => 'Juan Pablo Cardenas',
                'email' => 'seguridad.noche@pastorita.test',
                'document_number' => '77010001',
                'phone' => '3001110001',
                'role' => 'Seguridad',
                'position' => 'Vigilante turno noche',
                'contract_type' => 'planta',
                'salary' => 1900000,
                'account_type' => 'ahorros',
                'account_number' => '1122334455',
                'eps' => 'Sanitas',
                'arl' => 'Sura',
            ],
            [
                'full_name' => 'Angela Maria Ruiz',
                'email' => 'seguridad.dia@pastorita.test',
                'document_number' => '77010002',
                'phone' => '3001110002',
                'role' => 'Seguridad',
                'position' => 'Vigilante turno dia',
                'contract_type' => 'planta',
                'salary' => 1900000,
                'account_type' => 'ahorros',
                'account_number' => '1122334456',
                'eps' => 'Nueva EPS',
                'arl' => 'Positiva',
            ],
            [
                'full_name' => 'Diana Marcela Gomez',
                'email' => 'aseo.1@pastorita.test',
                'document_number' => '77010003',
                'phone' => '3001110003',
                'role' => 'Aseo',
                'position' => 'Auxiliar de aseo torre A',
                'contract_type' => 'planta',
                'salary' => 1650000,
                'account_type' => 'ahorros',
                'account_number' => '1122334457',
                'eps' => 'Compensar',
                'arl' => 'Colmena',
            ],
            [
                'full_name' => 'Sandra Milena Lara',
                'email' => 'aseo.2@pastorita.test',
                'document_number' => '77010004',
                'phone' => '3001110004',
                'role' => 'Aseo',
                'position' => 'Auxiliar de aseo torre B',
                'contract_type' => 'contratista',
                'salary' => 1580000,
                'account_type' => 'ahorros',
                'account_number' => '1122334458',
                'eps' => 'Famisanar',
                'arl' => 'Bolivar',
            ],
            [
                'full_name' => 'Hector Fabian Nino',
                'email' => 'mantenimiento.1@pastorita.test',
                'document_number' => '77010005',
                'phone' => '3001110005',
                'role' => 'Mantenimiento',
                'position' => 'Tecnico locativo',
                'contract_type' => 'planta',
                'salary' => 2100000,
                'account_type' => 'corriente',
                'account_number' => '5566778899',
                'eps' => 'Sura',
                'arl' => 'Sura',
            ],
        ];

        foreach ($rows as $row) {
            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'full_name' => $row['full_name'],
                    'document_number' => $row['document_number'],
                    'phone' => $row['phone'],
                    'password' => Hash::make('12345678'),
                    'is_active' => true,
                    'is_platform_admin' => false,
                ]
            );

            if (! empty($roleIds[$row['role']])) {
                $user->roles()->syncWithoutDetaching([
                    $roleIds[$row['role']] => ['condominium_id' => $condominiumId],
                ]);
            }

            Operative::query()->updateOrCreate(
                ['user_id' => $user->id, 'condominium_id' => $condominiumId],
                [
                    'position' => $row['position'],
                    'contract_type' => $row['contract_type'],
                    'salary' => $row['salary'],
                    'financial_institution' => 'Bancolombia',
                    'account_type' => $row['account_type'],
                    'account_number' => $row['account_number'],
                    'eps' => $row['eps'] ?? null,
                    'arl' => $row['arl'] ?? null,
                    'contract_start_date' => now()->subMonths(6)->toDateString(),
                    'is_active' => true,
                ]
            );

            $this->syncModulePermissions($user->id, $condominiumId, $row['role']);
        }
    }

    /**
     * @param array<string, Apartment> $apartments
     */
    private function seedResidents(int $condominiumId, array $apartments): void
    {
        $rows = [
            ['full_name' => 'Carlos Alberto Reyes', 'email' => 'residente.a101@pastorita.test', 'document' => '88020001', 'phone' => '3012221001', 'type' => 'propietario', 'apartment' => 'A101'],
            ['full_name' => 'Natalia Patricia Diaz', 'email' => 'residente.a102@pastorita.test', 'document' => '88020002', 'phone' => '3012221002', 'type' => 'arrendatario', 'apartment' => 'A102'],
            ['full_name' => 'Santiago Moreno', 'email' => 'residente.a201@pastorita.test', 'document' => '88020003', 'phone' => '3012221003', 'type' => 'propietario', 'apartment' => 'A201'],
            ['full_name' => 'Alejandra Cepeda', 'email' => 'residente.b101@pastorita.test', 'document' => '88020004', 'phone' => '3012221004', 'type' => 'arrendatario', 'apartment' => 'B101'],
            ['full_name' => 'Luis Fernando Pinto', 'email' => 'residente.b201@pastorita.test', 'document' => '88020005', 'phone' => '3012221005', 'type' => 'propietario', 'apartment' => 'B201'],
            ['full_name' => 'Marcela Bonilla', 'email' => 'residente.b202@pastorita.test', 'document' => '88020006', 'phone' => '3012221006', 'type' => 'arrendatario', 'apartment' => 'B202'],
            ['full_name' => 'Andres Camacho', 'email' => 'residente.n301@pastorita.test', 'document' => '88020007', 'phone' => '3012221007', 'type' => 'arrendatario', 'apartment' => 'N301'],
            ['full_name' => 'Paola Jimenez', 'email' => 'residente.n302@pastorita.test', 'document' => '88020008', 'phone' => '3012221008', 'type' => 'propietario', 'apartment' => 'N302'],
        ];

        foreach ($rows as $row) {
            if (! isset($apartments[$row['apartment']])) {
                continue;
            }

            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'full_name' => $row['full_name'],
                    'document_number' => $row['document'],
                    'phone' => $row['phone'],
                    'password' => Hash::make('12345678'),
                    'is_active' => true,
                    'is_platform_admin' => false,
                ]
            );

            Resident::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'apartment_id' => $apartments[$row['apartment']]->id,
                ],
                [
                    'type' => $row['type'],
                    'is_active' => true,
                ]
            );
        }
    }

    private function syncModulePermissions(int $userId, int $condominiumId, string $roleName): void
    {
        $allowedByRole = match ($roleName) {
            'Seguridad' => ['visits', 'vehicles', 'employee-entries', 'correspondences', 'vehicle-incidents'],
            'Aseo' => ['cleaning'],
            'Mantenimiento' => ['inventory', 'emergencies'],
            default => [],
        };

        foreach (User::AVAILABLE_MODULES as $module) {
            UserModulePermission::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'condominium_id' => $condominiumId,
                    'module' => $module,
                ],
                [
                    'can_view' => in_array($module, $allowedByRole, true),
                ]
            );
        }
    }
}
















