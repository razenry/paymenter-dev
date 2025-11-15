<x-filament-panels::page>
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('refresh-rates', () => {
                setTimeout(() => { window.location.reload(); }, 800);
            });
        });
    </script>

    <div class="space-y-6">
        <!-- Info Banner -->
        <div class="fi-banner">
            <div class="fi-banner-icon" aria-hidden="true">i</div>
            <div>
                <div class="fi-banner-title">Exchange Rates Management</div>
                <div class="fi-banner-desc">
                    Default (base) currency stays unchanged. Only Paymenter-enabled currencies are shown and updated.
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="fi-tabs">
            <div class="fi-tabs-list">
                <button
                    wire:click="switchTab('rates')"
                    class="px-4 py-2 text-sm font-medium rounded-md {{ $activeTab === 'rates' ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:text-gray-700' }}">
                    Exchange Rates
                </button>
                <button
                    wire:click="switchTab('config')"
                    class="px-4 py-2 text-sm font-medium rounded-md {{ $activeTab === 'config' ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:text-gray-700' }}">
                    API Configuration
                </button>
            </div>
        </div>

        <!-- Rates Tab -->
        @if ($activeTab === 'rates')
            <div class="fi-card">
                <div class="fi-toolbar">
                    <strong class="fi-title">Manage Exchange Rates</strong>
                    <div class="flex gap-2">
                        <button wire:click.prevent="fetchRates" class="fi-btn fi-btn-color-primary fi-btn-size-sm">
                            <span class="fi-btn-label">Fetch Latest Rates</span>
                        </button>
                        <button wire:click.prevent="saveRates" class="fi-btn fi-btn-color-success fi-btn-size-sm">
                            <span class="fi-btn-label">Save Changes</span>
                        </button>
                        <button wire:click.prevent="applyToProducts" class="fi-btn fi-btn-color-warning fi-btn-size-sm">
                            <span class="fi-btn-label">Apply to Products</span>
                        </button>
                    </div>
                </div>

                <div class="fi-body">
                    <div class="fi-table-wrap">
                        <table class="fi-table">
                            <thead>
                                <tr>
                                    <th>Default</th>
                                    <th>Code</th>
                                    <th>Rate (1 Base = x Code)</th>
                                    <th>Enabled</th>
                                    <th>Set Default</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $rates = $this->getRates(); @endphp
                                @forelse ($rates as $rate)
                                    <tr>
                                        <td>
                                            @if ($rate->is_default)
                                                <span class="fi-badge fi-badge-success">Default</span>
                                            @endif
                                        </td>
                                        <td>
                                            <input type="text"
                                                value="{{ $rate->code }}"
                                                class="fi-input w-24"
                                                onblur="@this.updateRate({{ $rate->id }}, 'code', this.value.toUpperCase())">
                                        </td>
                                        <td>
                                            <input type="number" step="0.00000001" min="0"
                                                value="{{ number_format((float) $rate->rate, 8, '.', '') }}"
                                                class="fi-input w-40"
                                                onblur="@this.updateRate({{ $rate->id }}, 'rate', this.value)">
                                        </td>
                                        <td>
                                            <input type="checkbox"
                                                @if ($rate->enabled) checked @endif
                                                class="fi-checkbox"
                                                onchange="@this.updateRate({{ $rate->id }}, 'enabled', this.checked)">
                                        </td>
                                        <td>
                                            @if (! $rate->is_default)
                                                <button class="fi-btn fi-btn-color-secondary fi-btn-size-xs"
                                                    wire:click="setDefault({{ $rate->id }})">
                                                    Make Default
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center fi-empty">
                                            No currencies available. Check Paymenter currency settings.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="fi-tip">Tip: The default currency's rate is always 1.0 and remains unchanged.</p>
                    <p class="fi-tip">Note: Only enabled currencies will be updated when using "Fetch Latest Rates". Disabled currencies will be skipped.</p>
                </div>
            </div>
        @endif

        <!-- API Config Tab -->
        @if ($activeTab === 'config')
            <div class="fi-card">
                <div class="fi-toolbar">
                    <strong class="fi-title">API Configuration</strong>
                    <div class="flex gap-2">
                        <button wire:click.prevent="saveApiConfig" class="fi-btn fi-btn-color-primary fi-btn-size-sm">
                            <span class="fi-btn-label">Save Configuration</span>
                        </button>
                        <button wire:click.prevent="fetchRates" class="fi-btn fi-btn-color-success fi-btn-size-sm">
                            <span class="fi-btn-label">Test API</span>
                        </button>
                    </div>
                </div>
                <div class="fi-body">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">API URL</label>
                            <input type="url"
                                wire:model="apiUrl"
                                class="fi-input w-full"
                                placeholder="https://v6.exchangerate-api.com/v6/YOUR_API_KEY/latest/">
                            <p class="text-sm text-gray-500 mt-1">
                                Examples:<br>
                                • ExchangeRate-API: <code>https://v6.exchangerate-api.com/v6/&lt;KEY&gt;/latest/</code> (base auto-appended)<br>
                                • Frankfurter: <code>https://api.frankfurter.app/latest</code>
                            </p>
                        </div>
                        
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Timeout (seconds)</label>
                            <input type="number"
                                wire:model="timeout"
                                min="1"
                                max="30"
                                class="fi-input w-32"
                                placeholder="8">
                            <p class="text-sm text-gray-500 mt-1">Request timeout in seconds (1–30).</p>
                        </div>
                    </div>
                    
                    <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <h4 class="font-semibold text-blue-900 mb-2">Recommended API Providers</h4>
                        <div class="space-y-2 text-sm">
                            <div><strong>Frankfurter.app (Free, Recommended):</strong> https://api.frankfurter.app/latest</div>
                            <div><strong>ExchangeRate-API Base URL:</strong> https://v6.exchangerate-api.com/v6/YOUR_API_KEY/latest/</div>
                            <div class="text-blue-600"><strong>Tip:</strong> Replace YOUR_API_KEY with your actual ExchangeRate-API key</div>
                            <div class="text-orange-600"><strong>Note:</strong> The system will automatically append your base currency to the URL</div>
                            <div class="text-gray-600"><strong>Get API Key:</strong> Visit <a href="https://www.exchangerate-api.com/" target="_blank" class="text-blue-600 underline">exchangerate-api.com</a> to get your free API key</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <!-- Footer with version info -->
    <div class="mt-6 text-center text-sm text-gray-500">
        Currency Exchanger v1.3 - Automatic Exchange Rate Updates for Paymenter
    </div>

    <style>
        .fi-banner{display:flex;gap:12px;padding:14px 16px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;}
        .fi-banner-title{font-weight:600;color:#0f172a;}
        .fi-banner-desc{color:#475569;}
        .fi-banner-icon{width:22px;height:22px;border-radius:999px;background:linear-gradient(135deg,#6366f1,#22d3ee);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;margin-top:2px;}
        .fi-tabs-list{display:flex;gap:8px;}
        .fi-card{border:1px solid #e5e7eb;border-radius:12px;background:#fff;}
        .fi-toolbar{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid #e5e7eb;}
        .fi-title{font-weight:600;color:#0f172a;}
        .fi-body{padding:14px;}
        .fi-btn{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:8px;font-size:12px;font-weight:600;border:1px solid transparent;}
        .fi-btn-size-sm{padding:6px 10px;}
        .fi-btn-size-xs{padding:4px 8px;font-size:11px;}
        .fi-btn-color-primary{background:#eef2ff;color:#3730a3;border-color:#c7d2fe;}
        .fi-btn-color-success{background:#ecfeff;color:#0e7490;border-color:#a5f3fc;}
        .fi-btn-color-warning{background:#fffbeb;color:#92400e;border-color:#fde68a;}
        .fi-btn-color-secondary{background:#f4f4f5;color:#1f2937;border-color:#e5e7eb;}
        .fi-table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;}
        .fi-table{width:100%;border-collapse:collapse;}
        .fi-table th,.fi-table td{padding:10px 12px;border-bottom:1px solid #e5e7eb;}
        .fi-table th{text-align:left;font-size:12px;color:#475569;font-weight:600;}
        .fi-input{border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;font-size:13px;width:100%;}
        .fi-badge{padding:2px 6px;border-radius:999px;font-size:11px;}
        .fi-badge-success{background:#ecfdf5;color:#065f46;}
        .fi-checkbox{width:18px;height:18px;accent-color:#6366f1;}
        .fi-empty{color:#475569;}
        .fi-tip{color:#667085;font-size:13px;margin-top:10px;}
    </style>
</x-filament-panels::page>
