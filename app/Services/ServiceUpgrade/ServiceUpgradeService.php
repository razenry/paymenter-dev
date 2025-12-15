<?php

namespace App\Services\ServiceUpgrade;

use App\Jobs\Server\UpgradeJob;
use App\Models\ConfigOption;
use App\Models\ServiceUpgrade;
use Exception;
use Illuminate\Support\Facades\DB;

class ServiceUpgradeService
{
    // TODO: RESET PRODUCT CONFIG EACH PRODUCT UPGRADE.
    /**
     * Handle the uploaded extension file.
     * The added file is always a zip file.
     *
     * @return void
     */
    public function handle(ServiceUpgrade $serviceUpgrade)
    {
        return DB::transaction(function () use ($serviceUpgrade) {
            $serviceUpgrade->status = ServiceUpgrade::STATUS_COMPLETED;
            $serviceUpgrade->save();

            // Check if old product stock should be increased
            $service = $serviceUpgrade->service;
            if ($service->product->stock !== null) {
                $serviceUpgrade->service->product->increment('stock', $serviceUpgrade->service->quantity);
            }

            $oldServiceConfigs = $service->configs;
            $isProductUpgrade = $service->product_id != $serviceUpgrade->product_id && $serviceUpgrade->product_id > 0;
            $service->plan_id = $serviceUpgrade->plan_id;
            $service->product_id = $serviceUpgrade->product_id;
            $service->save();

            $service->refresh();

            // Decrease stock of new product if applicable
            if ($service->product->stock !== null) {
                $service->product->decrement('stock', $service->quantity);
            }

            foreach ($serviceUpgrade->configs as $config) {
                $service->configs()->updateOrCreate(
                    ['config_option_id' => $config->config_option_id],
                    ['config_value_id' => $config->config_value_id]
                );
            }

            if ($isProductUpgrade) {
                $newConfigOptions = $serviceUpgrade->product
                    ->allConfigOptions();
                $newConfigOptionIds = $newConfigOptions->pluck('config_option_id')->toArray();

                // Delete configs that do NOT exist on the new product
                $service->configs()
                    ->whereNotIn('config_option_id', $newConfigOptionIds)
                    ->delete();

                // Create/update new configs
                foreach ($newConfigOptionIds as $optionId) {
                    $previous = $oldServiceConfigs->where('config_option_id', $optionId)->first();

                    if ($previous) {
                        // Use old value
                        $valueId = $previous->config_value_id;
                        // Save config row

                    } else {
                        $newPConfig = ConfigOption::where('parent_id', $optionId)->first();

                        if(!$newPConfig) {
                            throw new Exception("The config from product is not configured yet!");
                        }

                        $valueId = $newPConfig->id; 
                    }

                    $service->configs()->updateOrCreate(
                        ['config_option_id' => $optionId],
                        ['config_value_id' => $valueId]
                    );
                }
            }

            $service->refresh();

            $service->price = $service->calculatePrice();
            $service->save();

            if ($service->product->server) {
                UpgradeJob::dispatch($service);
            }
        });
    }
}
