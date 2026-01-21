<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceUpgradeResource;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Jobs\Services\SyncServerConfigJob;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->form(function (DeleteAction $action) {
                    $status = !in_array($this->record->status, [Service::STATUS_PENDING, Service::STATUS_CANCELLED]) && $this->record->product->server_id !== null;
                    if (!$status) {
                        return [];
                    }

                    return [
                        Checkbox::make('deleteExtensionServer')
                            ->label('Also trigger deletion of server')
                            ->default(true),
                    ];
                })
                ->action(function (array $data, Service $record): void {
                    try {
                        if (($data['deleteExtensionServer'] ?? false)) {
                            ExtensionHelper::terminateServer($record);
                        }
                    } catch (Exception $e) {
                        report($e);

                        Notification::make('Error')
                            ->title('Error occured while deleting the related server:')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                    $record->delete();
                }),
            Action::make('upgrade')
                ->label('Upgrade')
                ->disabled(fn (Service $record) => $record->status !== 'active')
                ->url(function (Service $record) {
                    $pendingUpgrade = $record->upgrade()
                        ->where('status', ServiceUpgrade::STATUS_PENDING)
                        ->latest()
                        ->first();

                    return $pendingUpgrade
                        ? ServiceUpgradeResource::getUrl('edit', ['record' => $pendingUpgrade->id])
                        : ServiceUpgradeResource::getUrl('create', ['data' => ['service_id' => $record->id]]);
                }),
            Action::make('syncConfigs')
                ->label('Sync Configs')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Sync Service Configs')
                ->modalDescription('This will sync configuration options for this service only.')
                ->action(function (Service $record, Action $action): void {
                    try {
                        SyncServerConfigJob::dispatch($record->id);

                        Notification::make()
                            ->title('Config sync queued')
                            ->body('Service configuration sync has been queued.')
                            ->success()
                            ->send();

                    } catch (Exception $e) {
                        report($e);

                        Notification::make()
                            ->title('Failed to sync configs')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
            Action::make('changeStatus')
                ->label('Trigger Extension Action')
                ->schema([
                    Select::make('action')
                        ->label('Action')
                        ->options([
                            'create' => 'Create server',
                            'suspend' => 'Suspend server',
                            'unsuspend' => 'Unsuspend server',
                            'terminate' => 'Terminate server',
                            'upgrade' => 'Upgrade server',
                        ])->required(),
                    Checkbox::make('sendNotification')
                        ->label('Send Notification')
                        ->default(false),
                ])
                ->action(function (array $data, Service $record, Action $action): void {
                    try {
                        switch ($data['action']) {
                            case 'create':
                                $sdata = ExtensionHelper::createServer($record);
                                if ($data['sendNotification']) {
                                    NotificationHelper::serverCreatedNotification($record->order->user, $record, $sdata);
                                }
                                break;
                            case 'suspend':
                                $sdata = ExtensionHelper::suspendServer($record);
                                break;
                            case 'unsuspend':
                                $sdata = ExtensionHelper::unsuspendServer($record);
                                break;
                            case 'terminate':
                                $sdata = ExtensionHelper::terminateServer($record);
                                break;
                            case 'upgrade':
                                $sdata = ExtensionHelper::upgradeServer($record);
                                break;
                        }
                    } catch (Exception $e) {
                        if (config('app.debug')) {
                            throw $e;
                        }
                        report($e);
                        Notification::make('Error')
                            ->title('Error occured while triggering the action:')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        $action->halt();
                    }
                    Notification::make('Success')
                        ->title('Action triggered successfully')
                        ->body('The action has been triggered successfully')
                        ->success()
                        ->send();
                })
                ->color('primary')
                ->modalSubmitActionLabel('Trigger'),

            AuditAction::make()->auditChildren([
                'order',
                'invoices',
                'properties',
                'configs',
                'invoiceItems',
            ]),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        if (!$this->record->cancellation()->exists()) {
            return [];
        }

        return [
            ServiceResource\Widgets\CancellationOverview::class,
        ];
    }
}
