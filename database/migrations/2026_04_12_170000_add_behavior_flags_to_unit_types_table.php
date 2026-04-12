<?php

use App\Modules\Core\Models\Apartment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_types', function (Blueprint $table) {
            $table->boolean('allows_residents')
                ->default(false)
                ->after('name');
            $table->boolean('requires_parent')
                ->default(false)
                ->after('allows_residents');
        });

        DB::table('unit_types')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function (object $unitType): void {
                $normalizedName = Apartment::normalizeUnitTypeName($unitType->name);
                $requiresParentByName = in_array($normalizedName, ['parqueadero', 'deposito'], true);
                $hasChildren = DB::table('apartments')
                    ->where('unit_type_id', $unitType->id)
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('apartments as child_apartments')
                            ->whereColumn('child_apartments.parent_id', 'apartments.id');
                    })
                    ->exists();
                $hasResidents = DB::table('apartments')
                    ->where('unit_type_id', $unitType->id)
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('residents')
                            ->whereColumn('residents.apartment_id', 'apartments.id');
                    })
                    ->exists();
                $hasChildApartments = DB::table('apartments')
                    ->where('unit_type_id', $unitType->id)
                    ->whereNotNull('parent_id')
                    ->exists();

                $requiresParent = $requiresParentByName || $hasChildApartments;
                $allowsResidents = $hasResidents || $hasChildren || ! $requiresParent;

                DB::table('unit_types')
                    ->where('id', $unitType->id)
                    ->update([
                        'allows_residents' => $allowsResidents,
                        'requires_parent' => $requiresParent,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('unit_types', function (Blueprint $table) {
            $table->dropColumn(['allows_residents', 'requires_parent']);
        });
    }
};
