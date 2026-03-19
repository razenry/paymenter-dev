<?php

namespace Paymenter\Extensions\Servers\RaznarVM;

use App\Classes\Extension\Server;
use App\Events\Service as ServiceEvent;
use App\Exceptions\DisplayException;
use App\Models\Service;
use App\Jobs\Extensions\RaznarVM\DeployServer;
use Exception;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RaznarVM extends Server
{
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'RaznarVM URL',
                'type' => 'text',
                'description' => 'The absolute URL to your RaznarVM panel (e.g. https://panel.example.com)',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'api_key',
                'label' => 'RaznarVM Admin API Key',
                'type' => 'text',
                'description' => 'Admin API Key with permissions',
                'required' => true,
                'encrypted' => true,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->request('/api/admin/servers', 'GET');
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    public function request($url, $method = 'get', $data = []): array|string
    {
        $req_url = rtrim($this->config('host'), '/') . $url;
        logger()->debug('[raznarvm] executing api call', [
            'url' => $url,
            'method' => $method,
            'data' => $data,
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->config('api_key'),
            'Accept' => 'application/json',
        ])->$method($req_url, $data);

        if (!$response->successful()) {
            $body = $response->json() ?? [];
            logger()->debug('[raznarvm] failed to execute api call', [
                'status' => $response->status(),
                'errors' => $body
            ]);
            
            $errorMsg = is_array($body) ? ($body['message'] ?? 'API Error') : 'API Error';
            if (is_array($body) && isset($body['errors']) && is_array($body['errors'])) {
                $errorMsg = collect($body['errors'])->first() ?? $errorMsg;
            }
            throw new DisplayException($errorMsg);
        }

        $body = $response->json();
        if (empty($body)) {
            $body = $response->body();
        }

        logger()->debug('[raznarvm] api call successful', [
            'url' => $url,
            'response_type' => gettype($body),
            'raw_body' => $response->body()
        ]);

        return $body ?? [];
    }

    public function getProductConfig($values = []): array
    {
        $locations = $this->request('/api/admin/locations');
        $locationList = [];
        $items = $locations['data']['items'] ?? $locations['data'] ?? [];
        foreach ($items as $location) {
            $locationList[$location['id']] = $location['label'];
        }

        return [
            [
                'name' => 'location_id',
                'label' => 'Location',
                'type' => 'select',
                'description' => 'Location where the server will be deployed',
                'options' => $locationList,
                'required' => true,
            ],
            [
                'name' => 'cpu',
                'label' => 'CPU Cores',
                'type' => 'number',
                'required' => true,
                'min_value' => 1,
                'description' => 'Number of CPU cores',
            ],
            [
                'name' => 'memory',
                'label' => 'Memory',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => true,
                'min_value' => 128,
            ],
            [
                'name' => 'disk_size',
                'label' => 'Disk Size',
                'type' => 'number',
                'suffix' => 'GB',
                'required' => true,
                'min_value' => 1,
            ],
            [
                'name' => 'max_traffic_in',
                'label' => 'Max Traffic In',
                'type' => 'number',
                'required' => false,
                'default' => 0,
                'description' => '0 for unlimited',
            ],
            [
                'name' => 'max_traffic_out',
                'label' => 'Max Traffic Out',
                'type' => 'number',
                'required' => false,
                'default' => 0,
                'description' => '0 for unlimited',
            ],
            [
                'name' => 'network_rate',
                'label' => 'Network Rate',
                'type' => 'number',
                'required' => false,
                'default' => 0,
                'description' => 'Network speed limit',
            ],
        ];
    }

    private function getOrCreateUserId($orderUser): int
    {
        return (int) $this->getOrCreateUser($orderUser)['id'];
    }

    private function getOrCreateUser($orderUser): array
    {
        $response = $this->request('/api/admin/users', 'get', [
            'email' => $orderUser->email,
        ]);

        $data = $response['data'] ?? [];
        $users = is_array($data) ? ($data['items'] ?? $data) : [];

        foreach ($users as $user) {
            if (($user['email'] ?? null) === $orderUser->email) {
                return $user;
            }
        }

        // Generate strong password
        $password = Str::password(16);

        $username = preg_replace('/[^a-zA-Z0-9]/', '', strtolower(Str::transliterate($orderUser->name))) ?: Str::random(8);
        $username .= '_' . Str::random(4);

        $newUser = $this->request('/api/admin/users', 'post', [
            'email' => $orderUser->email,
            'username' => $username,
            'first_name' => $orderUser->first_name ?: $orderUser->name,
            'last_name' => $orderUser->last_name ?: 'User',
            'password' => $password,
            'is_admin' => false,
        ]);

        logger()->debug('[raznarvm] created new user', [
            'user_id' => $newUser['data']['id'] ?? $newUser['id'] ?? 'unknown'
        ]);

        return $newUser['data'] ?? $newUser ?? [];
    }

    public function getServerId(Service $service, $settings, $properties)
    {
        $server = $this->getServer($service->id, false, raw: true);
        if (!$server) {
            return null;
        }

        return "{$server['id']} - {$server['name']}";
    }

    public function createServer(Service $service, $settings, $properties)
    {
        // 1. Initial Checks (Short Checking)
        if ($this->getServer($service->id, failIfNotFound: false)) {
            throw new DisplayException('Server already exists in RaznarVM');
        }

        $settings = array_merge($settings, $properties);
        
        if (empty($settings['location_id'])) {
            throw new DisplayException('Location ID is missing. Please check your product settings.');
        }

        // 2. Pre-flight user setup (so it's already done before the job)
        logger()->debug('[raznarvm] pre-flight: setting up user');
        $user = $this->getOrCreateUserId($service->user);
        
        $serverName = isset($settings['servername']) ? $settings['servername'] : $service->product->name . ' #' . $service->id;

        $deploymentData = [
            'location_id' => (int) $settings['location_id'],
            'owner_id' => $user,
            'external_id' => (string) $service->id,
            'disk_size' => (int) $settings['disk_size'],
            'name' => $serverName,
            'cpu' => (int) $settings['cpu'],
            'memory' => (int) $settings['memory'],
            'max_traffic_in' => (int) ($settings['max_traffic_in'] ?? 0),
            'max_traffic_out' => (int) ($settings['max_traffic_out'] ?? 0),
            'network_rate' => isset($settings['network_rate']) ? (int) $settings['network_rate'] : null,
            'dns1' => '1.1.1.1',
            'dns2' => '8.8.8.8',
            'dns1_v6' => '2606:4700:4700::1111',
            'dns2_v6' => '2606:4700:4700::1001',
        ];

        // 3. Move it to a background job
        try {
            DeployServer::dispatch($service, $deploymentData, $this->config('host'), $this->config('api_key'));
            logger()->debug('[raznarvm] server deployment job dispatched', ['service_id' => $service->id]);
        } catch (\Throwable $e) {
            logger()->error('Failed to dispatch server creation job via RaznarVM', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            throw new DisplayException('Failed to queue server deployment: ' . $e->getMessage());
        }

        return [
            'server' => 'Provisioning...',
            'link' => rtrim($this->config('host'), '/') . '/client/servers',
        ];
    }

    private function getServer($id, $failIfNotFound = true, $raw = false)
    {
        try {
            // Check if we have an internal ID stored
            $internalId = Service::find($id)?->properties()->where('key', 'server')->first()?->value;
            
            if ($internalId) {
                $response = $this->request('/api/servers/' . $internalId);
                $data = $response['data'] ?? $response;
            } else {
                $response = $this->request('/api/admin/servers/external/' . $id);
                $data = $response['data'] ?? $response;
            }
        } catch (Exception $e) {
            if ($failIfNotFound) {
                throw new DisplayException('Server not found');
            } else {
                return false;
            }
        }

        if ($raw) {
            return $data;
        }

        return $data['id'] ?? false;
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        logger()->debug('[raznarvm] suspending server', ['service_id' => $service->id]);
        $server = $this->getServer($service->id, failIfNotFound: false);
        if (!$server) {
            logger()->debug('[raznarvm] server not found for suspension, skipping');
            return true;
        }

        $this->request('/api/admin/servers/external/' . $service->id, 'put', [
            'status' => 'suspended',
        ]);

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $this->request('/api/admin/servers/external/' . $service->id, 'put', [
            'status' => 'active',
        ]);

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        logger()->debug('[raznarvm] terminating server', ['service_id' => $service->id]);
        $server = $this->getServer($service->id, failIfNotFound: false);
        if (!$server) {
            logger()->debug('[raznarvm] server not found for termination, skipping');
            return true;
        }

        $this->request('/api/admin/servers/external/' . $service->id, 'delete');

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $settings = array_merge($settings, $properties);

        // 1. Fetch current server details (disk_id is included here)
        $response = $this->request("/api/admin/servers/external/{$service->id}", 'get');
        $server = $response['data'] ?? $response ?? [];

        if (empty($server)) {
            throw new DisplayException('Failed to retrieve current server details from RaznarVM.');
        }

        $current = [
            'cpu' => (int) ($server['cpu'] ?? $server['cores'] ?? 0),
            'memory' => (int) ($server['memory'] ?? 0),
            'disk' => (int) ($server['disk_size'] ?? 0),
        ];

        $target = [
            'cpu' => (int) ($settings['cpu'] ?? 0),
            'memory' => (int) ($settings['memory'] ?? 0),
            'disk' => (int) ($settings['disk_size'] ?? 1),
        ];

        logger()->debug('[raznarvm] Starting server upgrade', compact('current', 'target'));

        $this->validateUpgradeResources($current, $target);

        // 2. Prepare upgrade payload
        $upgradeData = [
            'cpu' => $target['cpu'],
            'memory' => $this->normalizeVmMemory($target['memory']),
            'network_rate' => (int) ($settings['network_rate'] ?? $server['network_rate'] ?? 0),
        ];

        // Get disk_id directly from server data
        if (isset($server['disk_id'])) {
            $upgradeData['disks'] = [
                [
                    'id' => (int) $server['disk_id'],
                    'size' => (int) $target['disk'],
                ]
            ];
        }

        try {
            // 3. Execute the upgrade
            logger()->debug('[raznarvm] Sending POST upgrade request', $upgradeData);
            $this->request("/api/admin/servers/external/{$service->id}/upgrade", 'post', $upgradeData);
            
            logger()->debug('[raznarvm] Upgrade sequence processed');
        } catch (Exception $e) {
            logger()->error('[raznarvm] Upgrade failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return true;
    }

    private function validateUpgradeResources(array &$current, array &$target): void
    {
        logger()->debug('[raznarvm] Validating upgrade (no downgrade allowed)');

        if ($target['disk'] < 1) {
            return;
        }

        if ($target['disk'] < $current['disk']) {
            throw new DisplayException("You can't downgrade the disk!");
        }

        // Force target resources to be at least current state if requested upgrade is smaller
        $target['cpu'] = max($target['cpu'], $current['cpu']);
        $target['memory'] = max($target['memory'], $current['memory']);
        $target['disk'] = max($target['disk'], $current['disk']);
    }


    private function normalizeVmMemory(int $memory): int
    {
        $normalized = ($memory / 1024) === (int) ($memory / 1024)
            ? $memory
            : (int) round($memory * 1.024);

        logger()->debug('[raznarvm] Normalized memory output', [
            'input' => $memory,
            'output' => $normalized,
        ]);

        return $normalized;
    }


    public function boot()
    {
        Event::listen(
            ServiceEvent\Updated::class,
            function ($event) {
                try {
                    $this->updatedEvent($event);
                } catch (Exception $e) {
                    if (config('settings.debug', false)) {
                        throw $e;
                    }
                }
            }
        );
    }

    private function updatedEvent($event)
    {
        // RaznarVM API does not have a direct endpoint for updating the billing date currently.
        // We will just return, as it's not strictly necessary. 
        // If needed in the future, we could update the server notes with the billing date.
        return;
    }

    public function ssoLink(Service $service): string
    {
        $orderUser = $service->user;
        if (!$orderUser->hasVerifiedEmail()) {
            return route('verification.notice');
        }

        $userId = $this->getOrCreateUserId($orderUser);
        $data = $this->request('/api/admin/users/' . $userId . '/sso', 'get');

        $token = is_array($data) ? ($data['data'] ?? $data['token'] ?? '') : $data;

        return rtrim($this->config('host'), '/') . '/auth/sso?token=' . $token;
    }

    public function resetPassword(Service $service)
    {
        $orderUser = $service->user;
        if (!$orderUser->hasVerifiedEmail()) {
            throw new DisplayException('You must verify your email to reset the password');
        }

        $newPassword = Str::password(16);
        $userId = $this->getOrCreateUserId($orderUser);

        $this->request('/api/admin/users/' . $userId, 'put', [
            'password' => $newPassword,
        ]);

        return ['reset_password' => $newPassword];
    }

    public function getActions(Service $service): array
    {
        $orderUser = $service->user;
        $isVerified = $orderUser->hasVerifiedEmail();

        return [
            [
                'type' => 'button',
                'label' => 'Go to Console',
                'function' => 'ssoLink',
                'disabled' => !$isVerified,
                'tooltip' => $isVerified ? null : 'You must verify your email to access the panel',
            ],
            [
                'type' => 'button',
                'label' => 'Reset VM User Password',
                'function' => 'resetPassword',
                'disabled' => !$isVerified,
                'tooltip' => $isVerified ? null : 'You must verify your email to reset the password',
            ],
        ];
    }
}
