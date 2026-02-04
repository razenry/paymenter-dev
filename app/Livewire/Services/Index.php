<?php

namespace App\Livewire\Services;

use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public $status = null;

    public function render()
    {
        $services = Auth::user()->services()->orderByRaw("CASE WHEN status = 'active' THEN 1 ELSE 2 END")->orderBy('created_at', 'desc');

        if ($this->status) {
            $services->where('status', $this->status);
        }

        $services = $services->paginate(config('settings.pagination'));

        $services->getCollection()->transform(function ($service) {
            $service->external_id = ExtensionHelper::getServerId($service);
            return $service;
        });
        
        return view('services.index', [
            'services' => $services->paginate(config('settings.pagination')),
        ])->layoutData([
                    'title' => 'Services',
                    'sidebar' => true,
                ]);
    }
}
