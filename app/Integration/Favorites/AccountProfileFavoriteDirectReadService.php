<?php

declare(strict_types=1);

namespace App\Integration\Favorites;

use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Shared\Favorites\Contracts\AccountProfileFavoriteDirectReadContract;
use Shared\Favorites\Models\Tenants\FavoriteEdge;

class AccountProfileFavoriteDirectReadService implements AccountProfileFavoriteDirectReadContract
{
    private const int DEFAULT_PAGE_SIZE = 10;

    public function __construct(
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
    ) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function listForOwner(
        string $ownerUserId,
        int $page,
        int $pageSize,
    ): array {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = $pageSize > 0
            ? min($pageSize, self::DEFAULT_PAGE_SIZE)
            : self::DEFAULT_PAGE_SIZE;
        $skip = ($resolvedPage - 1) * $resolvedPageSize;

        $rawRows = $this->loadOrderPreservingFavoriteRows(
            ownerUserId: $ownerUserId,
            skip: $skip,
            limit: $resolvedPageSize + 1,
        );

        if ($rawRows === []) {
            return [
                'items' => [],
                'has_more' => false,
            ];
        }

        $hasMore = count($rawRows) > $resolvedPageSize;
        $pageRows = array_slice($rawRows, 0, $resolvedPageSize);
        $rows = [];

        foreach ($pageRows as $rawRow) {
            $edge = (new FavoriteEdge)->newFromBuilder($rawRow);
            $profile = $this->hydrateProfile($rawRow['profile'] ?? null);
            if (! $profile instanceof AccountProfile) {
                continue;
            }

            $rows[] = $this->buildRow(
                edge: $edge,
                profile: $profile,
                liveNowOccurrence: $this->hydrateOccurrence($rawRow['live_now_occurrence'] ?? null),
                nextOccurrence: $this->hydrateOccurrence($rawRow['next_occurrence'] ?? null),
                lastOccurrence: null,
            );
        }

        $lastOccurrenceStates = $this->loadLastOccurrenceStates(array_values(array_unique(array_map(
            static fn (array $row): string => (string) ($row['profile_id'] ?? ''),
            $rows,
        ))));

        return [
            'items' => array_map(
                fn (array $row): array => $this->applyLastOccurrenceState(
                    $row['payload'],
                    $lastOccurrenceStates[(string) ($row['profile_id'] ?? '')] ?? null,
                ),
                $rows,
            ),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadOrderPreservingFavoriteRows(
        string $ownerUserId,
        int $skip,
        int $limit,
    ): array {
        $now = Carbon::now();
        $pipeline = $this->buildFavoriteOrderingPipeline(
            ownerUserId: $ownerUserId,
            now: new UTCDateTime($now->toDateTimeImmutable()),
            skip: $skip,
            limit: $limit,
        );

        $rows = FavoriteEdge::raw(
            static fn ($collection) => $collection->aggregate($pipeline)
        );

        $normalizedRows = [];
        foreach ($rows as $row) {
            $normalizedRows[] = $row instanceof FavoriteEdge
                ? $row->getAttributes()
                : $this->normalizeArray($row);
        }

        return $normalizedRows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFavoriteOrderingPipeline(
        string $ownerUserId,
        UTCDateTime $now,
        int $skip,
        int $limit,
    ): array {
        return [
            [
                '$match' => [
                    'owner_user_id' => $ownerUserId,
                    'registry_key' => 'account_profile',
                    'target_type' => 'account_profile',
                ],
            ],
            [
                '$lookup' => [
                    'from' => 'account_profiles',
                    'let' => ['profileId' => '$target_id'],
                    'pipeline' => [
                        [
                            '$match' => [
                                '$or' => [
                                    ['is_active' => true],
                                    ['is_active' => null],
                                    ['is_active' => ['$exists' => false]],
                                ],
                                'deleted_at' => null,
                                '$expr' => [
                                    '$eq' => [
                                        $this->asStringExpression('$_id'),
                                        $this->asStringExpression('$$profileId'),
                                    ],
                                ],
                            ],
                        ],
                        [
                            '$project' => [
                                '_id' => 1,
                                'slug' => 1,
                                'display_name' => 1,
                                'avatar_url' => 1,
                                'cover_url' => 1,
                                'profile_type' => 1,
                                'is_active' => 1,
                                'deleted_at' => 1,
                            ],
                        ],
                    ],
                    'as' => 'profile',
                ],
            ],
            ['$unwind' => '$profile'],
            [
                '$lookup' => [
                    'from' => 'event_occurrences',
                    'localField' => 'target_id',
                    'foreignField' => 'place_ref._id',
                    'pipeline' => $this->buildOccurrenceStatePipeline($now, true),
                    'as' => 'direct_occurrence_state',
                ],
            ],
            [
                '$lookup' => [
                    'from' => 'event_occurrences',
                    'localField' => 'target_id',
                    'foreignField' => 'event_parties.party_ref_id',
                    'pipeline' => $this->buildOccurrenceStatePipeline($now),
                    'as' => 'party_occurrence_state',
                ],
            ],
            [
                '$set' => [
                    '__direct_occurrence_state' => [
                        '$arrayElemAt' => ['$direct_occurrence_state', 0],
                    ],
                    '__party_occurrence_state' => [
                        '$arrayElemAt' => ['$party_occurrence_state', 0],
                    ],
                ],
            ],
            [
                '$set' => [
                    '__live_now_candidates' => [
                        '$concatArrays' => [
                            ['$ifNull' => ['$__direct_occurrence_state.live_now', []]],
                            ['$ifNull' => ['$__party_occurrence_state.live_now', []]],
                        ],
                    ],
                    '__next_candidates' => [
                        '$concatArrays' => [
                            ['$ifNull' => ['$__direct_occurrence_state.next', []]],
                            ['$ifNull' => ['$__party_occurrence_state.next', []]],
                        ],
                    ],
                ],
            ],
            [
                '$set' => [
                    '__live_now' => [
                        '$arrayElemAt' => [
                            [
                                '$sortArray' => [
                                    'input' => '$__live_now_candidates',
                                    'sortBy' => ['starts_at' => 1, '__occurrence_id_sort' => 1],
                                ],
                            ],
                            0,
                        ],
                    ],
                    '__next' => [
                        '$arrayElemAt' => [
                            [
                                '$sortArray' => [
                                    'input' => '$__next_candidates',
                                    'sortBy' => ['starts_at' => 1, '__occurrence_id_sort' => 1],
                                ],
                            ],
                            0,
                        ],
                    ],
                ],
            ],
            [
                '$set' => [
                    '__sort_block' => [
                        '$cond' => [
                            ['$gt' => [['$size' => '$__live_now_candidates'], 0]],
                            0,
                            [
                                '$cond' => [
                                    ['$gt' => [['$size' => '$__next_candidates'], 0]],
                                    1,
                                    2,
                                ],
                            ],
                        ],
                    ],
                    '__favorite_id_sort' => $this->asStringExpression('$_id'),
                ],
            ],
            [
                '$set' => [
                    '__sort_upcoming_at' => [
                        '$cond' => [
                            ['$eq' => ['$__sort_block', 1]],
                            '$__next.starts_at',
                            null,
                        ],
                    ],
                ],
            ],
            [
                '$sort' => [
                    '__sort_block' => 1,
                    '__sort_upcoming_at' => 1,
                    'favorited_at' => -1,
                    '__favorite_id_sort' => 1,
                ],
            ],
            ['$skip' => $skip],
            ['$limit' => $limit],
            [
                '$project' => [
                    '_id' => 1,
                    'target_id' => 1,
                    'favorited_at' => 1,
                    'profile' => 1,
                    'live_now_occurrence' => '$__live_now',
                    'next_occurrence' => '$__next',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildOccurrenceStatePipeline(UTCDateTime $now, bool $requireAccountProfilePlace = false): array
    {
        $match = [
            'deleted_at' => null,
            'is_event_published' => true,
            '$expr' => [
                '$or' => [
                    ['$gt' => ['$__effective_end_at', $now]],
                    ['$gte' => ['$starts_at', $now]],
                ],
            ],
        ];
        if ($requireAccountProfilePlace) {
            $match['place_ref.type'] = 'account_profile';
        }

        return [
            [
                '$set' => [
                    '__effective_end_at' => [
                        '$ifNull' => ['$effective_ends_at', '$ends_at'],
                    ],
                    '__occurrence_id_sort' => $this->asStringExpression('$_id'),
                ],
            ],
            ['$match' => $match],
            [
                '$project' => [
                    '_id' => 1,
                    'slug' => 1,
                    'starts_at' => 1,
                    'effective_ends_at' => 1,
                    'ends_at' => 1,
                    'place_ref' => 1,
                    'event_parties' => 1,
                    '__effective_end_at' => 1,
                    '__occurrence_id_sort' => 1,
                ],
            ],
            [
                '$facet' => [
                    'live_now' => [
                        [
                            '$match' => [
                                '$expr' => [
                                    '$and' => [
                                        ['$lte' => ['$starts_at', $now]],
                                        ['$gt' => ['$__effective_end_at', $now]],
                                    ],
                                ],
                            ],
                        ],
                        ['$sort' => ['starts_at' => 1, '__occurrence_id_sort' => 1]],
                        ['$limit' => 1],
                    ],
                    'next' => [
                        [
                            '$match' => [
                                '$expr' => ['$gte' => ['$starts_at', $now]],
                            ],
                        ],
                        ['$sort' => ['starts_at' => 1, '__occurrence_id_sort' => 1]],
                        ['$limit' => 1],
                    ],
                ],
            ],
        ];
    }

    private function asStringExpression(string $field): array
    {
        return [
            '$convert' => [
                'input' => $field,
                'to' => 'string',
                'onError' => '',
                'onNull' => '',
            ],
        ];
    }

    private function hydrateProfile(mixed $rawProfile): ?AccountProfile
    {
        $profile = $this->normalizeArray($rawProfile);
        if ($profile === []) {
            return null;
        }

        return (new AccountProfile)->newFromBuilder($profile);
    }

    private function hydrateOccurrence(mixed $rawOccurrence): ?EventOccurrence
    {
        $occurrence = $this->normalizeArray($rawOccurrence);
        if ($occurrence === []) {
            return null;
        }

        return (new EventOccurrence)->newFromBuilder($occurrence);
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, EventOccurrence>
     */
    private function loadLastOccurrenceStates(array $profileIds): array
    {
        $normalizedProfileIds = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $profileIds,
        ), static fn (string $value): bool => $value !== '')));

        if ($normalizedProfileIds === []) {
            return [];
        }

        $profileIdCandidates = [];
        foreach ($normalizedProfileIds as $profileId) {
            foreach ($this->buildProfileIdCandidates($profileId) as $candidate) {
                $profileIdCandidates[] = $candidate;
            }
        }

        $now = Carbon::now();
        $occurrences = EventOccurrence::query()
            ->where('deleted_at', null)
            ->where('is_event_published', true)
            ->where(static function ($query) use ($now): void {
                $query->where('effective_ends_at', '<=', $now)
                    ->orWhere(static function ($query) use ($now): void {
                        $query->whereNull('effective_ends_at')
                            ->where('ends_at', '<=', $now);
                    })
                    ->orWhere(static function ($query) use ($now): void {
                        $query->whereNull('effective_ends_at')
                            ->whereNull('ends_at')
                            ->where('starts_at', '<', $now);
                    });
            })
            ->where(static function ($query) use ($profileIdCandidates): void {
                $query->where(static function ($query) use ($profileIdCandidates): void {
                    $query->where('place_ref.type', 'account_profile')
                        ->where(static function ($query) use ($profileIdCandidates): void {
                            $query->whereIn('place_ref.id', $profileIdCandidates)
                                ->orWhereIn('place_ref._id', $profileIdCandidates);
                        });
                })->orWhereRaw([
                    'event_parties' => [
                        '$elemMatch' => [
                            'party_ref_id' => ['$in' => $profileIdCandidates],
                        ],
                    ],
                ]);
            })
            ->orderBy('starts_at', 'desc')
            ->orderBy('_id', 'desc')
            ->get([
                '_id',
                'starts_at',
                'place_ref',
                'event_parties',
            ]);

        $states = [];
        $favoriteProfileIdSet = array_fill_keys($normalizedProfileIds, true);

        foreach ($occurrences as $occurrence) {
            $associatedProfileIds = $this->extractAssociatedProfileIds(
                $occurrence,
                $favoriteProfileIdSet,
            );
            if ($associatedProfileIds === []) {
                continue;
            }

            foreach ($associatedProfileIds as $profileId) {
                $currentLast = $states[$profileId] ?? null;
                if (! $currentLast instanceof EventOccurrence || $this->startsAfter($occurrence, $currentLast)) {
                    $states[$profileId] = $occurrence;
                }
            }
        }

        return $states;
    }

    /**
     * @param  array<string, bool>  $favoriteProfileIdSet
     * @return array<int, string>
     */
    private function extractAssociatedProfileIds(
        EventOccurrence $occurrence,
        array $favoriteProfileIdSet,
    ): array {
        $profileIds = [];

        $placeRef = $this->normalizeArray($occurrence->getAttribute('place_ref'));
        if (($placeRef['type'] ?? null) === 'account_profile') {
            $placeRefId = $this->extractEmbeddedId($placeRef);
            if ($placeRefId !== '' && isset($favoriteProfileIdSet[$placeRefId])) {
                $profileIds[$placeRefId] = $placeRefId;
            }
        }

        foreach ($this->normalizeList($occurrence->getAttribute('event_parties')) as $eventParty) {
            $partyRefId = trim((string) ($eventParty['party_ref_id'] ?? ''));
            if ($partyRefId !== '' && isset($favoriteProfileIdSet[$partyRefId])) {
                $profileIds[$partyRefId] = $partyRefId;
            }
        }

        return array_values($profileIds);
    }

    /**
     * @return array{profile_id:string,favorite_id:string,favorited_at:\DateTimeInterface|null,sort_block:int,sort_upcoming_occurrence_at:\DateTimeInterface|null,payload:array<string,mixed>}
     */
    private function buildRow(
        FavoriteEdge $edge,
        AccountProfile $profile,
        ?EventOccurrence $liveNowOccurrence,
        ?EventOccurrence $nextOccurrence,
        ?EventOccurrence $lastOccurrence,
    ): array {
        $profileId = (string) $profile->getAttribute('_id');
        $profileSlug = trim((string) ($profile->slug ?? ''));
        $canOpenPublicDetail = $profileSlug !== ''
            && $this->typeSetProvider->isPubliclyNavigable((string) ($profile->profile_type ?? ''));
        $publicDetailPath = $canOpenPublicDetail ? '/parceiro/'.$profileSlug : null;

        $liveNowOccurrenceId = $liveNowOccurrence ? (string) $liveNowOccurrence->getAttribute('_id') : null;
        $liveNowOccurrenceAt = $liveNowOccurrence?->starts_at;
        $nextOccurrenceId = $nextOccurrence ? (string) $nextOccurrence->getAttribute('_id') : null;
        $nextOccurrenceAt = $nextOccurrence?->starts_at;
        $lastOccurrenceAt = $lastOccurrence?->starts_at;
        $eventNavigationOccurrence = $liveNowOccurrence ?? $nextOccurrence;
        $eventTargetOccurrenceId = $eventNavigationOccurrence
            ? (string) $eventNavigationOccurrence->getAttribute('_id')
            : null;
        $eventTargetSlug = $eventNavigationOccurrence?->slug
            ? trim((string) $eventNavigationOccurrence->slug)
            : null;
        $eventTargetPath = $this->buildEventTargetPath(
            $eventTargetSlug,
            $eventTargetOccurrenceId,
        );

        $sortBlock = $liveNowOccurrenceAt instanceof \DateTimeInterface
            ? 0
            : ($nextOccurrenceAt instanceof \DateTimeInterface ? 1 : 2);

        return [
            'profile_id' => $profileId,
            'favorite_id' => (string) $edge->getAttribute('_id'),
            'favorited_at' => $edge->favorited_at,
            'sort_block' => $sortBlock,
            'sort_upcoming_occurrence_at' => $sortBlock === 1 ? $nextOccurrenceAt : null,
            'payload' => [
                'favorite_id' => (string) $edge->getAttribute('_id'),
                'registry_key' => 'account_profile',
                'target_type' => 'account_profile',
                'target_id' => $profileId,
                'favorited_at' => $this->formatDate($edge->favorited_at),
                'target' => [
                    'id' => $profileId,
                    'slug' => $profileSlug,
                    'display_name' => (string) ($profile->display_name ?? ''),
                    'avatar_url' => $profile->avatar_url ?? null,
                    'cover_url' => $profile->cover_url ?? null,
                    'profile_type' => $profile->profile_type ? (string) $profile->profile_type : null,
                    'can_open_public_detail' => $canOpenPublicDetail,
                    'public_detail_path' => $publicDetailPath,
                ],
                'occurrence_state' => [
                    'live_now_event_occurrence_id' => $liveNowOccurrenceId,
                    'live_now_event_occurrence_at' => $this->formatDate($liveNowOccurrenceAt),
                    'next_event_occurrence_id' => $nextOccurrenceId,
                    'next_event_occurrence_at' => $this->formatDate($nextOccurrenceAt),
                    'last_event_occurrence_at' => $this->formatDate($lastOccurrenceAt),
                ],
                'navigation' => [
                    'kind' => $eventTargetPath !== null ? 'event' : 'account_profile',
                    'target_slug' => $eventTargetPath !== null ? $eventTargetSlug : $profileSlug,
                    'target_path' => $eventTargetPath ?? $publicDetailPath,
                    'profile_target_path' => $publicDetailPath,
                    'event_target_path' => $eventTargetPath,
                    'event_target_slug' => $eventTargetSlug,
                    'event_occurrence_id' => $eventTargetOccurrenceId,
                    'can_open_public_detail' => $canOpenPublicDetail,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyLastOccurrenceState(
        array $payload,
        ?EventOccurrence $lastOccurrence,
    ): array {
        $occurrenceState = is_array($payload['occurrence_state'] ?? null)
            ? $payload['occurrence_state']
            : [];
        $occurrenceState['last_event_occurrence_at'] = $this->formatDate(
            $lastOccurrence?->starts_at,
        );
        $payload['occurrence_state'] = $occurrenceState;

        return $payload;
    }

    private function startsAfter(EventOccurrence $left, EventOccurrence $right): bool
    {
        $leftStartsAt = $left->starts_at;
        $rightStartsAt = $right->starts_at;

        if (! $leftStartsAt instanceof \DateTimeInterface || ! $rightStartsAt instanceof \DateTimeInterface) {
            return false;
        }

        if ($leftStartsAt->getTimestamp() === $rightStartsAt->getTimestamp()) {
            return strcmp((string) $left->getAttribute('_id'), (string) $right->getAttribute('_id')) > 0;
        }

        return $leftStartsAt->getTimestamp() > $rightStartsAt->getTimestamp();
    }

    private function buildEventTargetPath(?string $eventSlug, ?string $occurrenceId): ?string
    {
        $normalizedSlug = trim((string) $eventSlug);
        $normalizedOccurrenceId = trim((string) $occurrenceId);

        if ($normalizedSlug === '' || $normalizedOccurrenceId === '') {
            return null;
        }

        return '/agenda/evento/'.$normalizedSlug.'?occurrence='.rawurlencode($normalizedOccurrenceId);
    }

    /**
     * @return array<int, string|ObjectId>
     */
    private function buildProfileIdCandidates(string $profileId): array
    {
        $candidates = [$profileId];

        if ($this->looksLikeObjectId($profileId)) {
            $candidates[] = new ObjectId($profileId);
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeList(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $normalized[] = $this->normalizeArray($item);
        }

        return $normalized;
    }

    private function extractEmbeddedId(array $payload): string
    {
        foreach (['id', '_id'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->format(DATE_ATOM);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
