<?php

namespace Paymenter\Extensions\Others\CurrencyUpdater\Admin\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\CurrencyUpdater\src\Models\ExchangeRate;

class ExchangeRates extends Page
{
    // Use method, not static property (compat with Filament versions)
    public function getView(): string
    {
        return 'currency-updater::exchange-rates';
    }

    /** Livewire state (AJAX-bound) */
    public string $apiUrl    = 'https://v6.exchangerate-api.com/v6/YOUR_API_KEY/latest/';
    public int    $timeout   = 8;
    public string $activeTab = 'rates';
    public int    $refreshKey = 0;

    /** Navigation */
    public static function getNavigationIcon(): string { return 'heroicon-o-currency-dollar'; }
    public static function getNavigationLabel(): string { return 'Exchange Rates'; }
    public static function getNavigationGroup(): ?string { return 'Configuration'; }
    public static function getNavigationSort(): ?int { return 1; }
    public function getTitle(): string { return 'Exchange Rates'; }

    /** Lifecycle */
    public function mount(): void
    {
        $this->ensureRatesExist();
        $this->loadApiConfig(); // may load from DB/file; sanitize inside

        // Defensive defaults
        $this->apiUrl  = $this->sanitizeUrl($this->apiUrl) ?: 'https://v6.exchangerate-api.com/v6/YOUR_API_KEY/latest/';
        $this->timeout = ($this->timeout >= 1 && $this->timeout <= 30) ? $this->timeout : 8;
        $this->activeTab = in_array($this->activeTab, ['rates','config'], true) ? $this->activeTab : 'rates';
    }

    /** Tabs */
    public function switchTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['rates','config'], true) ? $tab : 'rates';
    }

    /** Livewire update hooks (sanitize inputs) */
    public function updatedApiUrl($value): void
    {
        $this->apiUrl = $this->sanitizeUrl($value) ?: 'https://v6.exchangerate-api.com/v6/YOUR_API_KEY/latest/';
    }

    public function updatedTimeout($value): void
    {
        $v = (int)$value;
        $this->timeout = max(1, min(30, $v ?: 8));
    }

    /** Inline edits for rate rows */
    public function updateRate($id, $field, $value): void
    {
        $allowed = ['rate','enabled','code'];
        if (!in_array($field, $allowed, true)) return;

        $row = ExchangeRate::find($id);
        if (!$row) return;

        if ($field === 'rate') {
            $value = (float)$value;
            if ($value <= 0) return;
        } elseif ($field === 'enabled') {
            $value = (bool)$value;
        } elseif ($field === 'code') {
            $value = strtoupper((string)$value);
            $value = $this->sanitizeUtf8($value);
            if ($value === '') return;
        }

        $row->update([$field => $value]);
        $this->dispatch('$refresh');
    }

    public function setDefault($id): void
    {
        ExchangeRate::where('is_default', true)->update(['is_default' => false]);
        ExchangeRate::where('id', $id)->update(['is_default' => true, 'rate' => 1.0]);
        $this->dispatch('$refresh');
    }

    /** Only Paymenter-enabled currencies appear */
    public function getRates()
    {
        $enabledCodes = $this->getEnabledCurrencyCodes();
        return ExchangeRate::whereIn('code', $enabledCodes)->orderBy('code')->get();
    }

    public function saveRates(): void
    {
        Notification::make()->title('Success')->body('Exchange rates saved successfully!')->success()->send();
    }


    /** Save API config (AJAX) */
    public function saveApiConfig(): void
    {
        try {
            $url = $this->sanitizeUrl($this->apiUrl);
            if (!$url) {
                throw new \InvalidArgumentException('API URL must be a valid http/https URL.');
            }
            $to = (int)$this->timeout;
            if ($to < 1 || $to > 30) {
                throw new \InvalidArgumentException('Timeout must be between 1 and 30 seconds.');
            }

            $this->writeSetting('currency_updater.api_url', $url);
            $this->writeSetting('currency_updater.timeout', (string)$to);

            Notification::make()->title('Success')->body('API configuration saved.')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body('Failed to save API configuration: '.$e->getMessage())->danger()->send();
        }
    }

    /** Fetch Latest Rates (AJAX) */
    public function fetchRates(): void
    {
        try {
            // Determine default/base
            $default = ExchangeRate::where('is_default', true)->first();
            $base = $default ? strtoupper($default->code) : 'USD';

            $enabledCodes = $this->getEnabledCurrencyCodes();
            $providerRates = $this->fetchFromProvider($this->apiUrl, $base);

            $rows = ExchangeRate::whereIn('code', $enabledCodes)
                ->where('enabled', true)
                ->get()
                ->keyBy(fn($r) => strtoupper($r->code));

            $updated = 0;
            foreach ($rows as $code => $row) {
                if ($code === $base) {
                    if ((float)$row->rate !== 1.0) { 
                        $row->rate = 1.0; 
                        $row->save(); 
                        $updated++;
                    }
                    continue;
                }
                
                if (isset($providerRates[$code])) {
                    $val = (float)$providerRates[$code];
                    
                    if ($val > 0 && (float)$row->rate !== $val) {
                        $row->rate = $val;
                        $row->save();
                        $updated++;
                    }
                }
            }

            Notification::make()->title('Success')->body("Fetched latest rates (base {$base}). Updated {$updated} rows.")->success()->send();
            $this->dispatch('refresh-rates');
        } catch (\Throwable $e) {
            \Log::error("Failed to fetch rates: " . $e->getMessage());
            Notification::make()->title('Error')->body('Failed to fetch rates: ' . $e->getMessage())->danger()->send();
        }
    }

    /**
     * Apply to Products (AJAX)
     * - Base product currency stays unchanged.
     * - Update per-currency rows using base price in default currency.
     * - Restricted to Paymenter-enabled currencies.
     */
    public function applyToProducts(): void
    {
        try {
            $enabledCodes = $this->getEnabledCurrencyCodes(); // UPPER
            $rates = ExchangeRate::whereIn('code', $enabledCodes)
                ->where('enabled', true)
                ->get()
                ->keyBy(fn($r) => strtoupper($r->code));

            $default = $rates->firstWhere('is_default', true);
            if (!$default) throw new \Exception('No default currency marked in exchange_rates.');
            $baseCode = strtoupper($default->code);

            // Map currency id <-> code if needed
            $codeById = [];
            $idByCode = [];
            try {
                if (class_exists('\App\Models\Currency')) {
                    $codeById = \App\Models\Currency::all()
                        ->mapWithKeys(fn($c) => [$c->id => strtoupper($c->code)])
                        ->toArray();
                    $idByCode = array_flip($codeById);
                }
            } catch (\Exception $e) {
                // Currency model not available, continue without mapping
            }

            // Targets = enabled ∩ ours, excluding base
            $targets = array_values(array_filter($enabledCodes, fn($c) => $c !== $baseCode && isset($rates[$c])));

            // Price tables with (parent_id, currency[_code|_id], price)
            $models = [
                'App\\Models\\ProductPrice',
                'App\\Models\\PlanPrice',
                'App\\Models\\Price',
            ];

            $updated = 0; $scanned = 0;
            
            foreach ($models as $modelClass) {
                if (!class_exists($modelClass)) continue;

                $model   = new $modelClass;
                $table   = $model->getTable();
                $columns = Schema::getColumnListing($table);

                $priceCol    = $this->findPriceColumn($columns);
                $currencyCol = $this->findCurrencyColumn($columns);
                $parentCol   = $this->findParentIdColumn($columns);

                if (!$priceCol || !$currencyCol || !$parentCol) continue;

                $usesId = ($currencyCol === 'currency_id');

                // Pull all rows so we can find base rows case-insensitively
                $rows = DB::table($table)->select($parentCol, $priceCol, $currencyCol)->get();

                // Group by parent
                $byParent = [];
                foreach ($rows as $r) {
                    $p = $r->{$parentCol};
                    $byParent[$p][] = $r;
                }

                foreach ($byParent as $pid => $group) {
                    // find base row (default currency) in this group
                    $baseRow = null;
                    foreach ($group as $r) {
                        $rowCode = $usesId
                            ? ($codeById[$r->{$currencyCol}] ?? null)
                            : strtoupper((string)$r->{$currencyCol});
                        if ($rowCode === $baseCode) { $baseRow = $r; break; }
                    }
                    if (!$baseRow) continue;

                    $basePrice = (float)$baseRow->{$priceCol};
                    if ($basePrice <= 0) continue;

                    foreach ($targets as $code) {
                        $rate = $rates[$code] ?? null;
                        if (!$rate) continue;

                        $newPrice = round($basePrice * (float)$rate->rate, 2);

                        if ($usesId) {
                            $targetId = $idByCode[$code] ?? null;
                            if (!$targetId) continue;
                            DB::table($table)->updateOrInsert(
                                [$parentCol => $pid, $currencyCol => $targetId],
                                [$priceCol  => $newPrice]
                            );
                        } else {
                            DB::table($table)->updateOrInsert(
                                [$parentCol => $pid, $currencyCol => $code],
                                [$priceCol  => $newPrice]
                            );
                        }
                $updated++;
                    }

                    $scanned++;
                }
            }

            Notification::make()->title('Success')->body("Updated {$updated} currency price rows across {$scanned} base rows.")->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error')->body('Failed to apply rates: ' . $e->getMessage())->danger()->send();
        }
    }

    /** Provider fetch & normalize */
    private function fetchFromProvider(string $apiUrl, string $base): array
    {
        $apiUrl = $this->sanitizeUrl($apiUrl) ?: 'https://v6.exchangerate-api.com/v6/YOUR_API_KEY/latest/';
        $base   = strtoupper($base);

        // ExchangeRate-API v6: https://v6.exchangerate-api.com/v6/{KEY}/latest/{BASE}
        if (stripos($apiUrl, 'exchangerate-api.com') !== false) {
            $u = rtrim($apiUrl, '/');
            if (preg_match('#/latest/?$#i', $u)) {
                $u .= '/' . $base; // auto-append base
            }
            
            $resp = Http::timeout($this->timeout)->get($u);
            if (!$resp->ok()) throw new \Exception('Provider returned ' . $resp->status());
            $j = $this->parseJsonResponse($resp);
            
            if (!isset($j['conversion_rates']) || !is_array($j['conversion_rates'])) {
                throw new \Exception('Unexpected ExchangeRate-API payload - no conversion_rates found');
            }
            $rates = array_change_key_case($j['conversion_rates'], CASE_UPPER);
            return $rates; // 1 BASE = rate CODE
        }

        // Frankfurter: https://api.frankfurter.app/latest?from=USD
        if (stripos($apiUrl, 'frankfurter.app') !== false) {
            $resp = Http::timeout($this->timeout)->get($apiUrl, ['from' => $base]);
            if (!$resp->ok()) throw new \Exception('Provider returned ' . $resp->status());
            $j = $this->parseJsonResponse($resp);
            
            if (!isset($j['rates']) || !is_array($j['rates'])) {
                throw new \Exception('Unexpected Frankfurter payload - no rates found');
            }
            $rates = array_change_key_case($j['rates'], CASE_UPPER);
            $rates[$base] = 1.0;
            return $rates;
        }

        // Generic fallback: try ?base or ?from, and {BASE} token replacement
        $u = str_replace(['{BASE}','{base}','<BASE>'], $base, $apiUrl);
        $resp = Http::timeout($this->timeout)->get($u, ['base' => $base, 'from' => $base]);
        if (!$resp->ok()) throw new \Exception('Provider returned ' . $resp->status());
        $j = $this->parseJsonResponse($resp);
        if (isset($j['rates']) && is_array($j['rates'])) {
            $rates = array_change_key_case($j['rates'], CASE_UPPER);
            $rates[$base] = $rates[$base] ?? 1.0;
            return $rates;
        }
        if (isset($j['conversion_rates']) && is_array($j['conversion_rates'])) {
            return array_change_key_case($j['conversion_rates'], CASE_UPPER);
        }
        throw new \Exception('Unknown provider format');
    }

    /** Parse JSON response with UTF-8 encoding handling */
    private function parseJsonResponse($response): array
    {
        $body = $response->body();
        
        // Clean the response body to handle UTF-8 issues
        $cleanBody = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        if ($cleanBody === false) {
            $cleanBody = utf8_encode($body);
        }
        
        // Remove any BOM or control characters
        $cleanBody = preg_replace('/[\x00-\x1F\x7F]/', '', $cleanBody);
        
        $json = json_decode($cleanBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse API response JSON: ' . json_last_error_msg() . ' - Response: ' . substr($cleanBody, 0, 200));
        }
        
        return $json;
    }

    /** Settings persistence — DB (settings table) or JSON file fallback */
    private function loadApiConfig(): void
    {
        $api = $this->readSetting('currency_updater.api_url');
        $to  = $this->readSetting('currency_updater.timeout');

        if (is_string($api) && $api !== '') $this->apiUrl = $this->sanitizeUrl($api) ?: $this->apiUrl;
        if (is_string($to) && $to !== '' && ctype_digit((string)$to)) {
            $this->timeout = max(1, min(30, (int)$to));
        }
    }

    private function readSetting(string $key, ?string $default = null): ?string
    {
        try {
            if (Schema::hasTable('settings')) {
                $keyCol = Schema::hasColumn('settings', 'key') ? 'key' : (Schema::hasColumn('settings', 'name') ? 'name' : null);
                $valCol = Schema::hasColumn('settings', 'value') ? 'value' : null;
                if ($keyCol && $valCol) {
                    $val = DB::table('settings')->where($keyCol, $key)->value($valCol);
                    if (is_string($val) && $val !== '') {
                        return $this->sanitizeUtf8($val);
                    }
                }
            }
            // file fallback
            $path = storage_path('app/currency_updater.json');
            if (is_file($path)) {
                $raw = (string)file_get_contents($path);
                $raw = $this->sanitizeUtf8($raw);
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data[$key]) && $data[$key] !== '') {
                    return $this->sanitizeUtf8((string)$data[$key]);
                }
            }
        } catch (\Throwable $e) {}
        return $default;
    }

    private function writeSetting(string $key, string $value): void
    {
        $value = $this->sanitizeUtf8($value);

        // Prefer DB (PDO/parameterized)
        if (Schema::hasTable('settings')) {
            $keyCol = Schema::hasColumn('settings', 'key') ? 'key' : (Schema::hasColumn('settings', 'name') ? 'name' : null);
            $valCol = Schema::hasColumn('settings', 'value') ? 'value' : null;
            if ($keyCol && $valCol) {
                DB::table('settings')->updateOrInsert([$keyCol => $key], [$valCol => $value]);
                return;
            }
        }

        // Fallback: JSON file (atomic write)
        $path = storage_path('app/currency_updater.json');
        $data = [];
        if (is_file($path)) {
            $raw = $this->sanitizeUtf8((string)file_get_contents($path));
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $data = $decoded;
        }
        $data[$key] = $value;
        $tmp = $path.'.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
        @rename($tmp, $path);
    }

    /** Sanitizers */
    private function sanitizeUtf8($val): string
    {
        $s = (string)$val;
        // Normalize newlines
        $s = str_replace(["\r\n","\r"], "\n", $s);
        // Remove control chars except \n and \t
        $s = preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? $s;
        // Ensure valid UTF-8; drop invalid bytes
        if (!mb_check_encoding($s, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
            if ($converted !== false) {
                $s = $converted;
                                } else {
                // Last resort
                $s = utf8_encode($s);
            }
        }
        // Trim and cap length (safety)
        $s = trim($s);
        if (strlen($s) > 4096) {
            $s = substr($s, 0, 4096);
        }
        return $s;
    }

    private function sanitizeUrl($url): ?string
    {
        $s = $this->sanitizeUtf8((string)$url);
        if ($s === '') return null;
        if (!preg_match('#^https?://#i', $s)) return null;
        if (filter_var($s, FILTER_VALIDATE_URL) === false) return null;
        return $s;
    }

    /** Helpers (columns & enabled currencies) */
    private function getEnabledCurrencyCodes(): array
    {
        $out = [];
        
        try {
            // Try to get currencies from Paymenter's Currency model
            if (class_exists('\App\Models\Currency')) {
                foreach (\App\Models\Currency::all() as $c) {
                    $flag = null;
                    foreach (['enabled','is_enabled','active','status'] as $f) {
                        if (isset($c->$f)) { $flag = (bool)$c->$f; break; }
                    }
                    if ($flag === null) $flag = true; // assume enabled if no flag exists
                    if ($flag && !empty($c->code)) {
                        $out[] = strtoupper($this->sanitizeUtf8($c->code));
                            }
                        }
                    } else {
                // Fallback: try to get currencies from our own exchange_rates table
                $existingRates = ExchangeRate::where('enabled', true)->get();
                foreach ($existingRates as $rate) {
                    if (!empty($rate->code)) {
                        $out[] = strtoupper($this->sanitizeUtf8($rate->code));
                    }
                }
            }
        } catch (\Exception $e) {
            // If all else fails, use our existing exchange rates
            $existingRates = ExchangeRate::where('enabled', true)->get();
            foreach ($existingRates as $rate) {
                if (!empty($rate->code)) {
                    $out[] = strtoupper($this->sanitizeUtf8($rate->code));
                }
            }
        }
        
        $out = array_values(array_unique($out));
        return $out ?: ['USD'];
    }

    private function findPriceColumn(array $columns): ?string
    {
        foreach (['price','amount','cost','value','fee'] as $column) {
            if (in_array($column, $columns, true)) return $column;
        }
        return null;
    }

    private function findCurrencyColumn(array $columns): ?string
    {
        foreach (['currency','currency_code','currency_id'] as $column) {
            if (in_array($column, $columns, true)) return $column;
        }
        return null;
    }

    private function findParentIdColumn(array $columns): ?string
    {
        $candidates = ['plan_id','product_id','service_id','package_id','item_id'];
        foreach ($candidates as $c) {
            if (in_array($c, $columns, true)) return $c;
        }
        foreach ($columns as $col) {
            if (Str::endsWith($col, '_id') && !in_array($col, ['id','user_id','currency_id'], true)) {
                return $col;
            }
        }
        return null;
    }

    private function ensureRatesExist(): void
    {
        $codes = $this->getEnabledCurrencyCodes();
        foreach ($codes as $i => $code) {
            ExchangeRate::firstOrCreate(
                ['code' => $code],
                ['rate' => 1.0, 'enabled' => true, 'is_default' => $i === 0]
            );
        }
    }
}
