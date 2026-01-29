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
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->request('/api/application/servers', 'GET');
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
            logger()->error('[pterodactyl] failed to execute api call', $body['errors']);
            throw new Exception($body['errors'][0]['detail']);
        }

        return $response->json() ?? [];
    }

    public function getProductConfig($values = []): array
    {
        $nodes = $this->request('/api/application/nodes');
        $nodeList = [];
        foreach ($nodes['data'] as $node) {
            $nodeList[$node['attributes']['id']] = $node['attributes']['name'];
        }

        $location = $this->request('/api/application/locations');
        $locationList = [];
        foreach ($location['data'] as $location) {
            $locationList[$location['attributes']['id']] = $location['attributes']['short'];
        }

        $nests = $this->request('/api/application/nests');
        $nestList = [];
        foreach ($nests['data'] as $nest) {
            $nestList[$nest['attributes']['id']] = $nest['attributes']['name'];
        }

        $eggList = [];
        if (isset($values['nest_id']) && $values['nest_id'] !== '') {
            $eggs = $this->request('/api/application/nests/' . $values['nest_id'] . '/eggs');
            foreach ($eggs['data'] as $egg) {
                $eggList[$egg['attributes']['id']] = $egg['attributes']['name'];
            }
        }

        $using_port_array = isset($values['port_array']) && $values['port_array'] !== '';

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
                'name' => 'node',
                'label' => 'Node',
                'type' => 'select',
                'required' => false,
                'description' => 'Fill in to install the server on a specific node',
                'options' => $nodeList,
            ],
            [
                'name' => 'nest_id',
                'label' => 'Nest ID',
                'type' => 'select',
                'options' => $nestList,
                'description' => 'Nest ID to fetch the eggs from',
                'required' => false,
                // Lets fetch the eggs every time the nest id changes
                'live' => true,
            ],
            [
                'name' => 'egg_id',
                'label' => 'Egg ID',
                'type' => 'select',
                'options' => $eggList,
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
                'name' => 'swap',
                'label' => 'Swap',
                'type' => 'number',
                'min_value' => -1,
                'suffix' => 'MiB',
                'required' => false,
                'description' => 'Set to -1 for unlimited, or to 0 to disable swap',
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
                'name' => 'io',
                'label' => 'IO Weight',
                'type' => 'number',
                'required' => false,
                'default' => 500,
                'min_value' => 10,
                'max_value' => 1000,
                'description' => 'The IO Weight is the priority given to this server for disk access.',
                'hint' => new HtmlString('<a href="https://docs.docker.com/engine/reference/run/#block-io-bandwidth-blkio-constraint" target="_blank">Documentation</a>'),
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
                'name' => 'cpu_pinning',
                'label' => 'CPU Pinning',
                'type' => 'text',
                'description' => 'Leave empty for no pinning. Used to specify what threads should be used. Example: 0,2-4,5,6',
                'validation' => 'regex:/^[0-9]+(?:-[0-9]+)?(?:,[0-9]+(?:-[0-9]+)?)*$/',
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
                // IMPORTANT: CHANGED FROM additional_allocations
                'name' => 'allocations',
                'label' => 'Additional Allocations',
                'type' => 'number',
                'required' => false,
                'min_value' => 0,
            ],
            [
                'name' => 'port_array',
                'label' => 'Port Array',
                'type' => 'text',
                'description' => 'Used to assign ports to egg variables.',
                'hint' => new HtmlString('<a href="https://paymenter.org/docs/extensions/pterodactyl#port-array" target="_blank">Documentation</a>'),
                'live' => true,
                'validation' => 'json',
            ],
            [
                'name' => 'port_range',
                'label' => 'Port ranges',
                'type' => 'text',
                'required' => false,
                'disabled' => $using_port_array,
            ],
            [
                'name' => 'skip_scripts',
                'label' => 'Skip Egg Install Script',
                'description' => 'If the selected Egg has an install script attached to it, the script will run during the install. If you would like to skip this step, check this box.',
                'type' => 'checkbox',
            ],
            [
                'name' => 'dedicated_ip',
                'label' => 'Dedicated IP',
                'description' => 'Assigns the server an allocation whose IP is not being used by any other server.',
                'type' => 'checkbox',
                'disabled' => $using_port_array,
            ],
            [
                'name' => 'start_on_completion',
                'label' => 'Start on completion',
                'description' => 'Start server automatically after installation.',
                'type' => 'checkbox',
            ],
            [
                'name' => 'oom_killer',
                'label' => 'Enable OOM Killer',
                'description' => 'Terminates the server if it breaches the memory limits. Enabling OOM killer may cause server processes to exit unexpectedly.',
                'type' => 'checkbox',
            ],
            [
                'name' => 'split_limit',
                'label' => 'Split Limit',
                'type' => 'number',
                'required' => false,
                'min_value' => 0,
            ],
        ];
    }

    private function getOrCreateUser($orderUser): int
    {
        // Try to fetch an existing user by email
        $response = $this->request('/api/application/users', 'get', [
            'filter' => ['email' => $orderUser->email],
        ]);

        if (!empty($response['data'][0]['attributes']['id'])) {
            return (int) $response['data'][0]['attributes']['id'];
        }

        // If user doesn't exist, create a new one
        $username = preg_replace('/[^a-zA-Z0-9]/', '', strtolower(Str::transliterate($orderUser->name)))
            ?? Str::random(8);
        $username .= '_' . Str::random(4);

        $newUser = $this->request('/api/application/users', 'post', [
            'email' => $orderUser->email,
            'username' => $username,
            'first_name' => $orderUser->first_name ?? '',
            'last_name' => $orderUser->last_name ?? '',
        ]);

        return (int) $newUser['attributes']['id'];
    }

    public function createServer(Service $service, $settings, $properties)
    {
        if ($this->getServer($service->id, failIfNotFound: false)) {
            throw new Exception('Server already exists');
        }
        // Smash the properties into the settings
        $settings = array_merge($settings, $properties);

        // Default values if null
        $defaults = [
            'memory' => 0,
            'swap' => 0,
            'disk' => 0,
            'io' => 500,
            'cpu' => 0,
            'cpu_pinning' => null,
            'databases' => 0,
            'allocations' => 0,
            'backups' => 0,
            'split_limit' => 0,
            'skip_scripts' => false,
            'oom_killer' => false,
            'start_on_completion' => false,
            'dedicated_ip' => false,
            'location_ids' => [],
            'port_range' => [],
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($settings[$key]) || $settings[$key] === null || empty($settings[$key])) {
                $settings[$key] = $value;
            }
        }

        $eggData = $this->request('/api/application/nests/' . $settings['nest_id'] . '/eggs/' . $settings['egg_id'], data: ['include' => 'variables']);
        if (!isset($eggData['attributes'])) {
            throw new Exception('Could not fetch egg data');
        }
        $environment = [];
        foreach ($eggData['attributes']['relationships']['variables']['data'] as $variable) {
            $environment[$variable['attributes']['env_variable']] = $settings[$variable['attributes']['env_variable']] ?? $variable['attributes']['default_value'];
        }

        $orderUser = $service->user;
        $user = $this->getOrCreateUser($orderUser);

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

        $serverCreationData = [
            'split_limit' => isset($settings['split_limit']) ? (int) $settings['split_limit'] : 0,

            'external_id' => (string) $service->id,
            'name' => isset($settings['servername']) ? $settings['servername'] : $service->product->name . ' #' . $service->id,
            'user' => (int) $user,
            'egg' => $settings['egg_id'],
            'docker_image' => isset($settings['docker_image']) ? $settings['docker_image'] : $eggData['attributes']['docker_image'],
            'startup' => $eggData['attributes']['startup'],
            'environment' => $environment,
            'skip_scripts' => $settings['skip_scripts'] ?? false,
            'oom_disabled' => !($settings['oom_killer'] ?? false),
            'limits' => [
                'memory' => (int) $settings['memory'],
                'swap' => (int) $settings['swap'],
                'disk' => (int) $settings['disk'],
                'io' => (int) $settings['io'],
                'threads' => $settings['cpu_pinning'] ?? null,
                'cpu' => (int) $settings['cpu'],
            ],
            'feature_limits' => [
                'databases' => (int) $settings['databases'],
                'allocations' => (int) $settings['allocations'],
                'backups' => (int) $settings['backups'],
            ],
            'deploy' => [
                'locations' => (array) $settings['location_ids'],
                'dedicated_ip' => false,
                'port_range' => $portRanges,
            ],
            'start_on_completion' => $settings['start_on_completion'] ?? false,
            'billing_expire_date' => $service->expires_at,
        ];

        logger()->debug('creating server', ['data' => $serverCreationData]);
        $server = $this->request('/api/application/private-servers', 'post', $serverCreationData);

        return [
            'server' => $server['attributes']['id'],
            'link' => $this->config('host') . '/server/' . $server['attributes']['identifier'],
        ];
    }

    private function getServer($id, $failIfNotFound = true, $raw = false)
    {
        try {
            $response = $this->request('/api/application/servers/external/' . $id);
        } catch (Exception $e) {
            if ($failIfNotFound) {
                throw new Exception('Server not found');
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

        $this->request('/api/application/servers/' . $server . '/suspend', 'post');

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id);

        $this->request('/api/application/servers/' . $server . '/unsuspend', 'post');

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id, failIfNotFound: false);
        if (!$server) {
            return true;
        }

        $this->request('/api/application/servers/' . $server, 'delete');

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id, raw: true);

        $settings = array_merge($settings, $properties);

        $updateServerData = [
            'memory' => (int) $settings['memory'],
            'swap' => (int) $settings['swap'],
            'disk' => (int) $settings['disk'],
            'io' => (int) $settings['io'],
            'cpu' => (int) $settings['cpu'],
            'feature_limits' => [
                'databases' => $settings['databases'],
                'allocations' => $settings['allocations'],
                'backups' => $settings['backups'],
            ],
        ];

        $this->request('/api/application/private-servers/' . $server['attributes']['id'] . '/upgrade', 'post', $updateServerData);

        return true;
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

        $userId = $this->getOrCreateUser($orderUser);
        $data = $this->request('/api/application/users/' . $userId . '/sso', 'post', [
            'user_agent' => $userAgent,
        ]);

        return rtrim($this->config('host'), '/') .
            sprintf('/auth/login/sso?token_id=%s&token=%s', $data['token_id'], $data['token']);
    }

    public function migrateOption(string $key, ?string $value)
    {
        return match ($key) {
            'egg' => ['key' => 'egg_id', 'value' => $value],
            'nest' => ['key' => 'nest_id', 'value' => $value],
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
            'description' => $server['attributes']['description'],
            'billing_expire_date' => $newDate,
        ];

        $this->request(
            '/api/application/servers/' . $server['attributes']['id'] . '/details',
            'patch',
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

    public function changePassword(int $userId, string $newPassword): bool
    {
        if (empty($newPassword)) {
            throw new Exception('Password cannot be empty.');
        }

        // Update the user's password via Pterodactyl API
        $this->request('/api/application/users/' . $userId, 'patch', [
            'password' => $newPassword,
        ]);

        return true;
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

        // $userId = $this->getOrCreateUser($orderUser);
        // $data = $this->request('/api/application/users/' . $userId . '/sso', 'post', [
        //     'user_agent' => $userAgent,
        // ]);

        return [
            'msg' => 'ayoo',
        ];
    }

    public function getActions(Service $service): array
    {
        $orderUser = $service->user;
        $isVerified = $orderUser->hasVerifiedEmail();

        return [
            [
                'type' => 'button',
                'label' => 'Go to Server',
                'function' => 'ssoLink',
                'disabled' => !$isVerified,
                'tooltip' => $isVerified ? null : 'You must verify your email to access the panel',
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
