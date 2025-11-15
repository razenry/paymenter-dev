// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    $ext = \App\Models\Extension::where('extension', 'CurrencyUpdater')
        ->where('type', 'other')
        ->where('enabled', true)
        ->first();

    if (!$ext) {
        return; // not enabled
    }

    // Respect settings saved via getConfig()
    $settings = $ext->settings->pluck('value', 'key')->toArray();
    if (!($settings['auto_update_enabled'] ?? true)) {
        return;
    }

    \Paymenter\Extensions\Others\CurrencyUpdater\src\Services\Updater::run(false);
})->dailyAt(($extTime = optional(
    \App\Models\Extension::where('extension', 'CurrencyUpdater')->where('type','other')->first()
)->settings->where('key','schedule_time')->first()->value ?? '01:15'));
