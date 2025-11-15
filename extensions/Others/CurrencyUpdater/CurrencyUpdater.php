<?php

namespace Paymenter\Extensions\Others\CurrencyUpdater;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\View;

#[ExtensionMeta(
    name: 'CurrencyUpdater',
    description: 'Fetch FX Rates Automatically.',
    version: '1.0',
    author: 'DigiDome'
)]
class CurrencyUpdater extends Extension
{
    public function installed(): void
    {
        // no-op
    }

    public function enabled(): void
    {
        Artisan::call('migrate', [
            '--path'  => 'extensions/Others/CurrencyUpdater/database/migrations',
            '--force' => true,
        ]);
    }

    public function disabled(): void
    {
        // keep audit/log tables
    }

    public function boot(): void
    {
        View::addNamespace('currency-updater', __DIR__ . '/resources/views');

        $this->app->afterResolving(\Filament\Panel::class, function (\Filament\Panel $panel) {
            if ($panel->getId() === 'admin') {
                $panel->pages([
                    \Paymenter\Extensions\Others\CurrencyUpdater\Admin\Pages\ExchangeRates::class,
                ]);
            }
        });

        $this->commands([
            \Paymenter\Extensions\Others\CurrencyUpdater\src\Console\UpdateCurrencyRates::class,
        ]);
    }

    public function getConfig($values = []): array
    {
        return [];
    }
}