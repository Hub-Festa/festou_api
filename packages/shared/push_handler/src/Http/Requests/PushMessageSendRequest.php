<?php

declare(strict_types=1);

namespace Shared\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PushMessageSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required_without:email', 'string'],
            'email' => ['required_without:user_id', 'email'],
            'device_id' => ['nullable', 'string'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }
}
