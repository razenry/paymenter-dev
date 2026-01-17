<?php

namespace App\Livewire\Services;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class Show extends Component
{
    public Service $service;

    #[Locked]
    public $buttons = [];

    #[Locked]
    public $views = [];

    #[Locked]
    public $fields = [];

    #[Url('tab', except: false), Locked]
    public $currentView;

    #[Url('cancel', except: false)]
    public bool $showCancel = false;
    public bool $showCancelUpgrade = false;

    public bool $showBillingAgreement = false;

    public bool $showGenerateInvoice = false;

    public bool $showAutoCancelModal = false;

    public $pendingInvoiceId = null;

    public $selectedMonths;

    public $selectedMethod;

    public function mount()
    {
        // Only fetch the actions if the service is active
        if ($this->service->status == Service::STATUS_ACTIVE) {
            $actions = [];
            try {
                $actions = ExtensionHelper::getActions($this->service);
            } catch (Exception $e) {
            }
            // separate the actions into buttons and views
            foreach ($actions as $action) {
                if ($action['type'] == 'button') {
                    $this->buttons[] = $action;
                } elseif ($action['type'] == 'view') {
                    $this->views[] = $action;
                } elseif ($action['type'] == 'text') {
                    $this->fields[] = $action;
                }
            }
            $this->currentView = $this->currentView ?? ($this->views[0]['name'] ?? null);
        }
    }

    public function updatedShowBillingAgreement()
    {
        $this->selectedMethod = Auth::user()->billingAgreements()->where('id', $this->service->billing_agreement_id)?->first()?->ulid;
    }

    public function updateBillingAgreement()
    {
        $agreement = Auth::user()->billingAgreements()->where('ulid', $this->selectedMethod)->first();
        $this->service->billing_agreement_id = $agreement->id;
        $this->service->save();

        $this->showBillingAgreement = false;
    }

    public function clearBillingAgreement()
    {
        $this->service->billing_agreement_id = null;
        $this->service->save();
        $this->selectedMethod = null;
    }

    public function changeView($view)
    {
        if (!$view) {
            return;
        }
        if ($this->currentView === $view || !in_array($view, array_column($this->views, 'name'))) {
            return $this->skipRender();
        }
        $this->currentView = $view;
    }

    public function updatedShowCancel($value)
    {
        if (!$this->service->cancellable) {
            $this->notify('This service cannot be cancelled', 'error');
            $this->showCancel = false;

            return;
        }
    }

    public function goto($function)
    {
        // Check if function is allowed
        if (!in_array($function, array_column($this->buttons, 'function'))) {
            $this->notify('This action is not allowed', 'error');

            return;
        }

        try {
            $result = ExtensionHelper::callService($this->service, $function);

            // If it returns a URL, redirect
            if (is_string($result)) {
                $this->redirect($result);
            }

            // Otherwise, return the result (could be JSON or array)
            return $result;

        } catch (DisplayException $e) {
            // Show the error message to the user
            $this->notify($e->getMessage(), 'error');
        } catch (Exception $e) {
            // Fallback for unexpected errors
            $this->notify('Something went wrong. Please try again.', 'error');
        }
    }

    public function generateInvoice()
    {
        // Validate that months are selected
        if (empty($this->selectedMonths)) {
            $this->notify('Please select a duration', 'error');
            return;
        }

        $months = (int) $this->selectedMonths;

        // Validate that the service is active and not cancelled
        if ($this->service->status !== Service::STATUS_ACTIVE) {
            $this->notify('Cannot generate invoice for inactive service', 'error');
            $this->showGenerateInvoice = false;
            $this->selectedMonths = null;
            return;
        }

        // Check if there are any pending service extension invoices for this service
        $pendingInvoice = Invoice::where('user_id', $this->service->user_id)
            ->where('status', 'pending')
            ->whereHas('items', function ($query) {
                $query->where('reference_type', Service::class)
                    ->where('reference_id', $this->service->id)
                    ->whereRaw("description LIKE '%- Extension%'");
            })
            ->first();

        if ($pendingInvoice) {
            $this->pendingInvoiceId = $pendingInvoice->id;
            $this->showAutoCancelModal = true;
            return;
        }

        // Proceed with invoice generation
        $this->createNewInvoice();
    }

    public function confirmGenerateInvoice()
    {
        // Cancel the existing pending invoice
        if ($this->pendingInvoiceId) {
            $invoice = Invoice::find($this->pendingInvoiceId);
            if ($invoice && $invoice->status === 'pending') {
                $invoice->status = Invoice::STATUS_CANCELLED;
                $invoice->save();
            }
        }

        // Close the modal
        $this->showAutoCancelModal = false;
        $this->pendingInvoiceId = null;

        // Create new invoice
        $this->createNewInvoice();
    }

    public function proceedWithoutCancel()
    {
        $this->showAutoCancelModal = false;
        $this->pendingInvoiceId = null;
        $this->showGenerateInvoice = false;
        $this->selectedMonths = null;
    }

    private function createNewInvoice()
    {
        try {
            $months = (int) $this->selectedMonths;
            
            // Calculate the price for the specified number of months
            $monthlyPrice = $this->service->calculatePrice();
            $totalPrice = (float) $monthlyPrice * $months;

            // Create the invoice
            $invoice = Invoice::create([
                'user_id' => $this->service->user_id,
                'currency_code' => $this->service->currency_code,
                'status' => 'pending',
                'due_at' => now()->addDays(7), // Due in 7 days
            ]);

            // Create the invoice item
            $startDate = $this->service->expires_at ?? now();
            $endDate = $startDate->copy()->addMonths($months);

            $invoice->items()->create([
                'description' => $this->service->product->name . ' - Extension (' . $startDate->format('M d, Y') . ' - ' . $endDate->format('M d, Y') . ')',
                'price' => $totalPrice,
                'quantity' => 1,
                'reference_type' => Service::class,
                'reference_id' => $this->service->id,
            ]);

            $this->notify('Invoice generated successfully', 'success');
            $this->selectedMonths = null;
            $this->showGenerateInvoice = false;

            // Redirect to the invoice view
            return $this->redirect(route('invoices.show', $invoice->id));

        } catch (Exception $e) {
            $this->notify('Failed to generate invoice: ' . $e->getMessage(), 'error');
            $this->showGenerateInvoice = false;
            $this->selectedMonths = null;
        }
    }

    public function render()
    {
        $view = null;
        $previousView = $this->currentView;

        if ($this->currentView) {
            try {
                // Search array for the current view
                $currentViewObj = $this->views[array_search($this->currentView, array_column($this->views, 'name'))] ?? null;
                if (!$currentViewObj) {
                    throw new Exception('View not found');
                }
                $view = ExtensionHelper::getView($this->service, $currentViewObj);
            } catch (Exception $e) {
                if ($previousView !== $this->views[0]['name'] ?? null) {
                    $this->notify('Got an error while trying to load the view', 'error');
                }
                $this->currentView = $this->views[0]['name'] ?? null;
            }
        }

        return view('services.show', ['extensionView' => $view])->layoutData([
            'title' => 'Services',
            'sidebar' => true,
        ]);
    }
}
