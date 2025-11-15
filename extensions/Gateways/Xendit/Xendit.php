<?php

namespace Paymenter\Extensions\Gateways\Xendit;

use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Exception;
use Illuminate\Http\Request;

class Xendit extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
    }

    public function getMetadata(): array
    {
        return [
            'display_name' => 'Xendit',
            'version' => '1.0.0',
            'author' => 'Raznar',
            'website' => 'https://raznar.id',
        ];
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'token_key',
                'label' => 'Token Webhook Key',
                'type' => 'text',
                'description' => 'Your Webhook Token key.',
                'required' => true,
                'encrypted' => true,
            ],
        ];
    }

    public function pay(Invoice $invoice, $total) {
        throw new Exception("this function is not implemented");
    }

    
    public function webhook(Request $request)
    {
        $tokenKey = $this->config('token_key');

        $headerToken = htmlspecialchars(isset($_SERVER['HTTP_X_CALLBACK_TOKEN']) ? $_SERVER['HTTP_X_CALLBACK_TOKEN'] : '');
        if ($headerToken != $tokenKey) {
            \Log::warning("Invalid token: $headerToken");

            return response('Forbidden', 403);
        }

        $data = $request->json()->all();
        \Log::debug('Xendit webhook payload received:', $data);

        if (
            isset($data['status']) && in_array($data['status'], ['PAID'])
        ) {
            try {
                $externalId = $data['external_id'] ?? null;
                \Log::debug("Parsed external_id: $externalId");

                $invoiceId = explode('-', $externalId)[1] ?? null;
                \Log::debug("Parsed invoice ID: $invoiceId");

                if (!$invoiceId) {
                    \Log::warning("Xendit webhook received invalid external_id format: $externalId");

                    return response('Invalid external id format', 400);
                }


                $transactionId = $data['id'] ?? null;

                if (!$transactionId) {
                    \Log::warning('Xendit webhook missing amount or id.');

                    return response('Invalid payload', 400);
                }


                /** @var Invoice $invoice */
                $invoice = Invoice::find($invoiceId);
                if(!isset($invoice)) {
                    \Log::warning('Invoice not found');

                    return response('Invoice not found', 404);
                }

                \Log::debug("Creating payment: invoice_id=$invoiceId, amount=$invoice->total, transaction_id=$transactionId");
                ExtensionHelper::addPayment($invoiceId, 'Xendit', $invoice->total, null, $transactionId);
                \Log::info("Xendit payment successfully recorded for invoice ID: $invoiceId");

            } catch (\Throwable $e) {
                \Log::error("Xendit webhook error: {$e->getMessage()}");

                return response('Error', 500);
            }
        } else {
            \Log::debug('Xendit webhook ignored due to invalid status.', []);

            return response('Bad Request', 400);
        }

        return response('OK', 200);
    }

    public function canUseGateway($total, $currency, $type, $items = [])
    {
        return false;
    }
}
