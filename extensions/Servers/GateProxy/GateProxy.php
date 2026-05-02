<?php

namespace Paymenter\Extensions\Servers\GateProxy;

use App\Classes\Extension\Server;
use App\Exceptions\DisplayException;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\Http;

class GateProxy extends Server
{
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'Gate Proxy Panel URL',
                'type' => 'text',
                'description' => 'The absolute URL to your Gate Proxy panel (e.g. https://proxy.example.com)',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'api_key',
                'label' => 'Proxy Panel API Key',
                'type' => 'text',
                'description' => 'The Admin API Key created in the proxy panel',
                'required' => true,
                'encrypted' => true,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            // We can just hit a generic ping or user endpoint to verify credentials if we want,
            // or hit an invalid user sync just to see if 401/403 triggers.
            // Using a fake sync to see if it responds with validation errors instead of auth errors.
            $response = $this->request('/api/admin/external/users/sync', 'POST', []);
        } catch (Exception $e) {
            // If it's a 422 validation error, it means we bypassed the 401/403 meaning credentials are correct.
            if (str_contains($e->getMessage(), 'The email field is required')) {
                return true;
            }
            return $e->getMessage();
        }

        return true;
    }

    private function request($endpoint, $method = 'POST', $data = [])
    {
        $url = rtrim($this->config('host'), '/') . $endpoint;
        
        logger()->debug('[GateProxy] Executing API Call', [
            'url' => $url,
            'method' => $method,
            'data' => $data,
        ]);

        $response = Http::withoutVerifying()->withHeaders([
            'Authorization' => 'Bearer ' . $this->config('api_key'),
            'Accept' => 'application/json',
        ])->$method($url, $data);

        if (!$response->successful()) {
            $body = $response->json() ?? [];
            logger()->error('[GateProxy] API Call Failed', [
                'status' => $response->status(),
                'response' => $body
            ]);

            $errorMsg = is_array($body) ? ($body['message'] ?? 'API Error') : 'API Error';
            
            // Format laravel validation errors if present
            if (is_array($body) && isset($body['errors'])) {
                $errorMsg = collect($body['errors'])->flatten()->first() ?? $errorMsg;
            }

            throw new DisplayException($errorMsg);
        }

        return $response->json();
    }

    public function getProductConfig($values = []): array
    {
        return [
            [
                'name' => 'plan_name',
                'label' => 'Plan Name',
                'type' => 'text',
                'description' => 'The exact string name of the Plan in Gate Proxy (e.g. "Basic Plan")',
                'required' => true,
            ],
            [
                'name' => 'max_server',
                'label' => 'Max Proxies / Servers',
                'type' => 'number',
                'description' => 'Number of proxies the user is allowed to create',
                'required' => true,
                'min_value' => 1,
            ],
        ];
    }

    private function getPayload(Service $service, array $settings, array $properties): array
    {
        $user = $service->user;
        $settings = array_merge($settings, $properties);

        return [
            'external_id' => (string) $service->id,
            'email' => $user->email,
            'name' => $user->first_name . ' ' . $user->last_name,
            'plan_name' => $settings['plan_name'] ?? 'Custom',
            'max_server' => (int) ($settings['max_server'] ?? 1),
            // Pass next due date if available, otherwise null. 
            // In Paymenter, we might have $service->order->invoices->last()->due_date, etc, 
            // but Paymenter doesn't expose a clean property on Service always.
            // Wait, Paymenter doesn't directly map expiration date easily in the core Service model without fetching invoice.
            // We will just leave it null to let proxy panel default to indefinite or pending billing cycles.
            'expired_at' => null, 
        ];
    }

    public function createServer(Service $service, $settings, $properties)
    {
        logger()->debug('[GateProxy] Creating Server / Subscription', ['service_id' => $service->id]);
        
        $payload = $this->getPayload($service, $settings, $properties);
        
        $this->request("/api/admin/external/subscriptions/{$service->id}/activate", 'POST', $payload);

        return [
            'server' => 'Active',
            'link' => rtrim($this->config('host'), '/'),
        ];
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        logger()->debug('[GateProxy] Suspending Server / Subscription', ['service_id' => $service->id]);

        $this->request("/api/admin/external/subscriptions/{$service->id}/suspend", 'POST');

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        logger()->debug('[GateProxy] Unsuspending Server / Subscription', ['service_id' => $service->id]);

        $payload = $this->getPayload($service, $settings, $properties);
        $this->request("/api/admin/external/subscriptions/{$service->id}/activate", 'POST', $payload);

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        logger()->debug('[GateProxy] Terminating Server / Subscription', ['service_id' => $service->id]);

        $this->request("/api/admin/external/subscriptions/{$service->id}/terminate", 'POST');

        return true;
    }

    public function getActions(Service $service): array
    {
        return [
            [
                'type' => 'button',
                'label' => 'Go to Control Panel',
                'function' => 'loginLink',
                'disabled' => false,
            ],
        ];
    }

    public function loginLink(Service $service)
    {
        return rtrim($this->config('host'), '/');
    }
}
