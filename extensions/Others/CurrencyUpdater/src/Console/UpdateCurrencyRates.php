<?php

namespace Paymenter\Extensions\Others\CurrencyUpdater\src\Console;

use Illuminate\Console\Command;
use Paymenter\Extensions\Others\CurrencyUpdater\src\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;

class UpdateCurrencyRates extends Command
{
    protected $signature = 'currency:update';
    protected $description = 'Fetch and store latest exchange rates';

    public function handle(): int
    {
        $this->info('Fetching exchange rates...');

        try {
            $currencyCodes = \App\Models\Currency::pluck('code')->map(fn($c) => strtoupper($c))->toArray() ?: ['USD'];
            $response = Http::timeout(10)->get('https://api.frankfurter.app/latest', ['to' => implode(',', $currencyCodes)]);
            
            if ($response->successful()) {
                $rates = $response->json()['rates'] ?? [];
                $updated = 0;
                
                foreach ($rates as $code => $rate) {
                    if (is_numeric($rate) && $rate > 0) {
                        // Don't update the default currency rate - it should always be 1
                        $isDefault = ExchangeRate::where('code', $code)->where('is_default', true)->exists();
                        $finalRate = $isDefault ? 1.00000000 : $rate;
                        
                        ExchangeRate::updateOrCreate(['code' => $code], ['rate' => $finalRate]);
                        $updated++;
                    }
                }
                
                $this->info("Updated {$updated} exchange rates successfully.");
                return self::SUCCESS;
            } else {
                $this->error('API request failed');
                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Failed to update rates: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}