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
    public static function sendDM(User $user, string $message, string $embedTitle = null, array $embedFields = [], string $buttonUrl = null, string $buttonLabel = null): bool
    {
            logger()->debug('DiscordNotificationHelper::sendDM called', [
                'user_id' => $user->id,
                'discord_user_id' => $user->discord_user_id,
                'message_length' => strlen($message),
                'has_embed_title' => !empty($embedTitle),
                'embed_fields_count' => count($embedFields),
                'has_button' => !empty($buttonUrl),
                'button_label' => $buttonLabel,
            ]);

        if (!$user->discord_user_id || !config('settings.discord_bot_token') || !config('settings.discord_notifications_enabled')) {
            logger()->warning('Discord DM blocked - missing requirements', [
                'user_id' => $user->id,
                'has_discord_user_id' => !empty($user->discord_user_id),
                'has_bot_token' => !empty(config('settings.discord_bot_token')),
                'notifications_enabled' => config('settings.discord_notifications_enabled'),
            ]);
            return false;
        }

        $botToken = config('settings.discord_bot_token');

        try {
            logger()->debug('Creating DM channel with Discord user', ['discord_user_id' => $user->discord_user_id]);
            
            // First, create a DM channel with the user
            $dmResponse = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'Content-Type' => 'application/json',
            ])->post('https://discord.com/api/v10/users/@me/channels', [
                'recipient_id' => $user->discord_user_id,
            ]);

            if (!$dmResponse->successful()) {
                logger()->error('Failed to create DM channel', [
                    'user_id' => $user->id,
                    'discord_user_id' => $user->discord_user_id,
                    'status' => $dmResponse->status(),
                    'response' => $dmResponse->json(),
                ]);
                return false;
            }

            $dmChannel = $dmResponse->json();
            logger()->debug('DM channel created successfully', ['channel_id' => $dmChannel['id']]);

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

            // Add button component if button URL is provided
            if ($buttonUrl) {
                $payload['components'] = [
                    [
                        'type' => 1, // ACTION_ROW
                        'components' => [
                            [
                                'type' => 2, // BUTTON
                                'style' => 5, // LINK style
                                'label' => $buttonLabel ?: 'View Details',
                                'url' => $buttonUrl,
                            ]
                        ]
                    ]
                ];
            }

            logger()->debug('Sending Discord message', [
                'channel_id' => $dmChannel['id'],
                'payload_keys' => array_keys($payload),
                'has_components' => isset($payload['components']),
            ]);

            // Send the message
            $messageResponse = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'Content-Type' => 'application/json',
            ])->post("https://discord.com/api/v10/channels/{$dmChannel['id']}/messages", $payload);

            if (!$messageResponse->successful()) {
                logger()->error('Failed to send Discord message', [
                    'user_id' => $user->id,
                    'channel_id' => $dmChannel['id'],
                    'status' => $messageResponse->status(),
                    'response' => $messageResponse->json(),
                ]);
                return false;
            }

            logger()->info('Discord message sent successfully', [
                'user_id' => $user->id,
                'discord_user_id' => $user->discord_user_id,
                'message_id' => $messageResponse->json()['id'] ?? null,
            ]);

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
    public static function sendNotification(User $user, string $message, string $embedTitle = null, array $embedFields = [], string $buttonUrl = null, string $buttonLabel = null): bool
    {
        return self::sendDM($user, $message, $embedTitle, $embedFields, $buttonUrl, $buttonLabel);
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
    /**
     * Get Guild ID from Invite URL
     */
    public static function getGuildIdFromInvite(string $inviteUrl): ?string
    {
        if (preg_match('/(?:discord\.gg|discord\.com\/invite)\/([a-zA-Z0-9-]+)/', $inviteUrl, $matches)) {
            $code = $matches[1];
        } else {
            $code = basename(parse_url($inviteUrl, PHP_URL_PATH));
        }

        if (empty($code)) {
            return null;
        }

        return Cache::remember('discord_invite_guild_' . $code, 3600, function () use ($code) {
            $response = Http::get("https://discord.com/api/v10/invites/{$code}");
            if ($response->successful()) {
                return $response->json()['guild']['id'] ?? null;
            }
            return null;
        });
    }

    /**
     * Check if user is in guild
     */
    public static function isUserInGuild(string $userId, string $guildId): bool
    {
        if (!config('settings.discord_bot_token')) {
            return false;
        }

        $botToken = config('settings.discord_bot_token');
        $response = Http::withHeaders([
            'Authorization' => "Bot {$botToken}",
        ])->get("https://discord.com/api/v10/guilds/{$guildId}/members/{$userId}");

        return $response->successful();
    }

    /**
     * Check if DM is accessible (by trying to open a channel)
     */
    public static function isDmAccessible(User $user): bool
    {
        if (!$user->discord_user_id || !config('settings.discord_bot_token')) {
            return false;
        }

        $botToken = config('settings.discord_bot_token');
        
        // Try creating a DM channel
        $response = Http::withHeaders([
            'Authorization' => "Bot {$botToken}",
            'Content-Type' => 'application/json',
        ])->post('https://discord.com/api/v10/users/@me/channels', [
            'recipient_id' => $user->discord_user_id,
        ]);

        return $response->successful();
    }
}