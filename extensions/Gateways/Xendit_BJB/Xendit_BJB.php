<?php

namespace Paymenter\Extensions\Gateways\Xendit_BJB;

use App\Classes\Extension\Gateway;
use App\Models\Invoice;
use Paymenter\Extensions\Gateways\XenditLib\XenditLib;

require_once realpath(__DIR__ . '/../libs/XenditLib.php');

class Xendit_BJB extends Gateway
{
    public function boot(): void
    {
        // Boot logic if needed
    }

    public function getMetadata(): array
    {
        return [
            'display_name' => 'Xendit BJB',
            'version' => '1.0.0',
            'author' => 'Raznar',
            'website' => 'https://raznar.id',
        ];
    }

    public function getConfig($values = []): array
    {
        return XenditLib::getConfig();
    }

    public function pay(Invoice $invoice, $total)
    {
        $fixedFee = (float) ($this->config('fixed_fee') ?? 0);
        $percentageFee = (float) ($this->config('percentage_fee') ?? 0);
        $invoiceDuration = (int) ($this->config('invoice_duration') ?? 86400);

        logger()->debug("fees: fixed - $fixedFee, percentage - $percentageFee");

        $lib = new XenditLib($this->config('api_key'));

        return $lib->pay(['BJB'], $fixedFee, $percentageFee, $invoiceDuration, $invoice, $total);
    }

    public function canUseGateway($total, $currency, $type, $items = [])
    {
        if ($currency != 'IDR') {
            return false;
        } if ($total < 5000) {
            return false;
        }

        return true;
    }
}
