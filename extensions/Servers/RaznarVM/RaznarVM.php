<?php

namespace Paymenter\Extensions\Servers\RaznarVM;

use App\Classes\Extension\Server;
use App\Events\Service as ServiceEvent;
use App\Exceptions\DisplayException;
use App\Models\Service;
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

    public function request($url, $method = 'get', $data = []): array
    {
        $req_url = rtrim($this->config('host'), '/') . $url;
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->config('api_key'),
            'Accept' => 'application/json',
        ])->$method($req_url, $data);

        if (!$response->successful()) {
            $body = $response->json();
            logger()->debug('[raznarvm] failed to execute api call', ['errors' => $body]);
            $errorMsg = $body['message'] ?? 'API Error';
            if (isset($body['errors']) && is_array($body['errors'])) {
                $errorMsg = collect($body['errors'])->first() ?? $errorMsg;
            }
            throw new DisplayException($errorMsg);
        }

        return $response->json() ?? [];
    }

    public function getProductConfig($values = []): array
    {
        $locations = $this->request('/api/admin/locations');
        $locationList = [];
        if (isset($locations['data'])) {
            foreach ($locations['data'] as $location) {
                $locationList[$location['id']] = $location['label'];
            }
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
        // Search by email
        $response = $this->request('/api/admin/users', 'get', [
            'email' => $orderUser->email,
        ]);

        if (!empty($response['data'])) {
            // Check if exact email matches, returning the first one
            foreach ($response['data'] as $user) {
                if ($user['email'] === $orderUser->email) {
                    return $user;
                }
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

        return $newUser['data'] ?? $newUser;
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
        if ($this->getServer($service->id, failIfNotFound: false)) {
            throw new DisplayException('Server already exists');
        }

        $settings = array_merge($settings, $properties);
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

        try {
            $server = $this->request('/api/admin/servers/deploy', 'post', $deploymentData);
        } catch (\Throwable $e) {
            logger()->error('Failed to create server via RaznarVM API', [
                'service_id' => $service->id,
                'payload' => $deploymentData,
                'error' => $e->getMessage(),
            ]);

            throw new DisplayException('Server deployment failed: ' . $e->getMessage());
        }

        $serverId = $server['data']['id'] ?? $server['id'] ?? null;
        if (!$serverId) {
            // Try fetching by external_id if ID misses in deployment response
            $serverDetails = $this->getServer($service->id, true, true);
            $serverId = $serverDetails['id'];
        }

        return [
            'server' => $serverId,
            'link' => rtrim($this->config('host'), '/') . '/client/servers/' . $serverId,
        ];
    }

    private function getServer($id, $failIfNotFound = true, $raw = false)
    {
        try {
            $response = $this->request('/api/admin/servers/external/' . $id);
            $data = $response['data'] ?? $response;
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
        $server = $this->getServer($service->id, failIfNotFound: false);
        if (!$server) {
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
        $server = $this->getServer($service->id, failIfNotFound: false);
        if (!$server) {
            return true;
        }

        $this->request('/api/admin/servers/external/' . $service->id, 'delete');

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $settings = array_merge($settings, $properties);

        $updateData = [
            'cpu' => (int) $settings['cpu'],
            'memory' => (int) $settings['memory'],
        ];

        if (isset($settings['max_traffic_in'])) {
            $updateData['max_traffic_in'] = (int) $settings['max_traffic_in'];
        }
        if (isset($settings['max_traffic_out'])) {
            $updateData['max_traffic_out'] = (int) $settings['max_traffic_out'];
        }

        $this->request('/api/admin/servers/external/' . $service->id, 'put', $updateData);

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
        
        $token = is_array($data) && isset($data['token']) ? $data['token'] : ($data['data']['token'] ?? '');

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
