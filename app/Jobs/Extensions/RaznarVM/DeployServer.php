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
                $errorBody = $response->json();
                $errorMsg = is_array($errorBody) ? ($errorBody['message'] ?? 'Unknown error') : 'API Error';
                throw new Exception('RaznarVM API Error: ' . $errorMsg);
            }

            $postData = $response->json();
            $serverId = $postData['data']['id'] ?? $postData['id'] ?? null;

            if (!$serverId) {
                logger()->debug('[raznarvm] server ID not in deployment response, performing external lookup...');
                for ($i = 0; $i < 5; $i++) {
                    sleep(3 + ($i * 2)); // 3, 5, 7, 9, 11 seconds
                    $serverDetails = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json',
                    ])->get(rtrim($this->host, '/') . '/api/admin/servers/external/' . $this->service->id);

                    if ($serverDetails->successful()) {
                        $detailsData = $serverDetails->json();
                        $serverId = $detailsData['data']['id'] ?? $detailsData['id'] ?? null;
                        if ($serverId) {
                            logger()->debug('[raznarvm] server ID obtained via external lookup', ['server_id' => $serverId, 'attempt' => $i + 1]);
                            break;
                        }
                    }
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
                throw new Exception('Failed to obtain server ID via initial response or external lookup after deployment.');
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
