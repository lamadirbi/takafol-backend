<?php

namespace App\Traits;

use App\Models\Camp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant()
    {
        // Add Global Scope to filter by camp_id
        static::addGlobalScope('camp', function (Builder $builder) {
            if (App::has('current_camp_id')) {
                $builder->where('camp_id', App::get('current_camp_id'));
            }
        });

        // Automatically set camp_id when creating a new record
        static::creating(function (Model $model) {
            if (empty($model->camp_id) && App::has('current_camp_id')) {
                $model->camp_id = App::get('current_camp_id');
            }
        });
    }

    public function camp()
    {
        return $this->belongsTo(Camp::class);
    }
}
