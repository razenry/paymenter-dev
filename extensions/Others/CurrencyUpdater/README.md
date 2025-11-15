# Paymenter - Currency Updater Extension

Automatic exchange-rate updates for all enabled currencies relative to your default currency in Paymenter.

## Installation

### Step 1: Copy Extension Files

1. Copy the entire `CurrencyUpdater` folder to your Paymenter installation:
   ```
   /path/to/paymenter/extensions/Others/CurrencyUpdater/
   ```

### Step 2: Enable the Extension

1. Go to your Paymenter admin panel
2. Navigate to **Extensions** → **Others**
3. Find **CurrencyUpdater** and click **Enable**
4. The extension will automatically run its database migrations

### Step 3: Access Exchange Rates

1. In your Paymenter admin panel, go to **Configuration** → **Exchange Rates**
2. You'll see all currencies from your main Paymenter installation
3. Set one currency as **Default** (this will be your base currency with rate = 1.0)

## Usage

### Fetching Latest Exchange Rates

1. Go to **Configuration** → **Exchange Rates**
2. Click **"Fetch Latest Rates"** to get current exchange rates from the API
3. The system will automatically update rates for all your configured currencies
4. You can manually adjust rates if needed
5. Click **"Save Changes"** to save your modifications

### Setting Default Currency

1. In the Exchange Rates page, select the radio button next to your preferred default currency
2. Only one currency can be default at a time
3. The default currency will always have a rate of 1.0

### Applying Rates to Products

1. After fetching and saving exchange rates, click **"Apply to Products"**
2. This will update all product prices based on the current exchange rates
3. Products will be converted from their current currency to the default currency

## Adding New Currencies to Products

### Method 1: Through Paymenter Admin

1. Go to **Configuration** → **Currencies** in your Paymenter admin
2. Add your new currency (e.g., INR, EUR, etc.)
3. The Exchange Rates page will automatically show the new currency
4. Fetch latest rates to get the current exchange rate
5. Apply rates to products to update all product prices

## Configuration

### Default Settings

- **API Provider**: Frankfurter.app (free, reliable)
- **Update Frequency**: Manual or via cron job
- **Rate Precision**: 8 decimal places
- **Auto-creation**: Exchange rates are automatically created for new currencies

### Cron Job Setup (Optional)

To automatically update rates daily, add this to your crontab:

```bash
# Update exchange rates daily at 6 AM
0 6 * * * cd /path/to/paymenter && php artisan currency:update
```

## Troubleshooting

### Common Issues

1. **"No currencies found"**
   - Ensure you have currencies configured in Paymenter admin
   - Check that the `currencies` table exists

2. **"Failed to fetch rates"**
   - Check your internet connection
   - The API might be temporarily unavailable
   - Check Laravel logs for detailed error messages

3. **Rates not updating in products**
   - Make sure to click "Apply to Products" after fetching rates
   - Check that your product tables have the correct structure

### File Structure

```
CurrencyUpdater/
├── Admin/Pages/ExchangeRates.php    # Admin interface
├── src/
│   ├── Models/ExchangeRate.php      # Eloquent model
│   ├── Services/ExchangeRateService.php  # API service
│   └── Console/UpdateCurrencyRates.php   # CLI command
├── database/migrations/             # Database migrations
├── resources/views/                 # Blade templates
└── CurrencyUpdater.php             # Extension bootstrap
```

## Support

For issues or questions:
1. Check the Laravel logs in `storage/logs/`
2. Verify your Paymenter currency configuration
3. Ensure the extension is properly enabled
