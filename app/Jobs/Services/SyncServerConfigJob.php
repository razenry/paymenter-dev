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

class SyncServerConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $serviceId
    ) {}

    public function handle(): void
    {
        logger()->info('Service config sync started', [
            'job' => static::class,
            'service_id' => $this->serviceId,
        ]);

        try {
            $service = Service::where('id', $this->serviceId)
                ->where('status', Service::STATUS_ACTIVE)
                ->first();

            if (!$service) {
                logger()->warning('Service not found or inactive', [
                    'service_id' => $this->serviceId,
                ]);
                return;
            }

            $configOptions = ConfigOption::all();
            $configOptionProducts = ConfigOptionProduct::all();

            $existingConfigs = ServiceConfig::where(
                'configurable_id',
                $service->id
            )->get();

            $relatedConfigIds = $configOptionProducts
                ->where('product_id', $service->product_id)
                ->pluck('config_option_id');

            if ($relatedConfigIds->isEmpty()) {
                logger()->info('No config options linked to product', [
                    'service_id' => $service->id,
                    'product_id' => $service->product_id,
                ]);
                return;
            }

            $inserted = 0;

            $relatedConfigs = $configOptions
                ->whereNull('parent_id')
                ->whereIn('id', $relatedConfigIds);

            foreach ($relatedConfigs as $config) {
                $childConfig = $configOptions
                    ->firstWhere('parent_id', $config->id);

                if (!$childConfig) {
                    logger()->warning('Missing child config option', [
                        'service_id' => $service->id,
                        'config_option_id' => $config->id,
                    ]);
                    continue;
                }

                $exists = $existingConfigs
                    ->where('configurable_id', $service->id)
                    ->where('config_option_id', $config->id)
                    ->isNotEmpty();

                if ($exists) {
                    continue;
                }

                DB::table('service_configs')->insert([
                    'configurable_type' => Service::class,
                    'configurable_id' => $service->id,
                    'config_option_id' => $config->id,
                    'config_value_id' => $childConfig->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $inserted++;
            }

            logger()->info('Service config sync completed', [
                'job' => static::class,
                'service_id' => $service->id,
                'inserted_configs' => $inserted,
            ]);

        } catch (\Throwable $e) {
            logger()->error('Service config sync failed', [
                'job' => static::class,
                'service_id' => $this->serviceId,
                'exception' => $e,
            ]);
        }
    }
}
