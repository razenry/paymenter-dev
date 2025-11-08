<?php

namespace Paymenter\Extensions\Gateways\XenditLib;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;

class XenditLib
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public static function getConfig($values = []): array
    {
        return [
            [
                'name' => 'api_key',
                'label' => 'Private API Key',
                'type' => 'text',
                'description' => 'Find your server key in the Xendit dashboard.',
                'required' => true,
            ],
            [
                'name' => 'fixed_fee',
                'label' => 'Payment Fixed Fee',
                'type' => 'text',
                'placeholder' => '0.0',
                'description' => 'Fixed fee applied per payment.',
                'required' => true,
            ],
            [
                'name' => 'percentage_fee',
                'label' => 'Payment Percentage Fee',
                'type' => 'text',
                'placeholder' => '0.0',
                'description' => 'Percentage fee applied per payment.',
                'required' => true,
            ],
            [
                'name' => 'invoice_duration',
                'label' => 'Invoice Duration',
                'type' => 'select', // <-- changed
                'description' => 'Select the duration before the invoice expires.',
                'options' => [
                    ['label' => '5 Minutes',  'value' => '300'],
                    ['label' => '15 Minutes', 'value' => '900'],
                    ['label' => '30 Minutes', 'value' => '1800'],
                    ['label' => '1 Hour',     'value' => '3600'],
                    ['label' => '3 Hours',    'value' => '10800'],
                    ['label' => '6 Hours',    'value' => '21600'],
                    ['label' => '12 Hours',   'value' => '43200'],
                    ['label' => '1 Day',      'value' => '86400'],
                    ['label' => '3 Days',     'value' => '259200'],
                ],
                'required' => true,
            ],
        ];
    }

    public function pay(array $paymentMethod, float $fixedFee, float $percentageFee, int $invoiceDuration, Invoice $invoice, $total)
    {

        $paymentFeeInPercentage = $total * ($percentageFee / 100);
        // Xendit Params
        $totalFee = (int) floor($fixedFee + $paymentFeeInPercentage);

        $total = (int) floor($total + $totalFee);
        $orderId = 'PAYMENTER-' . $invoice->id;
        $serverKey = $this->apiKey;

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
            'payment_methods' => $paymentMethod,
            'invoice_duration' => $invoiceDuration,
            'success_redirect_url' => route('invoices.show', $invoice) . '?checkPayment=true',
        ];

        if ($totalFee > 0) {
            $payload['fees'] = [['type' => 'Payment Fee', 'value' => (float) $totalFee]];
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($serverKey . ':'),
        ];

        $response = Http::withHeaders($headers)->post(sprintf('%s/invoices', $url), $payload);
        $json = $response->json();

        if ($response->failed() || !isset($json['invoice_url'])) {
            logger()->debug('test: ' . json_encode($paymentMethod));
            logger()->debug('payload: ' . json_encode($payload));
            logger()->error('failed to init: ' . json_encode($json));
            logger()->error('code: ' . (string) $response->getStatusCode());

            return redirect()->route('account.payment-methods')->with('notification', [
                'type' => 'danger',
                'message' => 'Failed to initiate Xendit payment.',
            ]);
        }

        return redirect($json['invoice_url']);
    }
}
