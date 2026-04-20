<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\Services;
use App\Admin\Components\UserComponent;
use App\Admin\Resources\Common\RelationManagers\PropertiesRelationManager;
use App\Admin\Resources\ServiceResource\Pages\CreateService;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Admin\Resources\ServiceResource\RelationManagers\ConfigOptionsRelationManager;
use App\Admin\Resources\ServiceResource\RelationManagers\InvoicesRelationManager;
use App\Helpers\ExtensionHelper;
use App\Models\ConfigOption;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Service;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-function-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-function-fill';

    public static function getNavigationBadge(): ?string
    {
        return Service::where('status', 'active')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected static ?string $cluster = Services::class;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                        // ALWAYS reload from DB
                        $oldServiceConfigs = $service->configs()->get();

                        $product = Product::with('allConfigOptions')->findOrFail($state);
                        $newConfigOptionIds = $product->allConfigOptions
                            ->pluck('id')
                            ->toArray();

   
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
                UserComponent::make('user_id'),
                Select::make('status')
                    ->label('Status')
                    ->required()
                    ->options([
                        // active, pending, suspended, cancelled
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'suspended' => 'Suspended',
                        'cancelled' => 'Cancelled',
                    ])
                    ->default('pending'),
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->required()
                    ->placeholder('Enter the quantity'),
                DatePicker::make('expires_at')
                    ->label('Expires At')
                    ->required(fn (Get $get) => $get('plan')?->type != 'one-time' && $get('plan')?->type != 'free' && $get('status') !== 'pending')
                    ->placeholder('Select the expiration date'),
                Select::make('coupon_id')
                    ->label('Coupon')
                    ->relationship('coupon', 'code')
                    ->searchable()
                    ->preload()
                    ->placeholder('Select the coupon'),
                Select::make('currency_code')
                    ->options(function (Get $get, ?string $state) {
                        $pricing = collect($get('../../pricing'))->pluck('currency_code');
                        if ($state !== null) {
                            $pricing = $pricing->filter(function ($code) use ($state) {
                                return $code !== $state;
                            });
                        }
                        $pricing = $pricing->filter(function ($code) {
                            return $code !== null;
                        });

                        return Currency::whereNotIn('code', $pricing)->pluck('code', 'code');
                    })
                    ->live()
                    ->default(config('settings.default_currency'))
                    ->required(),
                TextInput::make('price')
                    ->required()
                    ->label('Price')
                    // Suffix based on chosen currency
                    ->prefix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->prefix)
                    ->suffix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->suffix)
                    ->live(onBlur: true)
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->numeric()
                    ->minValue(0)
                    ->hintAction(
                        Action::make('Recalculate Price')
                            ->action(function (Component $component, Service $service) {
                                if ($service) {
                                    Notification::make('Price Recalculated')
                                        ->title('The price has been successfully recalculated')
                                        ->success()
                                        ->send();
                                    // Update the form field
                                    $component->state($service->calculatePrice());
                                }
                            })
                            ->label('Recalculate Price')
                            ->icon('ri-refresh-line')
                    ),
                Select::make('billing_agreement_id')
                    ->label('Billing Agreement')
                    ->relationship('billingAgreement', 'name', fn (Builder $query, Get $get) => $query->where('user_id', $get('user_id')))
                    ->searchable()
                    ->preload()
                    ->placeholder('Select the billing agreement'),
                TextInput::make('subscription_id')
                    ->label('Subscription ID (deprecated)')
                    ->nullable()
                    ->placeholder('Enter the subscription ID')
                    ->hintAction(
                        Action::make('Cancel Subscription ID')
                            ->action(function (Component $component) {
                                if (ExtensionHelper::cancelSubscription($component->getRecord())) {
                                    Notification::make('Subscription Cancelled')
                                        ->title('The subscription has been successfully cancelled')
                                        ->success()
                                        ->send();
                                } else {
                                    Notification::make('Subscription Not Cancelled')
                                        ->title('The subscription could not be cancelled')
                                        ->error()
                                        ->send();
                                }
                                // Update the record to remove the subscription ID
                                $component->getRecord()->update(['subscription_id' => null]);
                            })
                            ->requiresConfirmation()
                            ->label('Cancel Subscription')
                            ->hidden(fn (Component $component) => !$component->getRecord()?->subscription_id),
                    ),

                Toggle::make('disable_termination')
                    ->label('Disable Termination')
                    ->default(false)
                    ->inline(false)
                    ->reactive(),
                Toggle::make('disable_auto_provision')
                    ->label('Disable Auto Provision')
                    ->default(false)
                    ->inline(false)
                    ->reactive(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable(true, fn (Builder $query, string $search) => $query->whereHas('user', fn (Builder $query) => $query->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%"))),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Service $record) => match ($record->status) {
                        'pending' => 'gray',
                        'active' => 'success',
                        'cancelled' => 'danger',
                        'suspended' => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->label('Status')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expires At')
                    ->date()
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'suspended' => 'Suspended',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('user')
                    ->label('User')
                    ->relationship('user', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name . ' (' . $record->email . ')')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('expires_at')
                    ->form([
                        DatePicker::make('expires_from')->label('Expires From'),
                        DatePicker::make('expires_until')->label('Expires Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['expires_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('expires_at', '>=', $date),
                            )
                            ->when(
                                $data['expires_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('expires_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('id', 'desc');
            })
            ->recordActions([
                EditAction::make(),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            InvoicesRelationManager::class,
            PropertiesRelationManager::class,
            ConfigOptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListService::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
