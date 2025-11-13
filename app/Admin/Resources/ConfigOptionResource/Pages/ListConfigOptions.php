<?php

namespace App\Admin\Resources\ConfigOptionResource\Pages;

use App\Admin\Resources\ConfigOptionResource;
use App\Jobs\Services\SyncConfigJob;
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
                        SyncConfigJob::dispatch();
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

}
