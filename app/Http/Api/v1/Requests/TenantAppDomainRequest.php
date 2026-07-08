<?php

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class TenantAppDomainRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return [
                'platform' => [
                    'required_without:app_domain',
                    'string',
                    'in:android,ios',
                ],
                'app_domain' => [
                    'sometimes',
                    'string',
                    'max:' . InputConstraints::NAME_MAX,
                ],
            ];
        }

        return [
            'platform' => [
                'required_without:app_domain',
                'string',
                'in:android,ios',
            ],
            'identifier' => [
                'required_without:app_domain',
                'string',
                'max:' . InputConstraints::NAME_MAX,
            ],
            'app_domain' => [
                'sometimes',
                'string',
                'max:' . InputConstraints::NAME_MAX,
            ],
        ];
    }

    public function platform(): string
    {
        $validated = $this->validated();
        $platform = $validated['platform'] ?? null;

        if (is_string($platform) && trim($platform) !== '') {
            return strtolower(trim($platform));
        }

        return 'android';
    }

    public function identifier(): string
    {
        $validated = $this->validated();

        $identifier = $validated['identifier'] ?? $validated['app_domain'] ?? '';

        return is_string($identifier) ? strtolower(trim($identifier)) : '';
    }
}
