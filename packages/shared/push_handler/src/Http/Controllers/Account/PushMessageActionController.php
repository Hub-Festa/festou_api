<?php

declare(strict_types=1);

namespace Shared\PushHandler\Http\Controllers\Account;

use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Shared\PushHandler\Http\Requests\PushMessageActionRequest;
use Shared\PushHandler\Models\Tenants\PushMessage;
use Shared\PushHandler\Services\PushMessageAudienceService;
use Shared\PushHandler\Services\PushMetricsService;
use Illuminate\Http\JsonResponse;

class PushMessageActionController
{
    public function __construct(
        private readonly PushMetricsService $metricsService,
        private readonly PushMessageAudienceService $audienceService
    ) {
    }

    public function store(PushMessageActionRequest $request): JsonResponse
    {
        $account = Account::current();
        if (! $account) {
            abort(422, 'Account context not available.');
        }

        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'account')
            ->where('_id', $pushMessageId)
            ->where('account_id', (string) $account->_id)
            ->firstOrFail();

        $user = $request->user();
        if (! $user instanceof AccountUser) {
            return response()->json(['ok' => false], 401);
        }

        if (! $this->audienceService->isEligible($user, $message, [
            'scope' => 'account',
            'account_id' => (string) $account->_id,
        ])) {
            return response()->json(['ok' => false, 'reason' => 'forbidden'], 403);
        }

        $payload = $request->validated();

        $action = $this->metricsService->recordAction($message, $payload, (string) $user->_id);

        return response()->json([
            'ok' => true,
            'data' => $action,
        ]);
    }
}
