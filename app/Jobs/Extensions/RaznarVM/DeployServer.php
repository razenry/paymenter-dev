<?php

namespace App\Jobs\Extensions\RaznarVM;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Servers\RaznarVM\RaznarVM;

class DeployServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Service $service,
        public array $deploymentData,
        public string $host,
        public string $apiKey
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        logger()->debug('[raznarvm] background deployment job started', ['service_id' => $this->service->id]);

        $url = rtrim($this->host, '/') . '/api/admin/servers/deploy';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ])->post($url, $this->deploymentData);

            if (!$response->successful()) {
                throw new Exception('RaznarVM API Error: ' . ($response->json()['message'] ?? 'Unknown error'));
            }

            logger()->debug('[raznarvm] deployment request accepted, waiting 5 seconds for initialization...');
            sleep(5);

            $server = $response->json();
            $serverId = $server['data']['id'] ?? $server['id'] ?? null;

            if (!$serverId) {
                // Fallback: check by external ID if missing in direct response
                $serverDetails = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ])->get(rtrim($this->host, '/') . '/api/admin/servers/external/' . $this->service->id);

                if ($serverDetails->successful()) {
                    $details = $serverDetails->json();
                    $serverId = $details['data']['id'] ?? $details['id'] ?? null;
                }
            }

            if ($serverId) {
                // Update service properties
                $this->service->properties()->updateOrCreate(['key' => 'server'], ['value' => $serverId]);
                
                logger()->debug('[raznarvm] background deployment successful', [
                    'service_id' => $this->service->id,
                    'server_id' => $serverId
                ]);
            } else {
                throw new Exception('Failed to obtain server ID after successful deployment');
            }

        } catch (Exception $e) {
            logger()->error('[raznarvm] background deployment failed', [
                'service_id' => $this->service->id,
                'error' => $e->getMessage()
            ]);
            
            // Optionally set status back to pending or log failure
            $this->service->update(['status' => 'pending']);
        }
    }
}
