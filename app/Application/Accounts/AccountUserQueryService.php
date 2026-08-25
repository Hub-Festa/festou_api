<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\Shared\Query\AbstractQueryService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Pagination\LengthAwarePaginator;

class AccountUserQueryService extends AbstractQueryService
{
    public function paginate(
        Account $account,
        array $queryParams,
        bool $includeArchived,
        int $perPage = 15
    ): LengthAwarePaginator {
        return $this->buildPaginator(
            AccountUser::query()->where('account_roles.account_id', $account->id),
            $queryParams,
            $includeArchived,
            $perPage
        );
    }

    protected function baseSearchableFields(): array
    {
        return array_diff(
            (new AccountUser())->getFillable(),
            [
                'password',
                'credentials',
                'consents',
                'promotion_audit',
                'merged_source_ids',
                'fingerprints',
            ]
        );
    }

    protected function stringFields(): array
    {
        return ['name', 'identity_state'];
    }

    protected function arrayFields(): array
    {
        return ['emails', 'phones'];
    }

    protected function dateFields(): array
    {
        return ['first_seen_at', 'registered_at', 'created_at', 'updated_at', 'deleted_at'];
    }
}
