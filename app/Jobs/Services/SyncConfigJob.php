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

class SyncConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        logger()->info('Starting service sync...');

        try {
            $configOptions = ConfigOption::all();
            $configOptionProducts = ConfigOptionProduct::all();

            $chunkSize = 50;

            $totalServices = Service::where('status', Service::STATUS_ACTIVE)->count();
            $totalChunks = (int) ceil($totalServices / $chunkSize);

            logger()->info('Service config sync initialized', [
                'job' => static::class,
                'total_services' => $totalServices,
                'chunk_size' => $chunkSize,
                'total_chunks' => $totalChunks,
            ]);

            Service::where('status', Service::STATUS_ACTIVE)
                ->chunkById($chunkSize, function ($services) use ($configOptions, $configOptionProducts) {

                    try {
                        $existingConfigs = ServiceConfig::whereIn(
                            'configurable_id',
                            $services->pluck('id')
                        )->get();

                        logger()->info("Syncing {$services->count()} services.");

                        foreach ($services as $service) {
                            $relatedProducts = $configOptionProducts->where('product_id', $service->product_id);
                            $relatedConfigIds = $relatedProducts->pluck('config_option_id');

                            $relatedConfigs = $configOptions
                                ->whereNull('parent_id')
                                ->whereIn('id', $relatedConfigIds);

                            foreach ($relatedConfigs as $config) {
                                $childConfig = $configOptions->firstWhere('parent_id', $config->id);
                                if (!$childConfig) {
                                    continue;
                                }

                                $alreadyExists = $existingConfigs
                                    ->where('config_option_id', $config->id)
                                    ->where('configurable_id', $service->id)
                                    ->isNotEmpty();

                                if ($alreadyExists) {
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
                            }
                        }
                    } catch (\Throwable $e) {
                        logger()->error('Service sync chunk failed', [
                            'exception' => $e,
                            'service_ids' => $services->pluck('id')->all(),
                        ]);
                    }
                });

        } catch (\Throwable $e) {
            logger()->error('Service sync failed to start', [
                'exception' => $e,
            ]);
        }

        logger()->info('Service sync completed.');
    }
}
