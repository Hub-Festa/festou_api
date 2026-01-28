<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaticAssetUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'description' => 'sometimes|string|max:' . InputConstraints::DESCRIPTION_MAX,
            'category' => [
                'sometimes',
                'string',
                Rule::in(['culture', 'restaurant', 'beach', 'nature', 'historic']),
            ],
            'tags' => 'sometimes|array|max:10',
            'tags.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'taxonomy_terms' => 'sometimes|array',
            'taxonomy_terms.*.type' => 'required_with:taxonomy_terms|string|max:' . InputConstraints::NAME_MAX,
            'taxonomy_terms.*.value' => 'required_with:taxonomy_terms|string|max:' . InputConstraints::NAME_MAX,
            'location' => 'sometimes|array',
            'location.lat' => 'required_with:location|numeric',
            'location.lng' => 'required_with:location|numeric',
            'priority' => 'sometimes|integer|min:0|max:100',
            'is_active' => 'sometimes|boolean',
            'media' => 'sometimes|array',
            'badge' => 'sometimes|array',
        ];
    }
}
