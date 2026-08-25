<?php

declare(strict_types=1);

namespace Shared\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PushCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'string'],
            'client_email' => ['required', 'string', 'email'],
            'private_key' => ['required', 'string'],
        ];
    }
}
