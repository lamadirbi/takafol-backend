<?php

namespace App\Services;

use App\Models\Camp;
use Illuminate\Support\Facades\App;

class TenantManager
{
    protected ?Camp $currentCamp = null;

    public function setCurrentCamp(Camp $camp): void
    {
        $this->currentCamp = $camp;
        App::instance('current_camp_id', $camp->id);
        App::instance('current_camp', $camp);
    }

    public function clear(): void
    {
        $this->currentCamp = null;
        App::forgetInstance('current_camp_id');
        App::forgetInstance('current_camp');
    }

    public function getCamp(): ?Camp
    {
        return $this->currentCamp;
    }

    public function getCampId(): ?int
    {
        return $this->currentCamp?->id;
    }

    public function campForId(int $campId): ?Camp
    {
        if ($this->currentCamp && (int) $this->currentCamp->id === $campId) {
            return $this->currentCamp;
        }

        return Camp::query()->find($campId);
    }
}
