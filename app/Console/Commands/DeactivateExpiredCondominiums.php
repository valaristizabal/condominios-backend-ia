<?php

namespace App\Console\Commands;

use App\Models\Condominium;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DeactivateExpiredCondominiums extends Command
{
    protected $signature = 'condominiums:deactivate-expired';

    protected $description = 'Desactiva condominios vencidos segun expiration_date.';

    public function handle(): int
    {
        $today = Carbon::today();

        $expiredCondominiums = Condominium::query()
            ->where('is_active', true)
            ->whereNotNull('expiration_date')
            ->whereDate('expiration_date', '<=', $today)
            ->get(['id', 'name', 'tenant_code', 'expiration_date']);

        if ($expiredCondominiums->isEmpty()) {
            $this->info('No hay condominios vencidos para desactivar.');
            return self::SUCCESS;
        }

        Condominium::query()
            ->whereIn('id', $expiredCondominiums->pluck('id'))
            ->update(['is_active' => false]);

        foreach ($expiredCondominiums as $condominium) {
            Log::info('Condominio desactivado por vencimiento.', [
                'condominium_id' => $condominium->id,
                'name' => $condominium->name,
                'tenant_code' => $condominium->tenant_code,
                'expiration_date' => $condominium->expiration_date?->toDateString(),
            ]);
        }

        $count = $expiredCondominiums->count();
        $this->info("Condominios desactivados por vencimiento: {$count}");

        return self::SUCCESS;
    }
}
