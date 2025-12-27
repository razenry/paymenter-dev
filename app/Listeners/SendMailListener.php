<?php

namespace App\Listeners;

use App\Enums\InvoiceTransactionStatus;
use App\Events\Auth\Login;
use App\Events\Invoice\Finalized as InvoiceFinalized;
use App\Events\Invoice\Paid as InvoicePaid;
use App\Events\Invoice\Remind as InvoiceRemind;
use App\Events\InvoiceTransaction\Created as InvoiceTransactionCreated;
use App\Events\InvoiceTransaction\Updated as InvoiceTransactionUpdated;
use App\Events\Order\Finalized as OrderFinalized;
use App\Events\ServiceCancellation\Created as CancellationCreated;
use App\Events\User\Created as UserCreated;
use App\Helpers\NotificationHelper;
use App\Models\Session;
use App\Models\UserAuthenticationLog;

class SendMailListener
{
    /**
     * Handle the event.
     */
    public function handle(
        InvoiceFinalized|OrderFinalized|UserCreated|Login|CancellationCreated|InvoicePaid|InvoiceTransactionCreated|InvoiceTransactionUpdated|InvoiceRemind $event
    ): void {
        logger()->debug('SendMailListener triggered', ['event' => get_class($event)]);

        if ($event instanceof InvoiceFinalized) {
            logger()->debug('Handling InvoiceFinalized event', ['sendEmail' => $event->sendEmail]);
            if ($event->sendEmail === false) {
                logger()->debug('Skipping email because sendEmail is false');

                return;
            }

            $invoice = $event->invoice;
            logger()->debug('Sending invoice created notification', ['invoice_id' => $invoice->id]);
            NotificationHelper::invoiceCreatedNotification($invoice->user, $invoice);

        } elseif ($event instanceof InvoiceRemind) {
            $invoice = $event->invoice;
            logger()->debug('Sending invoice reminder', ['invoice_id' => $invoice->id]);
            NotificationHelper::invoiceRemindNotification($invoice->user, $invoice);

        } elseif ($event instanceof InvoicePaid) {
            logger()->debug('Sending invoice paid notification', ['invoice_id' => $event->invoice->id]);
            NotificationHelper::invoicePaidNotification($event->invoice->user, $event->invoice);

        } elseif ($event instanceof InvoiceTransactionCreated || $event instanceof InvoiceTransactionUpdated) {
            $transaction = $event->invoiceTransaction;
            logger()->debug('Handling invoice transaction event', ['transaction_id' => $transaction->id, 'status' => $transaction->status]);
            if ($transaction->status === InvoiceTransactionStatus::Failed) {
                logger()->debug('Transaction failed – sending payment failed notification', ['invoice_id' => $transaction->invoice->id]);
                NotificationHelper::invoicePaymentFailedNotification($transaction->invoice->user, $transaction->invoice);
            }

        } elseif ($event instanceof UserCreated) {
            $user = $event->user;
            logger()->debug('User created – sending email verification', ['user_id' => $user->id]);
            NotificationHelper::emailVerificationNotification($user);

        } elseif ($event instanceof OrderFinalized) {
            logger()->debug('Handling OrderFinalized event', ['sendEmail' => $event->sendEmail]);
            if ($event->sendEmail === false) {
                logger()->debug('Skipping order email because sendEmail is false');

                return;
            }
            $order = $event->order;
            logger()->debug('Sending order created notification', ['order_id' => $order->id]);
            NotificationHelper::orderCreatedNotification($order->user, $order);

        } elseif ($event instanceof Login) {
            logger()->debug('Handling Login event', ['user_id' => $event->user->id]);
            $this->login($event);

        } elseif ($event instanceof CancellationCreated) {
            $cancellation = $event->cancellation;
            logger()->debug('Service cancellation received', ['service_id' => $cancellation->service->id]);
            NotificationHelper::serviceCancellationReceivedNotification($cancellation->service->user, $cancellation);
        }
    }

    private function login(Login $event): void
    {
        $user = $event->user;
        $ip = request()->ip();
        if (!$user->authenticationLogs()->where('ip_address', $ip)->exists()) {
            // If the log for this IP does not exist, create a new log
            $log = new UserAuthenticationLog;
            $log->user_id = $user->id;
            $log->ip_address = $ip;
            $log->save();

            $data = [
                'ip' => $ip,
                'device' => (new Session(['user_agent' => request()->userAgent()]))->getFormattedDeviceAttribute(),
                'time' => now()->format('Y-m-d H:i:s'),
            ];
            NotificationHelper::loginDetectedNotification($user, $data);
        } else {
            // If it exists, update the last used timestamp
            $log = $user->authenticationLogs()->where('ip_address', $ip)->first();
            $log->last_used_at = now();
            $log->save();
        }
    }
}
