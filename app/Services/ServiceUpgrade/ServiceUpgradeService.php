<?php

namespace App\Services\ServiceUpgrade;

use App\Jobs\Server\UpgradeJob;
use App\Models\ConfigOption;
use App\Models\Product;
use App\Models\ServiceUpgrade;
use Exception;
use Illuminate\Support\Facades\DB;

class ServiceUpgradeService
{
    /**
     * Handle the uploaded extension file / perform the service upgrade.
     *
     * @param ServiceUpgrade $serviceUpgrade
     * @return void
     * @throws Exception
     */
    public function handle(ServiceUpgrade $serviceUpgrade)
    {
        DB::transaction(function () use ($serviceUpgrade) {
            $service = $serviceUpgrade->service;
            $oldServiceConfigs = $service->configs;

            // Update status
            $serviceUpgrade->status = ServiceUpgrade::STATUS_COMPLETED;
            $serviceUpgrade->save();

            // Handle stock adjustments
            $this->adjustStock($service, $serviceUpgrade);

            // Apply product/plan changes
            $isProductUpgrade = $this->applyUpgrade($service, $serviceUpgrade);

            // Update configs
            $this->updateConfigs($service, $serviceUpgrade, $oldServiceConfigs, $isProductUpgrade);

            // Recalculate price
            $service->price = $service->calculatePrice();
            $service->save();

            // Dispatch server upgrade job if applicable
            if ($service->product->server) {
                UpgradeJob::dispatch($service);
            }
        });
    }

    /**
     * Adjust product stock for old and new products.
     */
    private function adjustStock($service, ServiceUpgrade $serviceUpgrade): void
    {
        if ($service->product->stock !== null) {
            $service->product->increment('stock', $serviceUpgrade->service->quantity);
        }

        $service->refresh();

        if ($serviceUpgrade->product && $serviceUpgrade->product->stock !== null) {
            $serviceUpgrade->product->decrement('stock', $service->quantity);
        }
    }

    /**
     * Apply the upgrade (plan/product changes) and return whether it's a product upgrade.
     */
    private function applyUpgrade($service, ServiceUpgrade $serviceUpgrade): bool
    {
        $isProductUpgrade = $service->product_id != $serviceUpgrade->product_id && $serviceUpgrade->product_id > 0;

        $service->plan_id = $serviceUpgrade->plan_id;
        $service->product_id = $serviceUpgrade->product_id;
        $service->save();
        $service->refresh();

        return $isProductUpgrade;
    }

    /**
     * Update the service configs.
     */
    private function updateConfigs($service, ServiceUpgrade $serviceUpgrade, $oldConfigs, bool $isProductUpgrade): void
    {
        // Update or create new configs from upgrade
        foreach ($serviceUpgrade->configs as $config) {
            $service->configs()->updateOrCreate(
                ['config_option_id' => $config->config_option_id],
                ['config_value_id' => $config->config_value_id]
            );
        }

        if (!$isProductUpgrade) {
            return;
        }

        // Handle product-specific config changes
        $product = Product::with('allConfigOptions')->findOrFail($serviceUpgrade->product->id);
        $newConfigOptions = $product->allConfigOptions;
        $newConfigOptionIds = $newConfigOptions->pluck('config_option_id')->toArray();

        // Remove configs not present in the new product
        $service->configs()->whereNotIn('config_option_id', $newConfigOptionIds)->delete();

        // Add or retain old values for new product configs
        foreach ($newConfigOptionIds as $optionId) {
            $previous = $oldConfigs->where('config_option_id', $optionId)->first();

            if ($previous) {
                $valueId = $previous->config_value_id;
            } else {
                $newPConfig = ConfigOption::where('parent_id', $optionId)->first();

                if (!$newPConfig) {
                    throw new Exception('The config from product is not configured yet!');
                }

                $valueId = $newPConfig->id;
            }

            $service->configs()->updateOrCreate(
                ['config_option_id' => $optionId],
                ['config_value_id' => $valueId]
            );
        }
    }
}
