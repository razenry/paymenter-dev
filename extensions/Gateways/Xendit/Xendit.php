<?php

namespace Paymenter\Extensions\Gateways\Xendit;

use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;

class Xendit extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes/web.php';
        View::addNamespace('gateways.xendit', __DIR__ . '/resources/views');
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
                'name' => 'server_key',
                'label' => 'Private API Key',
                'type' => 'text',
                'description' => 'Find your server key in Xendit dashboard.',
                'required' => true,
            ],
            [
                'name' => 'token_key',
                'label' => 'Token Webhook Key',
                'type' => 'text',
                'description' => 'Your Webhook Token key.',
                'required' => true,
            ],
        ];
    }

    public function pay(Invoice $invoice, $total)
    {
        $orderId = 'PAYMENTER-' . $invoice->id;
        $serverKey = $this->config('server_key');

        $url = 'https://api.xendit.co/v2';

        $user = $invoice->user;
        $customerDetails = [
            'given_names' => $user->first_name,
            'surname' => $user->last_name,
            'email' => $user->email,
        ];

        $items = collect($invoice->items)->map(function ($item) {
            return [
                'id' => $item->id ?? uniqid(),
                'price' => round($item->price, 2),
                'quantity' => $item->quantity ?? 1,
                'name' => $item->description ?? 'Item',
            ];
        })->toArray();

        $payload = [
            'external_id' => $orderId,
            'amount' => round($total, 2),
            'items' => $items,
            'customer' => $customerDetails,
            'payment_methods' => ['QRIS'],
        ];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($serverKey . ':'),
        ];

        $response = Http::withHeaders($headers)->post(sprintf('%s/invoices', $url), $payload);
        $json = $response->json();

        if ($response->failed() || !isset($json['invoice_url'])) {
            logger()->error('failed to init: ' . json_encode($json));
            logger()->error('code: ' . (string) $response->getStatusCode());

            return redirect()->back()->with('error', 'Failed to initiate Xendit payment.');
        }

        return redirect($json['invoice_url']);

    }

    public function webhook(Request $request)
    {
        $tokenKey = $this->config('token_key');

        $headerToken = htmlspecialchars($_SERVER['HTTP_X_CALLBACK_TOKEN']);
        if ($headerToken != $tokenKey) {
            \Log::warning("Invalid token: $headerToken");
            return response('Forbiddein', 403);
        }

        $data = $request->json()->all();
        \Log::debug('Xendit webhook payload received:', $data);

        if (
            isset($data['status_code'], $data['transaction_status']) &&
            $data['status_code'] === '200' &&
            in_array($data['transaction_status'], ['capture', 'settlement'])
        ) {
            try {
                $orderId = $data['order_id'] ?? null;
                \Log::debug("Parsed order_id: $orderId");

                $invoiceId = explode('-', $orderId)[1] ?? null;
                \Log::debug("Parsed invoice ID: $invoiceId");

                if (!$invoiceId) {
                    \Log::warning("Xendit webhook received invalid order_id format: $orderId");

                    return response('Invalid order id format', 400);
                }

                $amount = isset($data['gross_amount']) ? floatval($data['gross_amount']) : null;
                $transactionId = $data['transaction_id'] ?? null;

                if (!$amount || !$transactionId) {
                    \Log::warning('Xendit webhook missing amount or transaction_id.');

                    return response('Invalid payload', 400);
                }

                \Log::debug("Creating payment: invoice_id=$invoiceId, amount=$amount, transaction_id=$transactionId");

                ExtensionHelper::addPayment($invoiceId, 'Xendit', $amount, null, $transactionId);
                \Log::info("Xendit payment successfully recorded for invoice ID: $invoiceId");

            } catch (\Throwable $e) {
                \Log::error("Xendit webhook error: {$e->getMessage()}");

                return response('Error', 500);
            }
        } else {
            \Log::debug('Xendit webhook ignored due to invalid status or transaction_status.', [
                'status_code' => $data['status_code'] ?? null,
                'transaction_status' => $data['transaction_status'] ?? null,
            ]);
        }

        return response('OK', 200);
    }

    public function canUseGateway($total, $currency, $type, $items = [])
    {
        if ($currency != 'IDR') {
            return false;
        }
        if ($total < 5000) {
            return false;
        }

        return true;
    }
}
