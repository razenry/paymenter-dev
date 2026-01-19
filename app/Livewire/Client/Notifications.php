<?php

namespace App\Livewire\Client;

use App\Classes\Settings;
use App\Livewire\Component;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use App\Helpers\DiscordNotificationHelper;
use Livewire\Attributes\Computed;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Illuminate\Support\Facades\RateLimiter;

class Notifications extends Component
{
    public $preferences = [];

    public function testDiscordNotification()
    {
        $user = Auth::user();

        if (!$user->discord_user_id) {
            $this->notify('You must be connected to Discord to send a test notification.', 'error');
            return;
        }

        $key = 'discord-test-notification:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);
            $this->notify('Too many requests. Please wait ' . $seconds . ' seconds before trying again.', 'error');
            return;
        }

        try {
            $success = DiscordNotificationHelper::sendNotification(
                $user,
                'This is a test notification from ' . config('app.name'),
                'Test Notification',
                [['name' => 'Status', 'value' => 'Success', 'inline' => true]]
            );

            if ($success) {
                RateLimiter::hit($key, 60);
                $this->notify('Test notification sent successfully!', 'success');
            } else {
                $this->notify('Failed to send test notification. Please make sure your DMs are open.', 'error');
            }
        } catch (\Exception $e) {
            $this->notify('Error sending notification: ' . $e->getMessage(), 'error');
        }
    }

    #[Computed]
    public function discordInviteUrl()
    {
        return config('settings.discord_invite_url');
    }



    public function mount()
    {
        $userPreferences = Auth::user()->notificationsPreferences;

        $notifications = NotificationTemplate::where('enabled', true)
            ->where(function ($query) {
                // If they are both force, or force and never, they are not user configurable
                $query->where('mail_enabled', '!=', 'force')
                    ->orWhere('in_app_enabled', '!=', 'force')
                    ->orWhere('discord_enabled', '!=', 'force');
            })
            ->whereNotIn('key', [
                'email_verification',
                'password_reset',
                'new_login_detected',
            ])
            ->get();

        foreach ($notifications as $notification) {
            $userPreference = $userPreferences->firstWhere('notification_template_id', $notification->id);

            $this->preferences[$notification->key] = [
                'mail_enabled' => $notification->isEnabledForPreference($userPreference, 'mail'),
                'in_app_enabled' => $notification->isEnabledForPreference($userPreference, 'app'),
                'discord_enabled' => $notification->isEnabledForPreference($userPreference, 'discord'),
            ];
        }
    }

    public function savePreferences()
    {
        foreach ($this->notifications as $preference) {
            // Check if allowed to change
            if (!($preference->mail_controllable || $preference->in_app_controllable)) {
                continue;
            }

            // Create new preference
            Auth::user()->notificationsPreferences()->updateOrCreate(
                ['notification_template_id' => $preference->id],
                [
                    'mail_enabled' => $this->preferences[$preference->key]['mail_enabled'],
                    'in_app_enabled' => $this->preferences[$preference->key]['in_app_enabled'],
                    'discord_enabled' => $this->preferences[$preference->key]['discord_enabled'],
                ]
            );
        }

        $this->notify('Notification preferences updated successfully.', 'success');
    }

    public function storePushSubscription($subscription)
    {
        $subscription = json_decode($subscription, true);

        $pushSubscription = Auth::user()->pushSubscriptions()
            ->updateOrCreate([
                'endpoint' => $subscription['endpoint'],
            ], [
                'p256dh_key' => $subscription['keys']['p256dh'],
                'auth_key' => $subscription['keys']['auth'],
            ]);

        $this->notify('Push subscription saved successfully.', 'success');

        try {
            $this->sendTestNotification($pushSubscription);
            $this->notify('Test push notification sent successfully. Please check your device.', 'success');
        } catch (\Exception $e) {
            // Failed to send notification
            $this->notify('Failed to send test push notification: ' . $e->getMessage(), 'error');
        }
    }

    private function sendTestNotification($pushSubscription)
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('app.url'),
                'publicKey' => config('settings.vapid_public_key'),
                'privateKey' => config('settings.vapid_private_key'),
            ],
        ]);

        // Create the subscription object properly
        $result = $webPush->sendOneNotification(
            $pushSubscription->subscription(),
            json_encode([
                'title' => 'Push Notifications Enabled!',
                'body' => 'You will now receive push notifications from ' . config('app.name'),
                'icon' => Storage::url(config('settings.logo')),
                'badge' => Storage::url(config('settings.logo')),
                'show_in_app' => false,
                // This forces it to be a push notification only
                'show_as_push' => true,
                'data' => [
                    'url' => url('/'),
                ],
            ])
        );

        // Check if the notification was sent successfully
        if (!$result->isSuccess()) {
            throw new \Exception('Push notification failed: ' . $result->getReason());
        }
    }

    #[Computed]
    public function supportsPush()
    {
        return Settings::validateOrCreateVapidKeys();
    }

    #[Computed]
    public function discordNotificationsEnabled()
    {
        return config('settings.discord_notifications_enabled');
    }

    #[Computed]
    public function discordConnected()
    {
        return Auth::user()->discord_user_id !== null;
    }

    #[Computed]
    public function notifications()
    {
        $userPreferences = Auth::user()->notificationsPreferences;

        return NotificationTemplate::where('enabled', true)
            ->where(function ($query) {
                // If they are both force, or force and never, they are not user configurable
                $query->where('mail_enabled', '!=', 'force')
                    ->orWhere('in_app_enabled', '!=', 'force')
                    ->orWhere('discord_enabled', '!=', 'force');
            })
            ->whereNotIn('key', [
                'email_verification',
                'password_reset',
                'new_login_detected',
            ])
            ->get()
            ->map(function ($notification) use ($userPreferences) {
                return (object) [
                    'id' => $notification->id,
                    'key' => $notification->key,
                    'name' => $notification->edit_preference_message,
                    'mail_controllable' => $notification->isEmailUserControllable(),
                    'in_app_controllable' => $notification->isInAppUserControllable(),
                    'discord_controllable' => $notification->isDiscordUserControllable(),
                    'mail_enabled' => $notification->isEnabledForPreference($userPreferences->firstWhere('notification_template_id', $notification->id), 'mail'),
                    'in_app_enabled' => $notification->isEnabledForPreference($userPreferences->firstWhere('notification_template_id', $notification->id), 'app'),
                    'discord_enabled' => $notification->isEnabledForPreference($userPreferences->firstWhere('notification_template_id', $notification->id), 'discord'),
                ];
            });
    }

    public function disconnectDiscord()
    {
        Auth::user()->update([
            'discord_user_id' => null,
        ]);

        $this->notify(__('account.discord_disconnected'));
    }

    public function render()
    {
        return view('client.account.notifications')->layoutData([
            'sidebar' => true,
            'title' => 'Notifications',
        ]);
    }
}
