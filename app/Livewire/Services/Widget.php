<?php

namespace App\Livewire\Services;

use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Widget extends Component
{
    use WithPagination;

    public $status = null;

    public function render()
    {
        $services = Auth::user()->services();

        if ($this->status) {
            $services->where('status', $this->status);
        } else {
            $services->where('status', '!=', 'cancelled');
        }

        $services = $services->paginate(config('settings.pagination'));

        $services->getCollection()->transform(function ($service) {
            $service->external_id = ExtensionHelper::getServerId($service);
            return $service;
        });

        return view('services.widget', [
            'services' => $services,
        ])->layoutData([
                    'title' => 'Services',
                ]);
    }

}
