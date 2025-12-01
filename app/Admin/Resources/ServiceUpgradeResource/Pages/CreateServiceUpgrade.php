<?php

namespace App\Admin\Resources\ServiceUpgradeResource\Pages;

use App\Admin\Resources\ServiceUpgradeResource;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Filament\Resources\Pages\CreateRecord;
use App\Models\ServiceUpgrade;
use App\Models\Invoice;
use Carbon\Carbon;

class CreateServiceUpgrade extends CreateRecord
{
    protected static string $resource = ServiceUpgradeResource::class;

    protected function handleRecordCreation(array $data): ServiceUpgrade
    {
        // Create upgrade record
        $upgrade = ServiceUpgrade::create([
            'service_id' => $data['service_id'],
            'product_id' => $data['product_id'],
            'plan_id'    => $data['plan_id'],
        ]);

        // Save upgrade configs
        if (!empty($data['configs'])) {
            foreach ($data['configs'] as $optionId => $value) {
                $upgrade->configs()->create([
                    'config_option_id' => $optionId,
                    'config_value_id'  => $value,
                ]);
            }
        }

        // Calculate price
        $price = $upgrade->calculatePrice();

        /*
        |--------------------------------------------------------------------------
        | Zero or negative cost → apply upgrade directly
        |--------------------------------------------------------------------------
        */

        if ($price->price <= 0) {
            (new ServiceUpgradeService)->handle($upgrade);

            // Downgrade credits?
            if (config('settings.credits_on_downgrade', true)) {
                $user = $upgrade->service->user;
                $credit = $user->credits()->where('currency_code', $price->currency->code)->first();

                if ($credit) {
                    $credit->increment('amount', abs($price->price));
                } else {
                    $user->credits()->create([
                        'currency_code' => $price->currency->code,
                        'amount'        => abs($price->price),
                    ]);
                }
            }

            return $upgrade; // No invoice needed
        }

        /*
        |--------------------------------------------------------------------------
        | Positive price → create invoice
        |--------------------------------------------------------------------------
        */

        $invoice = Invoice::create([
            'currency_code' => $upgrade->service->currency_code,
            'status'        => Invoice::STATUS_PENDING,
            'due_at'        => Carbon::now()->addDays(7),
            'user_id'       => $upgrade->service->user_id,
        ]);

        $upgrade->update([
            'invoice_id' => $invoice->id,
        ]);

        $invoice->items()->create([
            'description'    => 'Upgrade ' . $upgrade->service->product->name . ' to ' . $upgrade->product->name,
            'price'          => $price->price,
            'quantity'       => 1,
            'reference_id'   => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);

        return $upgrade;
    }
}
