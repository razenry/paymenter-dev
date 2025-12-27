<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;

class CancelUpgrade extends Component
{
    public Service $service;

    public $pendingUpgrade;

    public function mount()
    {
        $this->authorize('view', $this->service);

        // Get the pending upgrade if it exists
        $this->pendingUpgrade = $this->service->upgrade()->where('status', 'pending')->first();

        if (!$this->pendingUpgrade) {
            $this->notify('No pending upgrade to cancel.', 'error');

            return $this->redirect(route('services.show', $this->service));
        }
    }

    public function cancelUpgrade()
    {
        if (!$this->pendingUpgrade) {
            $this->notify('No pending upgrade found.', 'error');

            return;
        }

        // Delete the pending upgrade
        $this->pendingUpgrade->delete();

        $this->notify('The pending upgrade has been deleted.', 'success');

        $this->redirect(route('services.show', $this->service), true);
    }

    public function render()
    {
        return view('services.cancel-upgrade')->layoutData([
            'title' => 'Cancel Upgrade',
            'sidebar' => false,
        ]);
    }
}
