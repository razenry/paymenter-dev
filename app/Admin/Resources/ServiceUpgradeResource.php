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
use App\Models\Plan;
use Exception;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
                    ->relationship('service', 'id', fn(Builder $query) => $query->where('status', 'active'))
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->product->name . ' - ' . $record->plan->name . '  #' . $record->id . ($record->user ? ' (' . $record->user->email . ')' : ''))
                    ->searchable()
                    ->preload()
                    ->disabledOn('edit')
                    ->default(fn() => request('service_id') ?? request('data.service_id'))
                    ->live()
                    ->hint(fn($get) => $get('service_id') ? new HtmlString('<a href="' . ServiceResource::getUrl('edit', ['record' => $get('service_id')]) . '" target="_blank">Go to Service</a>') : null)
                    ->required(),
                Select::make('product_id')
                    ->label('Product')
                    ->afterStateUpdated(function ($state, callable $set, Get $get, Component $component) {
                        if (!$state) {
                            return;
                        }

                        /** @var Service|null $actualService */
                        $actualService = Service::find($get('service_id'));

                        if (!$actualService) {
                            return;
                        }

                        $oldProductId = $actualService->product_id;
                        $newProductId = $state;

                        $isProductUpgrade = $oldProductId != $newProductId;
                        if (!$isProductUpgrade) {
                            return;
                        }

                        // THIS is the fixed part: use collection not relation
                        $oldServiceConfigs = $actualService->configs;

                        // This stays EXACTLY your logic
                        $product = Product::with('allConfigOptions')->findOrFail($state);
                        $newConfigOptionIds = $product->allConfigOptions
                            ->pluck('id')
                            ->toArray();

                        // Delete configs not in new product
                        $actualService->configs()
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
                            $actualService->configs()->updateOrCreate(
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
                    ->relationship('plan', 'name', fn(Builder $query, Get $get) => $query->where('priceable_id', $get('product_id'))->where('priceable_type', Product::class))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->disabled(fn(Get $get) => !$get('product_id'))
                    ->placeholder('Select the plan'),
                Select::make('status')
                    ->label('Status')
                    ->required()
                    ->options([
                        'completed' => 'Completed',
                        'pending' => 'Pending',
                    ])
                    ->default('pending'),

                Section::make('Upgrade Preview')
                    ->description('Review the details of this upgrade before proceeding.')
                    ->collapsible()
                    ->schema([
                        Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(function (Get $get) {
                                $serviceId = $get('service_id');
                                $productId = $get('product_id');
                                $planId = $get('plan_id');

                                if (!$serviceId || !$productId || !$planId) {
                                    return new HtmlString('<div class="text-sm text-gray-500 italic p-4">Please select a service, product, and plan to see the preview.</div>');
                                }

                                try {
                                    $service = Service::find($serviceId);
                                    $targetProduct = Product::find($productId);
                                    $targetPlan = Plan::find($planId);

                                    if (!$service || !$targetProduct || !$targetPlan) {
                                        return new HtmlString('<div class="text-sm text-danger-500 italic p-4">Invalid selection.</div>');
                                    }

                                    // Create a temporary ServiceUpgrade object for calculation
                                    $upgrade = new ServiceUpgrade([
                                        'service_id' => $serviceId,
                                        'product_id' => $productId,
                                        'plan_id' => $planId,
                                    ]);
                                    $upgrade->setRelation('service', $service);
                                    $upgrade->setRelation('product', $targetProduct);
                                    $upgrade->setRelation('plan', $targetPlan);

                                    $price = $upgrade->calculatePrice();
                                    $currency = $service->currency;

                                    return new HtmlString('
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <!-- Current Service -->
                                            <div class="p-4 rounded-xl border border-gray-100 dark:border-white/10 bg-gray-50/50 dark:bg-white/5 shadow-sm">
                                                <div class="flex items-center gap-2 mb-3">
                                                    <span class="p-2 rounded-lg bg-gray-200 dark:bg-gray-700"><i class="ri-history-line"></i></span>
                                                    <h4 class="font-bold text-gray-600 dark:text-gray-400 uppercase text-xs tracking-wider">Current Service</h4>
                                                </div>
                                                <div class="space-y-1">
                                                    <p class="text-lg font-extrabold">' . $service->product->name . '</p>
                                                    <p class="text-sm text-gray-500">' . $service->plan->name . '</p>
                                                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-white/5">
                                                        <p class="text-xs text-gray-400 uppercase">Paid amount</p>
                                                        <p class="font-mono text-gray-600 dark:text-gray-300">' . $service->formattedPrice . '</p>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Target Service -->
                                            <div class="p-4 rounded-xl border border-primary-500/20 bg-primary-500/5 shadow-sm overflow-hidden relative">
                                                <div class="absolute top-0 right-0 p-2 opacity-5 scale-150 rotate-12"><i class="ri-rocket-line text-6xl"></i></div>
                                                <div class="flex items-center gap-2 mb-3">
                                                    <span class="p-2 rounded-lg bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400"><i class="ri-rocket-line"></i></span>
                                                    <h4 class="font-bold text-primary-600 dark:text-primary-400 uppercase text-xs tracking-wider">Target Upgrade</h4>
                                                </div>
                                                <div class="space-y-1 relative z-10">
                                                    <p class="text-lg font-extrabold text-primary-700 dark:text-primary-300">' . $targetProduct->name . '</p>
                                                    <p class="text-sm text-gray-500">' . $targetPlan->name . '</p>
                                                    <div class="mt-3 pt-3 border-t border-primary-500/10">
                                                        <p class="text-xs text-primary-400 uppercase">Base Price</p>
                                                        <p class="font-mono text-primary-600 dark:text-primary-300">' . $currency->prefix . number_format($targetProduct->price($targetPlan->id, null, null, $service->currency_code)->price, 2) . $currency->suffix . '</p>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Upgrade Summary -->
                                            <div class="md:col-span-2 mt-2 p-6 rounded-2xl bg-gradient-to-br from-primary-500 to-indigo-600 text-white shadow-xl shadow-primary-500/20">
                                                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                                    <div>
                                                        <h3 class="text-sm font-bold uppercase tracking-widest opacity-80 mb-1 leading-tight">Total Upgrade Cost</h3>
                                                        <p class="text-xs opacity-70 italic max-w-sm">Prorated based on remaining service time (' . (max(0, floor($service->expires_at?->diffInDays(now())) * -1)) . ' days left)</p>
                                                    </div>
                                                    <div class="text-center md:text-right">
                                                        <span class="text-3xl md:text-5xl font-black tracking-tighter">' . $currency->prefix . number_format($price->price, 2) . $currency->suffix . '</span>
                                                        <div class="mt-1">
                                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-white/20 uppercase tracking-tighter">Due Now</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    ');
                                } catch (\Exception $e) {
                                    return new HtmlString('<div class="text-sm text-danger-500 italic p-4">Error calculating preview: ' . $e->getMessage() . '</div>');
                                }
                            })
                            ->live()
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Service')
                    ->formatStateUsing(fn($record) => $record->service_id)
                    ->url(fn($record) => ServiceResource::getUrl('edit', ['record' => $record->service_id]))
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
                    ])->formatStateUsing(fn(string $state) => ucfirst($state)),
                TextColumn::make('invoice.id')
                    ->label('Invoice')
                    ->formatStateUsing(fn($state) => $state ?? '—') // display a dash if null
                    ->url(
                        fn($record) => $record->invoice_id
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
