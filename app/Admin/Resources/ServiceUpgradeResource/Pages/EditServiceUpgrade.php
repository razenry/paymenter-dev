<?php

namespace App\Admin\Resources\ServiceUpgradeResource\Pages;

use App\Admin\Resources\ServiceUpgradeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceUpgrade extends EditRecord
{
    protected static string $resource = ServiceUpgradeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
