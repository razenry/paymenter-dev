<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProvisionPendingServices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'services:provision-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision services that are pending and have no paid invoices';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = 0;

        Service::where('status', Service::STATUS_PENDING)

            // Must have at least one paid invoice
            ->whereHas('invoices', function ($query) {
                $query->where('status', 'paid');
            })

            // Must NOT have any pending invoices
            ->whereDoesntHave('invoices', function ($query) {
                $query->where('status', 'pending');
            })
            ->where('disable_auto_provision', false)

            ->get()
            ->each(function (Service $service) use (&$count) {
                if (!isset($service->product->server)) {
                    return;
                }


                try {
                    ExtensionHelper::createServer($service);
                    Cache::forget('provision_retries_' . $service->id);
                    $count++;
                } catch (\Throwable $e) {
                    $msg = strtolower($e->getMessage());
                    if (str_contains($msg, 'already provisioned') || str_contains($msg, 'already exists')) {
                        // Skip logging for already provisioned services
                        $count++;

                        Log::warning('Service already provisioned, skipping.', [
                            'service_id' => $service->id,
                        ]);

                        $service->status = Service::STATUS_ACTIVE;
                        $service->save();
                        return;
                    }

                    Log::error('Service provisioning failed', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);

                    $retries = Cache::get('provision_retries_' . $service->id, 0) + 1;
                    if ($retries >= 3) {
                        $service->disable_auto_provision = true;
                        $service->save();
                        Cache::forget('provision_retries_' . $service->id);

                        Log::error('Service provisioning reached max retries (3), disabling auto provision', [
                            'service_id' => $service->id,
                        ]);
                    } else {
                        Cache::put('provision_retries_' . $service->id, $retries, now()->addDay());
                    }
                }
            });

        $this->info("Provisioned {$count} services.");

    }
}
