<?php

namespace App\Http\Requests;

use App\Models\Distribution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDistributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                Distribution::STATUS_PENDING,
                Distribution::STATUS_RECEIVED,
                Distribution::STATUS_NOT_ELIGIBLE,
            ])],
            'delivered_at' => ['nullable', 'date'],
        ];
    }
}
