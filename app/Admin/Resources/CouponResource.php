<?php

namespace App\Admin\Resources;

use App\Admin\Resources\CouponResource\Pages\CreateCoupon;
use App\Admin\Resources\CouponResource\Pages\EditCoupon;
use App\Admin\Resources\CouponResource\Pages\ListCoupons;
use App\Admin\Resources\CouponResource\RelationManagers\ServicesRelationManager;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Role;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-coupon-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-coupon-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->maxLength(255)
                    ->unique(static::getModel(), 'code', ignoreRecord: true)
                    ->placeholder('Enter the code of the coupon'),

                Select::make('type')
                    ->label('Type')
                    ->required()
                    ->default('percentage')
                    ->live()
                    ->options([
                        'percentage' => 'Percentage',
                        'fixed' => 'Fixed amount',
                    ])
                    ->placeholder('Select the type of the coupon'),

                Select::make('applies_to')
                    ->label('Applies To')
                    ->required()
                    ->default('all')
                    ->options([
                        'all' => 'Price and Setup Fee',
                        'price' => 'Price only',
                        'setup_fee' => 'Setup Fee only',
                    ]),

                TextInput::make('recurring')
                    ->label('Recurring')
                    ->numeric()
                    ->nullable()
                    ->minValue(0)
                    ->placeholder('How many billing cycles the discount will be applied'),

                TextInput::make('max_uses')
                    ->label('Max Uses')
                    ->numeric()
                    ->minValue(0),

                TextInput::make('max_uses_per_user')
                    ->label('Max Uses Per User')
                    ->numeric()
                    ->minValue(0),

                DatePicker::make('starts_at')
                    ->label('Starts At'),

                DatePicker::make('expires_at')
                    ->label('Expires At'),

                Select::make('allowed_roles')
                    ->label('Allowed Roles')
                    ->multiple()
                    ->options(
                        Role::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                    )
                    ->searchable()
                    ->preload()
                    ->placeholder('Select roles allowed to use this coupon'),

                Select::make('products')
                    ->label('Products')
                    ->relationship('products', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(
                        Product::query()
                            ->with('category') // make sure Product has category() relationship
                            ->orderBy('category_id')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($p) => [
                                $p->id => $p->category ? $p->category->name . ' – ' . $p->name : $p->name,
                            ])
                    )
                    ->placeholder('Select the products'),

                Toggle::make('new_users_only')
                    ->label('New Users Only')
                    ->default(false)
                    ->inline(false)
                    ->reactive()
                    ->disabled(fn (Get $get) => $get('existing_users_only') === true),

                Toggle::make('existing_users_only')
                    ->label('Existing Users Only')
                    ->default(false)
                    ->inline(false)
                    ->reactive()
                    ->disabled(fn (Get $get) => $get('new_users_only') === true),

                Toggle::make('apply_once_only')
                    ->label('Apply Once Only')
                    ->default(false)
                    ->inline(false)
                    ->reactive(),

                // Percentage or fixed base value
                TextInput::make('value')
                    ->label('Value')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->hidden(fn (Get $get) => $get('type') === 'fixed') // HIDE when using coupon_values
                    ->suffix(fn (Get $get) => '%')
                    ->placeholder('Enter the value of the coupon'),

                // Coupon values repeater (for fixed type)
                Repeater::make('couponValues')
                    ->relationship()
                    ->hidden(fn (Get $get) => $get('type') !== 'fixed')
                    ->columns(2)
                    ->schema([
                        Select::make('currency')
                            ->label('Currency')
                            ->required()
                            ->options(
                                Currency::query()
                                    ->orderBy('code')
                                    ->pluck('code', 'code')
                            )
                            ->searchable()
                            ->preload(),

                        TextInput::make('value')
                            ->label('Value')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->addActionLabel('Add Coupon Value')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
            ])
            ->filters([])
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
            ServicesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoupons::route('/'),
            'create' => CreateCoupon::route('/create'),
            'edit' => EditCoupon::route('/{record}/edit'),
        ];
    }
}
