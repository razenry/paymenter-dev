<?php

namespace App\Admin\Resources\CategoryResource\Pages;

use App\Admin\Resources\CategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(function (DeleteAction $action) {
                if ($this->record->products()->exists() || $this->record->children()->exists()) {
                    Notification::make()
                        ->title('Cannot delete category')
                        ->body('The category has products or children categories.')
                        ->duration(5000)
                        ->icon('ri-error-warning-line')
                        ->danger()
                        ->send();
                    $action->cancel();
                }
            }),
        ];
    }
}
