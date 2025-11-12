<?php

namespace App\Admin\Resources\ConfigOptionResource\Pages;

use App\Admin\Resources\ConfigOptionResource;
use App\Models\ConfigOption;
use App\Models\ConfigOptionProduct;
use App\Models\Service;
use App\Models\ServiceConfig;
use Exception;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListConfigOptions extends ListRecords
{
    protected static string $resource = ConfigOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('syncServices')
                ->label('Sync Services')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        $this->syncServices();

                        Notification::make()
                            ->title('Services synced successfully!')
                            ->success()
                            ->send();
                    } catch (Exception $e) {
                        logger()->error('Sync Services failed', [
                            'message' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        Notification::make()
                            ->title('Sync failed')
                            ->body('An unexpected error occurred. Check logs for details.')
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function syncServices(): void
    {
        logger()->debug('Syncing services...');

        $configOptions = ConfigOption::all();
        $configOptionProducts = ConfigOptionProduct::all();
        $services = Service::all();
        $existingConfigs = ServiceConfig::all();

        foreach ($services as $service) {
            $relatedProducts = $configOptionProducts
                ->where('product_id', $service->product_id);

            $relatedConfigIds = $relatedProducts->pluck('config_option_id');

            $relatedConfigs = $configOptions
                ->whereNull('parent_id')
                ->whereIn('id', $relatedConfigIds);

            foreach ($relatedConfigs as $config) {
                $childConfig = $configOptions->firstWhere('parent_id', $config->id);

                if (!$childConfig) {
                    logger()->debug("No child config found for parent {$config->id}");
                    continue;
                }

                $alreadyExists = $existingConfigs
                    ->where('config_option_id', $config->id)
                    ->where('configurable_id', $service->id)
                    ->isNotEmpty();

                if ($alreadyExists) {
                    logger()->debug("Config for option {$config->id} already exists for service {$service->id}");
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


                logger()->debug("Created service config for service {$service->id}, option {$config->id}");
            }

            logger()->debug("Service {$service->id} processed with {$relatedConfigs->count()} related config(s).");
        }

        logger()->debug('Service sync completed.');
    }
}
