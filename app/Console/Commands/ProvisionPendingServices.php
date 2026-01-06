<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Console\Command;
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

            ->get()
            ->each(function (Service $service) use (&$count) {
                try {
                    ExtensionHelper::createServer($service);
                    $count++;
                } catch (\Throwable $e) {
                    Log::error('Service provisioning failed', [
                        'service_id' => $service->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        $this->info("Provisioned {$count} services.");

    }
}
