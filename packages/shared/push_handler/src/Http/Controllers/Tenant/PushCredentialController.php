<?php

declare(strict_types=1);

namespace Shared\PushHandler\Http\Controllers\Tenant;

use Shared\PushHandler\Http\Requests\PushCredentialRequest;
use Shared\PushHandler\Models\Tenants\PushCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushCredentialController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PushCredential::query()->get(),
        ]);
    }

    public function store(PushCredentialRequest $request): JsonResponse
    {
        $credential = PushCredential::create($request->validated());

        return response()->json(['data' => $credential], 201);
    }

    public function update(PushCredentialRequest $request): JsonResponse
    {
        $credential = PushCredential::query()->findOrFail((string) $request->route('credential_id'));
        $credential->fill($request->validated());
        $credential->save();

        return response()->json(['data' => $credential]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $credential = PushCredential::query()->findOrFail((string) $request->route('credential_id'));
        $credential->delete();

        return response()->json(['ok' => true]);
    }
}
