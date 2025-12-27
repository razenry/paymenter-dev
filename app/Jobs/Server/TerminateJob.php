<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TerminateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->service->disable_termination) {
            return;
        }

        $data = []; // ensure it's always an array

        try {
            $result = ExtensionHelper::terminateServer($this->service);
            if (is_array($result)) {
                $data = $result;
            }
        } catch (Exception $e) {
            if ($e->getMessage() !== 'No server assigned to this product') {
                throw $e;
            }
            // else leave $data as empty array
        }

        if ($this->sendNotification) {
            NotificationHelper::serverTerminatedNotification($this->service->user, $this->service, $data);
        }
    }
}
