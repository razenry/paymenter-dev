<?php

namespace App\Console\Commands;

use App\Models\Service;
use Illuminate\Console\Command;
use Log;
use Throwable;

class SyncServices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-services';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync active services in batches with progress logging';

    /**
     * @var int
     */
    protected $batchSize = 25;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        ini_set('memory_limit', '1024M');

        $total = Service::where('status', Service::STATUS_ACTIVE)->count();
        if ($total === 0) {
            $this->info('No active services found.');

            return;
        }

        $bar = $this->output->createProgressBar(ceil($total / $this->batchSize));
        $bar->setFormat('Batch %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();

        Service::where('status', Service::STATUS_ACTIVE)
            ->chunk($this->batchSize, function ($services) use ($bar) {
                $observer = new \App\Observers\ServiceObserver;

                try {
                    foreach ($services as $service) {
                        $observer->updated($service);
                    }
                } catch (Throwable $th) {
                    Log::error($th);
                    $this->error('Error processing batch: ' . $th->getMessage());
                }

                $bar->advance();
            });

        $bar->finish();
        $this->info("\nAll active services synced successfully.");
    }
}
