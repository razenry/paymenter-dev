<?php

namespace App\Jobs\Invoice;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class SendInvoiceReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    public function __construct(public Invoice $invoice) {}

    /**
     * Apply rate limiting to avoid spamming mail server.
     */
    public function middleware(): array
    {
        return [new RateLimited('invoice-reminders')];
    }

    public function handle(): void
    {
        logger()->debug('SendInvoiceReminderJob handle() started', ['invoice_id' => $this->invoice->id]);
        $observer = new \App\Observers\InvoiceObserver;
        $observer->remind($this->invoice);
        
    }
}
