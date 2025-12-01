<?php

namespace App\Console\Commands;

use App\Jobs\Server\TerminateJob;
use App\Models\Service;
use Illuminate\Console\Command;

class TerminateServices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:terminate-services';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Terminate suspended services that exceeded their overdue termination days.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $terminateDays = (int) config('settings.cronjob_order_terminate', -1);

        if ($terminateDays <= 0) {
            $this->info("Termination disabled. cronjob_order_terminate is set to {$terminateDays}");

            return;
        }

        $this->info("Checking for services to terminate (Overdue: {$terminateDays} days)...");

        $count = 0;

        Service::where('status', 'suspended')
            ->where('expires_at', '<', now()->subDays($terminateDays))
            ->each(function ($service) use (&$count) {
                TerminateJob::dispatch($service);
                $count++;

                $this->info(
                    "Queued termination | Service: {$service->id} | User: {$service->user->email} | Expired: {$service->expires_at}"
                );
            });

        if ($count === 0) {
            $this->info('No services eligible for termination.');
        } else {
            $this->info("Termination queued for {$count} service(s).");
        }
    }
}
