<?php

namespace App\Admin\Resources\ConfigOptionResource\Pages;

use App\Admin\Resources\ConfigOptionResource;
use App\Models\ConfigOption;
use App\Models\ConfigOptionProduct;
use App\Models\Service;
use App\Models\ServiceConfig;
use Exception;
use Filament\Actions;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class EditConfigOption extends EditRecord
{
    protected static string $resource = ConfigOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 🗑️ Delete action with warning
            DeleteAction::make('Delete')
                ->requiresConfirmation()
                ->modalDescription(
                    fn (ConfigOption $record) => $record->serviceConfigs()->exists()
                        ? 'This config option has services connected to it. Deleting it will also delete it from the services it is associated with. Are you sure you want to delete this config option?'
                        : 'Are you sure you want to delete this config option?',
                )
                ->action(function () {
                    $this->record->serviceConfigs()->delete();
                    $this->record->delete();

                    return redirect()->to(ConfigOptionResource::getUrl());
                }),

            // 🔁 Single sync action
            Actions\Action::make('syncSingle')
                ->label('Sync This Option')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        $this->syncSingle($this->record);

                        Notification::make()
                            ->title('Config option synced successfully!')
                            ->success()
                            ->send();
                    } catch (Exception $e) {
                        logger()->error('Single config sync failed', [
                            'id' => $this->record->id,
                            'message' => $e->getMessage(),
                        ]);

                        Notification::make()
                            ->title('Sync failed')
                            ->body('An error occurred while syncing this config. Check logs for details.')
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function syncSingle(ConfigOption $configOption): void
    {
        logger()->debug("Syncing single config option ID {$configOption->id}");

        // Only sync parent options
        if ($configOption->parent_id !== null) {
            logger()->debug("Skipping child config option ID {$configOption->id}");
            return;
        }

        $childConfig = ConfigOption::where('parent_id', $configOption->id)->first();
        if (!$childConfig) {
            logger()->debug("No child found for parent config ID {$configOption->id}");
            return;
        }

        // Find all products related to this config option
        $relatedProducts = ConfigOptionProduct::where('config_option_id', $configOption->id)->get();
        $productIds = $relatedProducts->pluck('product_id');

        if ($productIds->isEmpty()) {
            logger()->debug("No products linked to config option ID {$configOption->id}");
            return;
        }

        // Find all services belonging to those products
        $services = Service::whereIn('product_id', $productIds)->get();

        foreach ($services as $service) {
            $alreadyExists = ServiceConfig::where('config_option_id', $configOption->id)
                ->where('configurable_id', $service->id)
                ->exists();

            if ($alreadyExists) {
                logger()->debug("Config {$configOption->id} already exists for service {$service->id}");
                continue;
            }

            DB::table('service_configs')->insert([
                'configurable_type' => Service::class,
                'configurable_id' => $service->id,
                'config_option_id' => $configOption->id,
                'config_value_id' => $childConfig->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            logger()->debug("Created service config for service {$service->id} (config {$configOption->id})");
        }

        logger()->debug("Finished syncing config option ID {$configOption->id}");
    }
}
