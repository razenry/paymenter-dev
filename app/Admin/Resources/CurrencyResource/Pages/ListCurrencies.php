<?php

namespace App\Admin\Resources\CurrencyResource\Pages;

use App\Admin\Resources\CurrencyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCurrencies extends ListRecords
{
    protected static string $resource = CurrencyResource::class;

    public function mount(): void
    {
        parent::mount();
        
        // If refresh parameter is present, reset table state and reload
        if (request()->has('refresh')) {
            // Reset pagination to first page
            $this->resetTable();
            
            // Force a full page reload to ensure fresh data
            $this->js('window.location.href = "' . CurrencyResource::getUrl('index') . '"');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
