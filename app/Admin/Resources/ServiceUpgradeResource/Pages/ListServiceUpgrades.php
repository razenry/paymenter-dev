<?php

namespace App\Admin\Resources\ServiceUpgradeResource\Pages;


use App\Admin\Resources\ServiceUpgradeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceUpgrades extends ListRecords
{
    protected static string $resource = ServiceUpgradeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
