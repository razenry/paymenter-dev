<?php

namespace Paymenter\Extensions\Servers\PterodactylProxmox;

use App\Classes\Extension\Server;
use App\Events\Service as ServiceEvent;
use App\Exceptions\DisplayException;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Class Pterodactyl
 */
class PterodactylProxmox extends Server
{
    public $resetModalContent;
    public $showResetModal;

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'Pterodactyl URL',
                'type' => 'text',
                'description' => 'Pterodactyl URL',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'api_key',
                'label' => 'Pterodactyl API Key',
                'type' => 'text',
                'description' => 'Pterodactyl API Key',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'server_prefix',
                'label' => 'Server Name Prefix',
                'type' => 'text',
                'description' => 'The prefix for the server name (e.g. PMX-)',
                'default' => 'PMX-',
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->request('/api/application/hyper-nodes', 'GET');
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    public function request($url, $method = 'get', $data = []): array
    {
        // Trim any leading slashes from the base url and add the path URL to it
        $req_url = rtrim($this->config('host'), '/') . $url;
        $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->config('api_key'),
                    'Accept' => 'application/json',
                ])->$method($req_url, $data);

        if (!$response->successful()) {
            $body = $response->json();
            logger()->debug('[pterodactyl] failed to execute api call', $body['errors']);
            throw new DisplayException($body['errors'][0]['detail']);
        }

        return $response->json() ?? [];
    }

    public function getProductConfig($values = []): array
    {
        $location = $this->request('/api/application/locations');
        $locationList = [];
        foreach ($location['data'] as $location) {
            $locationList[$location['attributes']['id']] = $location['attributes']['short'];
        }

        // Fetch Egg Profiles
        $eggProfileList = [];
        try {
            $eggProfiles = $this->request('/api/application/nests/egg-profiles');
            foreach ($eggProfiles['data'] as $profile) {
                $eggProfileList[$profile['attributes']['id']] = $profile['attributes']['label'];
            }
        } catch (Exception $e) {
            // Log it but don't fail, maybe the panel doesn't support egg profiles yet
            logger()->error('[pterodactyl] failed to fetch egg profiles', ['error' => $e->getMessage()]);
        }

        return [
            [
                'name' => 'location_ids',
                'label' => 'Location(s)',
                'type' => 'select',
                'description' => 'Location(s) where the server will be installed',
                'options' => $locationList,
                'multiple' => true,
                'database_type' => 'array',
                'required' => false,
            ],
            [
                'name' => 'egg_profile_id',
                'label' => 'Egg Profile',
                'type' => 'select',
                'description' => 'The egg profile to use for this node',
                'options' => $eggProfileList,
                'required' => false,
            ],
            [
                'name' => 'unlimited_resources',
                'label' => 'Unlimited Resources',
                'type' => 'checkbox',
                'description' => 'Allow this node to use unlimited resources',
                'required' => false,
            ],
            [
                'name' => 'max_servers',
                'label' => 'Max Servers',
                'type' => 'number',
                'description' => 'The maximum number of servers allowed on this node',
                'default' => 1,
                'required' => false,
            ],
            [
                'name' => 'memory',
                'label' => 'Memory',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => false,
                'validation' => 'numeric',
                'min_value' => 0,
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'disk',
                'label' => 'Disk',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => false,
                'min_value' => 0,
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'cpu',
                'label' => 'CPU Limit',
                'type' => 'number',
                'required' => false,
                'min_value' => 0,
                'suffix' => '%',
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'databases',
                'label' => 'Databases',
                'type' => 'number',
                'required' => false,
                'min_value' => 0,
            ],
            [
                'name' => 'backups',
                'label' => 'Backups',
                'type' => 'number',
                'required' => false,
                'min_value' => 0,
            ],
            [
                'name' => 'allocations',
                'label' => 'Additional Allocations',
                'type' => 'number',
                'required' => false,
                'min_value' => 0,
            ],
            [
                'name' => 'port_range',
                'label' => 'Port ranges',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    private function getOrCreateUserId($orderUser): int
    {
        return (int) $this->getOrCreateUser($orderUser)['id'];
    }

    private function getOrCreateUser($orderUser): array
    {
        // 1. Try to fetch an existing user by email
        $response = $this->request('/api/application/users', 'get', [
            'filter[email]' => $orderUser->email, // Pterodactyl uses filter[key] syntax
        ]);

        // Check if user exists in the response
        if (!empty($response['data'])) {
            // Returns the first user's attributes [id, username, email, first_name, etc.]
            return $response['data'][0]['attributes'];
        }

        // 2. If user doesn't exist, prepare creation data
        $username = preg_replace('/[^a-zA-Z0-9]/', '', strtolower(Str::transliterate($orderUser->name)))
            ?: Str::random(8);
        $username .= '_' . Str::random(4);

        $newUser = $this->request('/api/application/users', 'post', [
            'email' => $orderUser->email,
            'username' => $username,
            'first_name' => $orderUser->first_name ?: $orderUser->name, // Ensure not empty
            'last_name' => $orderUser->last_name ?: 'User',          // Pterodactyl requires these
        ]);

        // Return the newly created user's attributes
        return $newUser['attributes'];
    }

    public function getServerId(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id, false, raw: true);
        if (!$server) {
            return null;
        }

        $uuid = $server['attributes']['uuid'] ?? $server['attributes']['id'];
        $name = $server['attributes']['name'];

        // Take only the first UUID segment if it's a UUID
        $shortUuid = str_contains($uuid, '-') ? explode('-', $uuid)[0] : $uuid;

        return "{$shortUuid} - {$name}";
    }

    public function createServer(Service $service, $settings, $properties)
    {
        if ($this->getServer($service->id, failIfNotFound: false)) {
            throw new DisplayException('Hyper Node already exists');
        }
        // Smash the properties into the settings
        $settings = array_merge($settings, $properties);

        // Default values if null
        $defaults = [
            'memory' => 1024,
            'disk' => 10240,
            'cpu' => 100,
            'databases' => 0,
            'allocations' => 0,
            'backups' => 0,
            'location_ids' => [],
            'port_range' => [],
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($settings[$key]) || $settings[$key] === null || empty($settings[$key])) {
                $settings[$key] = $value;
            }
        }

        $orderUser = $service->user;
        $user = $this->getOrCreateUserId($orderUser);

        if (isset($settings['location'])) {
            $settings['location_ids'] = [$settings['location']];
        }

        if (isset($settings['location_id'])) {
            $settings['location_ids'] = [$settings['location_id']];
        }

        $portRanges = [];
        if (!empty($settings['port_range']) && is_string($settings['port_range'])) {
            $portRanges = array_map('trim', explode(',', $settings['port_range']));
        }

        $hyperNodeData = [
            'external_id' => (string) $service->id,
            'name' => isset($settings['servername']) ? $settings['servername'] : ($this->config('server_prefix') ?? 'PMX-') . $service->id,
            'user' => (int) $user,
            'owner_id' => (int) $user,
            'location_id' => !empty($settings['location_ids']) ? $settings['location_ids'][0] : null,
            'description' => 'Managed by Paymenter',
            'limits' => [
                'memory' => (int) $settings['memory'],
                'cpu' => (int) $settings['cpu'],
                'disk' => (int) $settings['disk'],
            ],
            'feature_limits' => [
                'databases' => (int) $settings['databases'],
                'allocations' => (int) $settings['allocations'],
                'backups' => (int) $settings['backups'],
            ],
            'max_servers' => (int) ($settings['max_servers'] ?? 10),
            'max_databases' => (int) $settings['databases'],
            'max_allocations' => (int) $settings['allocations'],
            'max_backups' => (int) $settings['backups'],
            'unlimited_resources' => (bool) ($settings['unlimited_resources'] ?? false),
            'deploy' => [
                'locations' => (array) $settings['location_ids'],
                'port_range' => $portRanges,
            ],
            'egg_profile_id' => !empty($settings['egg_profile_id']) ? (int) $settings['egg_profile_id'] : null,
            'billing_expire_date' => $service->expires_at ? $service->expires_at->format('Y-m-d') : null,
        ];

        logger()->debug('creating hyper node', ['data' => $hyperNodeData]);
        $server = $this->request('/api/application/hyper-nodes', 'post', $hyperNodeData);

        return [
            'server' => $server['attributes']['id'],
            'link' => rtrim($this->config('host'), '/') . '/admin/nodes/view/' . $server['attributes']['id'],
        ];
    }

    private function getServer($id, $failIfNotFound = true, $raw = false)
    {
        try {
            $response = $this->request('/api/application/hyper-nodes/external/' . $id);
        } catch (Exception $e) {
            if ($failIfNotFound) {
                throw new DisplayException('Hyper Node not found');
            } else {
                return false;
            }
        }
        if ($raw) {
            return $response;
        }

        return $response['attributes']['id'] ?? false;
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id, failIfNotFound: false);
        if (!$server) {
            return true;
        }

        $this->request('/api/application/hyper-nodes/' . $server . '/suspend', 'post');

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id);

        $this->request('/api/application/hyper-nodes/' . $server . '/unsuspend', 'post');

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id, failIfNotFound: false);
        if (!$server) {
            return true;
        }

        $this->request('/api/application/hyper-nodes/' . $server, 'delete');

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id, raw: true);

        $settings = array_merge($settings, $properties);

        $updateServerData = [
            'memory' => (int) $settings['memory'],
            'cpu' => (int) $settings['cpu'],
            'disk' => (int) $settings['disk'],
        ];

        $this->request('/api/application/hyper-nodes/' . $server['attributes']['id'] . '/upgrade', 'post', $updateServerData);

        return true;
    }

    public function migrateOption(string $key, ?string $value)
    {
        return match ($key) {
            'allocation' => ['key' => 'allocations', 'value' => $value],
            'location' => ['key' => 'location_ids', 'value' => json_encode([$value]), 'type' => 'array'],
            default => ['key' => $key, 'value' => $value]
        };
    }

    /**
     * Update the billing expire date of a server
     *
     * @param  string  $newDate  Format: Y-m-d H:i:s
     *
     * @throws Exception
     */
    public function updateBillingDate(Service $service, string $newDate): bool
    {
        $server = $this->getServer($service->id, raw: true);

        $updateData = [
            'name' => $server['attributes']['name'],
            'external_id' => $server['attributes']['external_id'],
            'user' => $server['attributes']['user'],
            'owner_id' => $server['attributes']['owner_id'],
            'description' => $server['attributes']['description'],
            'billing_expire_date' => $newDate,
        ];

        $this->request(
            '/api/application/hyper-nodes/' . $server['attributes']['id'] . '/update',
            'post',
            $updateData
        );

        return true;
    }

    public function boot()
    {
        Event::listen(
            ServiceEvent\Updated::class,
            function ($event) {
                try {
                    $this->updatedEvent($event);
                } catch (Exception $e) {
                    // Log the error
                    if (config('settings.debug', false)) {
                        throw $e;
                    }
                }
            }
        );
    }

    private function updatedEvent($event)
    {
        logger()->debug('event', ['event' => $event]);
        /** @var Service $service */
        $service = $event->service ?? null;

        if (!$service) {
            return;
        }

        $product = $service->product;
        $extension = $product->server->extension;

        if ($extension !== 'PterodactylProxmox') {
            logger()->debug('invalid extension, skipping');

            return;
        }

        try {
            $newDate = $service->expires_at;

            if ($newDate === null) {
                logger()->debug('Invalid date, skipping');

                return;
            }

            $this->updateBillingDate(
                $service,
                $newDate->format('Y-m-d H:i:s')
            );

            logger()->debug(
                "Updated billing date for service #{$service->id} to {$newDate->format('Y-m-d H:i:s')}"
            );

        } catch (Exception $e) {
            logger()->error("Failed to update billing date for service #{$service->id}: " . $e->getMessage());
        }
    }

    public function ssoLink(Service $service): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (empty($userAgent)) {
            throw new DisplayException('User agent cannot be empty.');
        }

        $orderUser = $service->user;
        if (!$orderUser->hasVerifiedEmail()) {
            return route('verification.notice');
        }

        $userId = $this->getOrCreateUserId($orderUser);
        $data = $this->request('/api/application/users/' . $userId . '/sso', 'post', [
            'user_agent' => $userAgent,
        ]);

        return rtrim($this->config('host'), '/') .
            sprintf('/auth/login/sso?token_id=%s&token=%s', $data['token_id'], $data['token']);
    }

    public function resetPassword(Service $service)
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (empty($userAgent)) {
            throw new DisplayException('User agent cannot be empty.');
        }

        $orderUser = $service->user;
        if (!$orderUser->hasVerifiedEmail()) {
            return route('verification.notice');
        }

        // 1. Generate the secure password locally first
        $newPassword = Str::password(16);

        $pterodactylUser = $this->getOrCreateUser($orderUser);
        $userId = $pterodactylUser['id'];

        $this->request('/api/application/users/' . $userId, 'patch', [
            'email' => $pterodactylUser['email'],
            'username' => $pterodactylUser['username'],
            'first_name' => $pterodactylUser['first_name'],
            'last_name' => $pterodactylUser['last_name'],
            'password' => $newPassword,
        ]);

        // 4. Update UI state
        $this->resetModalContent = $newPassword;
        $this->showResetModal = true;

        return ['reset_password' => $newPassword];
    }

    public function restartServer(Service $service)
    {
        $server = $this->getServer($service->id);
        $this->request('/api/application/hyper-nodes/' . $server . '/restart', 'post');

        return true;
    }

    public function getActions(Service $service): array
    {
        $orderUser = $service->user;
        $isVerified = $orderUser->hasVerifiedEmail();

        return [
            [
                'type' => 'button',
                'label' => 'Go to Panel',
                'function' => 'ssoLink',
                'disabled' => !$isVerified,
                'tooltip' => $isVerified ? null : 'You must verify your email to access the panel',
            ],
            [
                'type' => 'button',
                'label' => 'Restart Node',
                'function' => 'restartServer',
                'disabled' => !$isVerified,
                'tooltip' => $isVerified ? null : 'You must verify your email to restart the node',
            ],
            [
                'type' => 'button',
                'label' => 'Reset Password',
                'function' => 'resetPassword',
                'disabled' => !$isVerified,
                'tooltip' => $isVerified ? null : 'You must verify your email to reset the password',
            ],
        ];
    }
}
