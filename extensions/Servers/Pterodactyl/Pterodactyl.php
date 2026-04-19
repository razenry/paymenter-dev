<?php

namespace Paymenter\Extensions\Servers\Pterodactyl;

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
class Pterodactyl extends Server
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
            $errors = $body['errors'] ?? [];
            $detail = $errors[0]['detail'] ?? ('Pterodactyl API error (HTTP ' . $response->status() . ')');

            logger()->debug('[pterodactyl] failed to execute api call', [
                'url' => $url,
                'status' => $response->status(),
                'errors' => $errors,
            ]);

            throw new DisplayException($detail);
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

        $uuid = $server['attributes']['uuid'];
        $name = $server['attributes']['name'];

        // Take only the first UUID segment
        $shortUuid = explode('-', $uuid)[0];

        return "{$shortUuid} - {$name}";
    }

    public function createServer(Service $service, $settings, $properties)
    {
        if ($this->getServer($service->id, failIfNotFound: false)) {
            throw new DisplayException('Server already exists');
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
            throw new DisplayException('Could not fetch egg data');
        }
        $environment = [];
        foreach ($eggData['attributes']['relationships']['variables']['data'] as $variable) {
            $environment[$variable['attributes']['env_variable']] = $settings[$variable['attributes']['env_variable']] ?? $variable['attributes']['default_value'];
        }

        $orderUser = $service->user;
        $user = $this->getOrCreateUserId($orderUser);

        if (isset($settings['location'])) {
            $settings['location_ids'] = [$settings['location']];
        }

        if (isset($settings['location_id'])) {
            $settings['location_ids'] = [$settings['location_id']];
        }

        $deploymentData = $this->generateDeploymentData($settings, $environment);

        $serverCreationData = [
            'split_limit' => isset($settings['split_limit']) ? (int) $settings['split_limit'] : 0,
            'external_id' => (string) $service->id,
            'name' => isset($settings['servername']) ? $settings['servername'] : $service->product->name . ' #' . $service->id,
            'user' => (int) $user,
            'egg' => $settings['egg_id'],
            'docker_image' => isset($settings['docker_image']) ? $settings['docker_image'] : $eggData['attributes']['docker_image'],
            'startup' => $eggData['attributes']['startup'],
            'environment' => $deploymentData['environment'],
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
                'allocations' => $deploymentData['allocations_needed'] + (int) $settings['allocations'],
                'backups' => (int) $settings['backups'],
            ],
            'start_on_completion' => $settings['start_on_completion'] ?? false,
            'billing_expire_date' => $service->expires_at,
        ];
        if ($deploymentData['auto_deploy']) {
            $portRanges = [];
            if (!empty($settings['port_range']) && is_string($settings['port_range'])) {
                $portRanges = array_map('trim', explode(',', $settings['port_range']));
            }

            $serverCreationData['deploy'] = [
                'locations' => (array) $settings['location_ids'],
                'dedicated_ip' => $settings['dedicated_ip'] ?? false,
                'port_range' => $portRanges,
            ];
        } else {
            $serverCreationData['allocation'] = $deploymentData['allocation'];
        }

        try {
            $server = $this->request('/api/application/servers', 'post', $serverCreationData);
        } catch (\Throwable $e) {
            logger()->error('Failed to create server via Pterodactyl API', [
                'service_id' => $service->id,
                'payload' => $serverCreationData,
                'error' => $e->getMessage(),
            ]);

            throw new DisplayException('Server creation failed: ' . $e->getMessage());
        }

        return [
            'server' => $server['attributes']['id'],
            'link' => $this->config('host') . '/server/' . $server['attributes']['identifier'],
        ];
    }

    private function generateDeploymentData($settings, $environment)
    {
        if (!isset($settings['port_array']) || $settings['port_array'] === '') {
            if (!empty($settings['node'])) {
                // Only get one allocation from the node
                $nodes = $this->request('/api/application/nodes/deployable', 'get', [
                    'memory' => $settings['memory'],
                    'disk' => $settings['disk'],
                    'location_ids' => $settings['location_ids'] ?? [],
                    'include' => ['allocations'],
                ]);
                $nodes = collect($nodes['data']);
                $nodes_by_id = $nodes->mapWithKeys(fn($node) => [$node['attributes']['id'] => $node['attributes']]);

                if (!$nodes_by_id->has($settings['node'])) {
                    throw new DisplayException('Node is not suitable for deployment.');
                }
                $node = $nodes_by_id->get($settings['node']);
                $availablePorts = collect($node['relationships']['allocations']['data']);
                $availablePorts = $availablePorts
                    ->filter(fn($port) => !$port['attributes']['assigned'])
                    ->map(
                        fn($port) => [
                            'port' => $port['attributes']['port'],
                            'id' => $port['attributes']['id'],
                        ]
                    );
                if ($availablePorts->isEmpty()) {
                    throw new DisplayException('No available allocations found on the selected node.');
                }
                $allocation = $availablePorts->first();
                $environment['SERVER_PORT'] = $allocation['port'];

                // Return the allocation id for the SERVER_PORT
                return [
                    'auto_deploy' => false,
                    'environment' => $environment,
                    'allocations_needed' => 1,
                    'allocation' => [
                        'default' => $allocation['id'],
                        'additional' => [],
                    ],
                ];
            }

            return [
                'auto_deploy' => true,
                'environment' => $environment,
                'allocations_needed' => 1,
            ];
        }

        try {
            $input = trim($settings['port_array']);
            $port_array = json_decode($input, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $ports = [];

                // Split by comma
                foreach (explode(',', $input) as $part) {
                    $part = trim($part);
                    if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
                        // Range
                        $start = (int) $m[1];
                        $end = (int) $m[2];
                        if ($start > $end) {
                            [$start, $end] = [$end, $start];
                        }
                        $ports = array_merge($ports, range($start, $end));
                    } elseif (is_numeric($part)) {
                        // Single number
                        $ports[] = (int) $part;
                    }
                }

                if (empty($ports)) {
                    throw new DisplayException('Invalid port array format');
                }

                $port_array = ['NONE' => $ports];
            }
        } catch (Exception $e) {
            throw new DisplayException('Invalid port array input');
        }

        if (!is_array($port_array)) {
            throw new DisplayException('Port array must be an array');
        }

        $nodes = $this->request('/api/application/nodes/deployable', 'get', [
            'memory' => $settings['memory'],
            'disk' => $settings['disk'],
            'location_ids' => $settings['location_ids'] ?? [],
            'include' => ['allocations'],
        ]);
        $nodes = collect($nodes['data']);
        $nodes_by_id = $nodes->mapWithKeys(fn($node) => [$node['attributes']['id'] => $node['attributes']]);

        if ($settings['node']) {
            // If the product's node id is not in the deployable nodes array, throw error.
            if (!$nodes_by_id->has($settings['node'])) {
                throw new DisplayException('Node is not suitable for deployment.');
            }

            $node = $nodes_by_id->get($settings['node']);
            $availablePorts = collect($node['relationships']['allocations']['data']);
            $availablePorts = $availablePorts
                ->filter(fn($port) => !$port['attributes']['assigned'])
                ->map(
                    fn($port) => [
                        'port' => $port['attributes']['port'],
                        'id' => $port['attributes']['id'],
                    ]
                );

            $free_allocations_needed = 0;
            foreach ($port_array as $key => $value) {
                $free_allocations_needed += is_array($value) ? count($value) : 1;
            }

            if (count($availablePorts) < $free_allocations_needed) {
                throw new DisplayException("Not enough allocations found for deployment. Found: {$availablePorts->count()}, Required: {$free_allocations_needed}");
            }
        } else {
            foreach ($nodes as $index => $node) {
                $availablePorts = collect($node['attributes']['relationships']['allocations']['data']);
                $availablePorts = $availablePorts
                    ->filter(fn($port) => !$port['attributes']['assigned'])
                    ->map(
                        fn($port) => [
                            'port' => $port['attributes']['port'],
                            'id' => $port['attributes']['id'],
                        ]
                    );

                $free_allocations_needed = 0;
                foreach ($port_array as $key => $value) {
                    $free_allocations_needed += is_array($value) ? count($value) : 1;
                }

                if (count($availablePorts) < $free_allocations_needed) {
                    // If this was last viable node, throw error
                    if ($index == $nodes->count() - 1) {
                        throw new DisplayException('No nodes with suitable allocations found for deployment');
                    }

                    // Else move onto next viable node
                    continue;
                }
                break;
            }
        }

        $allocations = [];
        foreach ($port_array as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $port) {
                    $allocation = $availablePorts->where('port', $port)->first();
                    if (!$allocation) {
                        // try to assign a higher port, if that fails try a random port
                        $allocation = $availablePorts->where('port', '>', $port)->first();
                        if (!$allocation) {
                            $allocation = $availablePorts->random();
                        }
                        if (!$allocation) {
                            throw new DisplayException('Could not find a port to assign');
                        }
                    }
                    $allocations[$key][] = $allocation;

                    // Remove the port from the available ports
                    $availablePorts = $availablePorts->reject(function ($port) use ($allocation) {
                        return $port['id'] == $allocation['id'];
                    });
                }
            } else {
                $allocation = $availablePorts->where('port', $value)->first();
                if (!$allocation) {
                    // try to assign a higher port, if that fails try a random port
                    $allocation = $availablePorts->where('port', '>', $value)->first();
                    if (!$allocation) {
                        $allocation = $availablePorts->random();
                    }
                    if (!$allocation) {
                        throw new DisplayException('Could not find a port to assign');
                    }
                }
                $allocations[$key] = $allocation;

                // Remove the port from the available ports
                $availablePorts = $availablePorts->reject(function ($port) use ($allocation) {
                    return $port['id'] == $allocation['id'];
                });
            }
        }

        $allocationIds = [];

        foreach ($allocations as $key => $value) {
            // Assign the allocations to the environment
            if ($key !== 'NONE') {
                if (isset($environment[$key])) {
                    $environment[$key] = $value['port'];
                }
            }

            // Set allocations to a array with only the ids
            if ($key !== 'SERVER_PORT') {
                if (is_array($value) && isset($value[0])) {
                    foreach ($value as $v) {
                        $allocationIds[] = $v['id'];
                    }
                } else {
                    $allocationIds[] = $value['id'];
                }
            }
        }

        return [
            'auto_deploy' => false,
            'allocations_needed' => $free_allocations_needed,
            'environment' => $environment,
            'allocation' => [
                'default' => $allocations['SERVER_PORT']['id'],
                'additional' => $allocationIds,
            ],
        ];
    }

    private function getServer($id, $failIfNotFound = true, $raw = false)
    {
        try {
            $response = $this->request('/api/application/servers/external/' . $id);
        } catch (Exception $e) {
            if ($failIfNotFound) {
                throw new DisplayException('Server not found');
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
            'allocation' => $server['attributes']['allocation'],
            'memory' => (int) $settings['memory'],
            'swap' => (int) $settings['swap'],
            'disk' => (int) $settings['disk'],
            'io' => (int) $settings['io'],
            'cpu' => (int) $settings['cpu'],
            'threads' => $settings['cpu_pinning'] ?? null,
            'feature_limits' => [
                'databases' => $settings['databases'],
                'allocations' => $settings['allocations'],
                'backups' => $settings['backups'],
            ],
        ];

        $this->request('/api/application/servers/' . $server['attributes']['id'] . '/build', 'patch', $updateServerData);

        $eggData = $this->request('/api/application/nests/' . $settings['nest_id'] . '/eggs/' . $settings['egg_id'], data: ['include' => 'variables']);

        if (!isset($eggData['attributes'])) {
            throw new DisplayException('Could not fetch egg data');
        }

        $environment = [];

        foreach ($eggData['attributes']['relationships']['variables']['data'] as $variable) {
            // Check if variable has been set on server
            if (isset($server['attributes']['container']['environment'][$variable['attributes']['env_variable']])) {
                $environment[$variable['attributes']['env_variable']] = $server['attributes']['container']['environment'][$variable['attributes']['env_variable']];
            } else {
                $environment[$variable['attributes']['env_variable']] = $settings[$variable['attributes']['env_variable']] ?? $variable['attributes']['default_value'];
            }
        }

        $updateServerData = [
            'environment' => $environment,
            'skip_scripts' => $settings['skip_scripts'] ?? false,
            'oom_disabled' => !($settings['oom_killer'] ?? false),
            'egg' => $settings['egg_id'],
            'image' => $server['attributes']['container']['image'] ?? $eggData['attributes']['docker_image'],
            'startup' => $server['attributes']['container']['startup_command'] ?? $settings['startup'] ?? $eggData['attributes']['startup'],
        ];

        $this->request('/api/application/servers/' . $server['attributes']['id'] . '/startup', 'patch', $updateServerData);

        return true;
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

        if ($extension !== 'Pterodactyl') {
            logger()->debug('invalid extension, skipping');

            return;
        }

        try {
            // Use expires_at from the service as the billing date
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
