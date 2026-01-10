<?php

namespace App\Helpers;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DiscordNotificationHelper
{
    /**
     * Send a Discord DM to a user
     */
    public static function sendDM(User $user, string $message, string $embedTitle = null, array $embedFields = []): bool
    {
        if (!$user->discord_user_id || !config('settings.discord_bot_token') || !config('settings.discord_notifications_enabled')) {
            return false;
        }

        $botToken = config('settings.discord_bot_token');

        try {
            // First, create a DM channel with the user
            $dmResponse = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'Content-Type' => 'application/json',
            ])->post('https://discord.com/api/v10/users/@me/channels', [
                'recipient_id' => $user->discord_user_id,
            ]);

            if (!$dmResponse->successful()) {
                return false;
            }

            $dmChannel = $dmResponse->json();

            // Prepare the message payload
            $payload = [
                'content' => $message,
            ];

            // Add embed if title or fields are provided
            if ($embedTitle || !empty($embedFields)) {
                $embed = [
                    'title' => $embedTitle ?: 'Paymenter Notification',
                    'color' => 0x00FF00, // Green color
                    'timestamp' => now()->toIso8601String(),
                    'footer' => [
                        'text' => config('app.name'),
                    ],
                ];

                if (!empty($embedFields)) {
                    $embed['fields'] = $embedFields;
                }

                $payload['embeds'] = [$embed];
            }

            // Send the message
            $messageResponse = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'Content-Type' => 'application/json',
            ])->post("https://discord.com/api/v10/channels/{$dmChannel['id']}/messages", $payload);

            return $messageResponse->successful();

        } catch (\Exception $e) {
            // Log the error if needed
            logger()->error('Failed to send Discord DM', [
                'user_id' => $user->id,
                'discord_user_id' => $user->discord_user_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send Discord notification to user (alias for sendDM for backward compatibility)
     */
    public static function sendNotification(User $user, string $message, string $embedTitle = null, array $embedFields = []): bool
    {
        return self::sendDM($user, $message, $embedTitle, $embedFields);
    }

    /**
     * Check if a user can receive Discord notifications
     */
    public static function canSendNotification(User $user): bool
    {
        return self::canSendDM($user);
    }

    /**
     * Check if a user can receive Discord DMs
     */
    public static function canSendDM(User $user): bool
    {
        return $user->discord_user_id &&
               config('settings.discord_bot_token') &&
               config('settings.discord_notifications_enabled');
    }
}