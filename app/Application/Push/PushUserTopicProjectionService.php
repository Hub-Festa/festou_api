<?php

declare(strict_types=1);

namespace App\Application\Push;

class PushUserTopicProjectionService
{
    public function __construct(
        private readonly PushChannelNamingService $naming,
    ) {}

    /**
     * @return array<int, string>
     */
    public function topicsForUserId(string $userId): array
    {
        return array_values(array_unique(array_filter(array_merge(
            $this->allUsersTopics(),
            $this->favoriteProfileTopicsForUserId($userId),
            $this->confirmedEventTopicsForUserId($userId),
        ), static fn (string $topic): bool => trim($topic) !== '')));
    }

    /**
     * @return array<int, string>
     */
    public function allUsersTopics(): array
    {
        $topic = $this->naming->allUsersTopic();

        return $topic === '' ? [] : [$topic];
    }

    /**
     * @return array<int, string>
     */
    public function favoriteProfileTopicsForUserId(string $userId): array
    {
        return [];
    }

    public function userHasFavoriteAccountProfile(string $userId, string $accountProfileId): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function confirmedEventTopicsForUserId(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }

        return [];
    }

    public function userHasConfirmedEvent(string $userId, string $eventId): bool
    {
        return false;
    }
}
