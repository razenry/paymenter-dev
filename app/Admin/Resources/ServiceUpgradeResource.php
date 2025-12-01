<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\Services;
use App\Admin\Resources\ServiceUpgradeResource\Pages\CreateServiceUpgrade;
use App\Admin\Resources\ServiceUpgradeResource\Pages\EditServiceUpgrade;
use App\Admin\Resources\ServiceUpgradeResource\Pages\ListServiceUpgrades;
use App\Models\ConfigOption;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Exception;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ServiceUpgradeResource extends Resource
{
    protected static ?string $model = ServiceUpgrade::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog';

    protected static ?string $cluster = Services::class;

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('service_id')
                    ->relationship('service', 'id', fn (Builder $query) => $query->where('status', 'active'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->product->name . ' - ' . $record->plan->name . '  #' . $record->id . ($record->user ? ' (' . $record->user->email . ')' : ''))
                    ->searchable()
                    ->preload()
                    ->disabledOn('edit')
                    ->hint(fn ($get) => $get('service_id') ? new HtmlString('<a href="' . ServiceResource::getUrl('edit', ['record' => $get('service_id')]) . '" target="_blank">Go to Service</a>') : null)
                    ->required(),
                Select::make('product_id')
                    ->label('Product')
                    ->afterStateUpdated(function ($state, callable $set, Component $component) {
                        if (!$state) {
                            return;
                        }

                        /** @var Service|null $service */
                        $service = $component->getRecord();

                        // Only run during edit
                        if (!$service) {
                            return;
                        }

                        $oldProductId = $service->product_id;
                        $newProductId = $state;

                        $isProductUpgrade = $oldProductId != $newProductId;
                        if (!$isProductUpgrade) {
                            return;
                        }

                        // THIS is the fixed part: use collection not relation
                        $oldServiceConfigs = $service->configs;

                        // THIS is fixed: load product first
                        $product = Product::find($newProductId);

                        // This stays EXACTLY your logic
                        $newConfigOptionIds = $product->configOptions()->pluck('config_option_id')->toArray();

                        // Delete configs not in new product
                        $service->configs()
                            ->whereNotIn('config_option_id', $newConfigOptionIds)
                            ->delete();

                        // Create/update new configs
                        foreach ($newConfigOptionIds as $optionId) {
                            $previous = $oldServiceConfigs->where('config_option_id', $optionId)->first();

                            if ($previous) {
                                // Use old value
                                $valueId = $previous->config_value_id;
                            } else {
                                // Get default config
                                $newPConfig = ConfigOption::where('parent_id', $optionId)->first();

                                if (!$newPConfig) {
                                    throw new Exception('The config from product is not configured yet!');
                                }

                                $valueId = $newPConfig->id;
                            }

                            // Create or update config row
                            $service->configs()->updateOrCreate(
                                ['config_option_id' => $optionId],
                                ['config_value_id' => $valueId]
                            );
                        }
                    })
                    ->required()
                    ->options(Product::all()->pluck('name', 'id')->toArray())
                    ->searchable()
                    ->live()
                    ->preload()

                    ->placeholder('Select the product'),
                Select::make('plan_id')
                    ->label('Plan')
                    ->required()
                    ->relationship('plan', 'name', fn (Builder $query, Get $get) => $query->where('priceable_id', $get('product_id'))->where('priceable_type', Product::class))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get) => !$get('product_id'))
                    ->placeholder('Select the plan'),
                Select::make('status')
                    ->label('Status')
                    ->required()
                    ->options([
                        'completed' => 'Completed',
                        'pending' => 'Pending',
                    ])
                    ->default('pending'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Service')
                    ->formatStateUsing(fn ($record) => $record->service_id)
                    ->url(fn ($record) => ServiceResource::getUrl('edit', ['record' => $record->service_id]))
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => 'completed',
                        'warning' => 'pending',
                    ])->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('invoice.id')
                    ->label('Invoice')
                    ->formatStateUsing(fn ($state) => $state ?? '—') // display a dash if null
                    ->url(fn ($record) => $record->invoice_id
                        ? InvoiceResource::getUrl('edit', ['record' => $record->invoice_id])
                        : null
                    ),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceUpgrades::route('/'),
            'create' => CreateServiceUpgrade::route('/create'),
            'edit' => EditServiceUpgrade::route('/{record}/edit'),
        ];
    }
}
