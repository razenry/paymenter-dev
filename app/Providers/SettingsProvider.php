<?php

namespace App\Providers;

use App\Classes\Settings;
use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Qirolab\Theme\Theme;

class SettingsProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void {}

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->getSettings();
    }

    public static function getSettings($force = false): void
    {
        if (config('settings') && !empty(config('settings')) && !$force) {
            return;
        }
        try {
            // Load settings from cache
            $settings = Cache::get('settings', []);
            if (empty($settings)) {
                $settingsRaw = Setting::where('settingable_type', null)->get();
                $settings = [];
                foreach ($settingsRaw as $setting) {
                    $value = $setting->value;
                    // Cast based on type
                    if ($setting->type === 'boolean') {
                        $value = (bool) $value;
                    } elseif ($setting->type === 'integer' || $setting->type === 'number') {
                        $value = (int) $value;
                    } elseif ($setting->type === 'float' || $setting->type === 'decimal') {
                        $value = (float) $value;
                    } elseif ($setting->type === 'json') {
                        $value = json_decode($value, true);
                    }
                    $settings[$setting->key] = $value;
                }
                Cache::put('settings', $settings);
            }
            // Is the current command a config:cache command?
            if (isset($_SERVER['argv']) && (in_array('config:cache', $_SERVER['argv']) || in_array('optimize', $_SERVER['argv']) || in_array('app:optimize', $_SERVER['argv']))) {
                return;
            }
            config(['settings' => $settings]);
            foreach (Settings::settings() as $settings) {
                foreach ($settings as $setting) {
                    if (isset($setting['override']) && config("settings.$setting[name]") !== null) {
                        config([$setting['override'] => config("settings.$setting[name]")]);
                    }
                }
            }

            include_once app_path('Classes/helpers.php');

            date_default_timezone_set(config('settings.timezone', 'UTC'));

            Theme::set(config('settings.theme', 'default'), 'default');

            if (Str::startsWith(config('app.url') ?? '', 'https://')) {
                URL::forceScheme('https');
            }
            URL::forceRootUrl(config('app.url'));

            Config::set('filesystems.disks.public.url', config('app.url') . '/storage');
        } catch (Exception $e) {
            // Do nothing
        }
    }

    public static function flushCache()
    {
        Cache::forget('settings');
        // Restart queue worker
        Artisan::call('queue:restart');
        self::getSettings(true);
    }
}
