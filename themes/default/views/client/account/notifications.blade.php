<div class="container mt-14">
    <x-navigation.breadcrumb />

    <div class="px-2">
        <!-- Configure push notifications -->
        @if($this->supportsPush())
        <div class="bg-background-secondary rounded-lg p-4 mb-6" x-data="pushNotifications">
            <h2 class="text-lg font-medium text-primary mb-2">{{ __('account.push_notifications') }}</h2>
            <p class="text-base/70 mb-4">{{ __('account.push_notifications_description') }}</p>
            <x-button.primary type="button" class="!w-fit" @click="subscribe"
                x-bind:disabled="subscriptionStatus !== 'not_subscribed'">
                <x-ri-notification-line class="size-5 mr-2" />
                {{ __('account.enable_push_notifications') }}
            </x-button.primary>
            <div x-show="subscriptionStatus !== 'unknown'">
                <template x-if="subscriptionStatus === 'not_supported'">
                    <p class="text-sm text-red-600">{{ __('account.push_status.not_supported') }}</p>
                </template>
                <template x-if="subscriptionStatus === 'denied'">
                    <p class="text-sm text-red-600">{{ __('account.push_status.denied') }}</p>
                </template>
                <template x-if="subscriptionStatus === 'subscribed'">
                    <p class="text-sm text-green-600">{{ __('account.push_status.subscribed') }}</p>
                </template>
            </div>
        </div>
        @script
        <script>
            Alpine.data('pushNotifications', () => ({
                    subscriptionStatus: 'unknown',

                    init() {
                        if ('serviceWorker' in navigator && 'PushManager' in window) {
                            navigator.serviceWorker.ready.then((registration) => {
                                registration.pushManager.getSubscription().then((subscription) => {
                                    if (subscription) {
                                        this.subscriptionStatus = 'subscribed';
                                    } else {
                                        this.subscriptionStatus = Notification.permission === 'denied' ? 'denied' : 'not_subscribed';
                                    }
                                });
                            });
                        } else {
                            this.subscriptionStatus = 'not_subscribed';
                        }
                    },

                    subscribe() {
                        if ('serviceWorker' in navigator && 'PushManager' in window) {
                            navigator.serviceWorker.ready.then((registration) => {
                                registration.pushManager.getSubscription().then((subscription) => {
                                    if (subscription) {
                                        @this.call('storePushSubscription', JSON.stringify(subscription));
                                        this.subscriptionStatus = 'subscribed';
                                        return;
                                    }

                                    // Subscribe the user
                                    registration.pushManager.subscribe({
                                        userVisibleOnly: true,
                                        applicationServerKey: urlBase64ToUint8Array('{{ config('settings.vapid_public_key') }}')
                                    }).then((newSubscription) => {
                                        @this.call('storePushSubscription', JSON.stringify(newSubscription));
                                        this.subscriptionStatus = 'subscribed';
                                    }).catch((e) => {
                                        if (Notification.permission === 'denied') {
                                            this.subscriptionStatus = 'denied';
                                        } else {
                                            console.error('Failed to subscribe the user: ', e);
                                            this.subscriptionStatus = 'not_subscribed';
                                        }
                                    });
                                });
                            });
                        } else {
                            this.subscriptionStatus = 'not_supported';
                        }
                    }
                }));
                function urlBase64ToUint8Array(base64String) {
                    const padding = '='.repeat((4 - base64String.length % 4) % 4);
                    const base64 = (base64String + padding)
                        .replace(/\-/g, '+')
                        .replace(/_/g, '/');

                    const rawData = window.atob(base64);
                    const outputArray = new Uint8Array(rawData.length);

                    for (let i = 0; i < rawData.length; ++i) {
                        outputArray[i] = rawData.charCodeAt(i);
                    }
                    return outputArray;
                }

        </script>
        @endscript
        @endif

        <!-- Configure Discord connection -->
        @if($this->discordNotificationsEnabled)
        <div class="bg-background-secondary rounded-lg p-4 mb-6">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h2 class="text-lg font-medium text-primary mb-1">{{ __('account.discord_connection') }}</h2>
                    @if($this->discordConnected())
                        <div class="flex items-center gap-2">
                            <x-ri-discord-fill class="size-5 text-[#5865F2]" />
                            <span class="text-sm text-primary-100">{{ __('account.discord_connected') }}</span>
                            <span class="text-sm text-primary-400">• {{ __('account.discord_connected_description') }}</span>
                        </div>
                    @else
                        <p class="text-base/70">{{ __('account.discord_connection_description') }}</p>
                    @endif
                </div>

                @if($this->discordConnected())
                    <x-button.danger type="button" class="w-full sm:w-auto" x-on:click="$store.confirmation.confirm({
                        title: '{{ __('account.discord_disconnect') }}',
                        message: '{{ __('account.discord_disconnect_description') }}',
                        confirmText: '{{ __('account.confirm') }}',
                        cancelText: '{{ __('account.cancel') }}',
                        callback: () => $wire.disconnectDiscord()
                    })">
                        {{ __('account.discord_disconnect') }}
                    </x-button.danger>
                @else
                    <a href="{{ route('oauth.link', 'discord') }}" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-[#5865F2] hover:bg-[#4752C4] rounded-lg transition-colors shadow-lg shadow-[#5865F2]/20">
                        <x-ri-discord-fill class="size-5 mr-2" />
                        {{ __('account.connect_discord') }}
                    </a>
                @endif
            </div>

            @if($this->discordConnected() && $this->discordInviteUrl)
                <div class="mt-6 border-t border-neutral/10 pt-6">
                    <div class="flex flex-col gap-4 p-4 rounded-xl bg-primary-800/50 border border-primary-200/10">
                        <div class="flex gap-3">
                            <div class="p-2 rounded-lg bg-primary-700/30 h-fit">
                                <x-ri-information-line class="size-5 text-primary-400" />
                            </div>
                            <div>
                                <h3 class="text-sm font-medium text-primary-100">Setup Notice</h3>
                                <p class="text-sm text-primary-400 mt-1">
                                    To receive notifications, you must join our Discord server and ensure your direct messages are open.
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                            <a href="{{ $this->discordInviteUrl }}" target="_blank"
                                class="inline-flex items-center justify-center px-6 py-2 text-sm font-medium text-white bg-[#5865F2] hover:bg-[#4752C4] rounded-lg transition-all shadow-lg shadow-[#5865F2]/20 hover:shadow-[#5865F2]/40 whitespace-nowrap">
                                <x-ri-discord-fill class="size-4 mr-2" />
                                Join Server
                            </a>
                            <button type="button" wire:click="testDiscordNotification" wire:loading.attr="disabled"
                                class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors shadow-lg shadow-blue-600/20 hover:shadow-blue-600/40 whitespace-nowrap">
                                <x-loading wire:loading wire:target="testDiscordNotification" class="mr-2" />
                                <x-ri-notification-line wire:loading.remove wire:target="testDiscordNotification" class="size-4 mr-2" />
                                Test
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full bg-background-secondary rounded-lg">
                <thead>
                    <tr class="border-b border-neutral/20">
                        <th class="text-left py-4 px-6 text-primary font-medium">
                            {{ __('account.notification') }}
                            <p class="text-sm text-base/70 font-normal mt-1">
                                {{ __('account.notifications_description') }}
                            </p>
                        </th>
                        <th class="text-center py-4 px-4 text-primary font-medium">
                            <div class="flex items-center justify-center gap-2">
                                <x-ri-mail-line class="size-4" />
                                <span>{{ __('account.email_notifications') }}</span>
                            </div>
                        </th>
                        <th class="text-center py-4 px-4 text-primary font-medium">
                            <div class="flex items-center justify-center gap-2">
                                <x-ri-notification-line class="size-4" />
                                <span>{{ __('account.in_app_notifications') }}</span>
                            </div>
                        </th>
                        @if($this->discordNotificationsEnabled && $this->discordConnected)
                        <th class="text-center py-4 px-4 text-primary font-medium">
                            <div class="flex items-center justify-center gap-2">
                                <x-ri-discord-fill class="size-4" />
                                <span>{{ __('account.discord_notifications') }}</span>
                            </div>
                        </th>
                        @endif
                    </tr>
                </thead>
                <tbody x-data="{ preferences: $wire.entangle('preferences') }">
                    @foreach($this->notifications as $notification)
                    <tr class="border-b border-neutral/10 hover:bg-background/50 transition-colors">
                        <td class="py-4 px-6 text-base/70">
                            {{ $notification->name }}
                        </td>
                        <td class="py-4 px-4">
                            <div class="flex justify-center items-center">
                                <x-form.toggle :disabled="!$notification->mail_controllable"
                                    wire:model.defer="preferences.{{ $notification->key }}.mail_enabled" />
                            </div>
                        </td>
                        <td class="py-4 px-4">
                            <div class="flex justify-center items-center">
                                <x-form.toggle :disabled="!$notification->in_app_controllable"
                                    wire:model.defer="preferences.{{ $notification->key }}.in_app_enabled" />
                            </div>
                        </td>
                        @if($this->discordNotificationsEnabled && $this->discordConnected)
                        <td class="py-4 px-4">
                            <div class="flex justify-center items-center">
                                <x-form.toggle :disabled="!$notification->discord_controllable"
                                    wire:model.defer="preferences.{{ $notification->key }}.discord_enabled" />
                            </div>
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-button.primary wire:click="savePreferences" class="w-full mt-6" wire:loading.attr="disabled">
            <x-loading wire:loading wire:target="savePreferences" />
            <span wire:loading.remove wire:target="savePreferences">
                {{ __('general.save') }}
            </span>
        </x-button.primary>
    </div>
</div>