<?php

namespace App\Jobs\Services;

use App\Models\ConfigOption;
use App\Models\ConfigOptionProduct;
use App\Models\Service;
use App\Models\ServiceConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SyncConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
       logger()->info('Starting service sync...');

        $configOptions = ConfigOption::all();
        $configOptionProducts = ConfigOptionProduct::all();
        $services = Service::all();
        $existingConfigs = ServiceConfig::all();

        foreach ($services as $service) {
            $relatedProducts = $configOptionProducts->where('product_id', $service->product_id);
            $relatedConfigIds = $relatedProducts->pluck('config_option_id');
            $relatedConfigs = $configOptions->whereNull('parent_id')->whereIn('id', $relatedConfigIds);

            foreach ($relatedConfigs as $config) {
                $childConfig = $configOptions->firstWhere('parent_id', $config->id);
                if (!$childConfig) continue;

                $alreadyExists = $existingConfigs
                    ->where('config_option_id', $config->id)
                    ->where('configurable_id', $service->id)
                    ->isNotEmpty();

                if ($alreadyExists) continue;

                DB::table('service_configs')->insert([
                    'configurable_type' => Service::class,
                    'configurable_id' => $service->id,
                    'config_option_id' => $config->id,
                    'config_value_id' => $childConfig->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        logger()->info('Service sync completed.');
    }
}
