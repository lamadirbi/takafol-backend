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

    public function getCamp(): ?Camp
    {
        return $this->currentCamp;
    }

    public function getCampId(): ?int
    {
        return $this->currentCamp?->id;
    }
}
