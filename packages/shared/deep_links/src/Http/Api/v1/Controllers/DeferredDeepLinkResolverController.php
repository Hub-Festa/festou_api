<?php

declare(strict_types=1);

namespace Shared\DeepLinks\Http\Api\v1\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shared\DeepLinks\Application\DeferredDeepLinkResolverService;

class DeferredDeepLinkResolverController extends Controller
{
    public function __construct(
        private readonly DeferredDeepLinkResolverService $resolver,
    ) {}

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'in:android,ios'],
            'install_referrer' => ['nullable', 'string'],
            'deferred_payload' => ['nullable', 'string'],
            'store_channel' => ['nullable', 'string'],
        ]);

        $result = $this->resolver->resolve(
            deferredPayload: isset($validated['deferred_payload']) ? (string) $validated['deferred_payload'] : null,
            installReferrer: isset($validated['install_referrer']) ? (string) $validated['install_referrer'] : null,
            fallbackStoreChannel: isset($validated['store_channel']) ? (string) $validated['store_channel'] : null,
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}
