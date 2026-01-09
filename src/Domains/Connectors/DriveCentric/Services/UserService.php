<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Client;

class UserService
{
    public Client $client;

    public function __construct(
        protected Apps $app,
        protected Companies $company
    ) {
        $this->client = new Client($this->app, $this->company);
    }

    /**
     * List all users for the store.
     * GET /api/stores/{storeId}/user/list
     */
    public function listUsers(string $start, int $offset = 0, int $limit = 100): array
    {
        $response = $this->client->get('/api/stores/{+storeId}/user/list', [
            'offset' => $offset,
            'limit' => $limit,
            'start' => $start,
        ]);

        return $response->json('data') ?? [];
    }

    /**
     * Search users by various criteria.
     * GET /api/stores/{storeId}/user/search
     *
     * Supported filters: dmsId, crmId, phone, email, offset, showInactive
     */
    public function searchUsers(array $filters = []): array
    {
        // Filter out null/empty values to avoid sending empty params
        $params = array_filter($filters, fn ($value) => $value !== null && $value !== '');

        $response = $this->client->get('/api/stores/{+storeId}/user/search', $params);

        return $response->json('data') ?? [];
    }

    /**
     * Search user by email.
     */
    public function getUserByEmail(string $email): ?array
    {
        $users = $this->searchUsers(['email' => $email]);

        return $users[0] ?? null;
    }

    /**
     * Search user by name.
     */
    public function getUserByName(string $firstName, ?string $lastName = null): array
    {
        $filters = ['firstName' => $firstName];

        if ($lastName) {
            $filters['lastName'] = $lastName;
        }

        return $this->searchUsers($filters);
    }

    /**
     * Get user by DriveCentric user ID.
     */
    public function getUserById(string $userId): ?array
    {
        $users = $this->searchUsers(['userId' => $userId]);

        return $users[0] ?? null;
    }

    /**
     * Get all active users.
     */
    public function getActiveUsers(int $offset = 0): array
    {
        return $this->searchUsers([
            'showInactive' => 'false',
            'offset' => $offset,
        ]);
    }
}
