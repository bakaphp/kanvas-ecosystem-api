<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;
use Kanvas\Connectors\DriveCentric\Services\UserService;

class PullUserAction
{
    protected UserService $userService;

    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
    ) {
        $this->userService = new UserService($app, $company);
    }

    public function execute(UserInterface $user): array
    {
        // First try to find by email
        $driveCentricUser = null;

        if ($user->email) {
            $driveCentricUser = $this->userService->getUserByEmail($user->email);
        }

        $phoneNumber = $user->phone_number ?? $user->cell_phone_number ?? null;
        // If not found by email, try by phone
        if (! $driveCentricUser && ! empty($phoneNumber)) {
            $driveCentricUser = $this->userService->getUserByPhone($phoneNumber);
        }

        if (! $driveCentricUser) {
            throw new DriveCentricException(
                "User not found in DriveCentric: {$user->email} / {$phoneNumber}"
            );
        }

        // Extract CRM ID from identifiers
        $crmId = $this->extractCrmId($driveCentricUser);

        if (! $crmId) {
            throw new DriveCentricException('DriveCentric user found but has no CrmId identifier');
        }

        // Save CRM ID to Kanvas user
        $user->set(
            ConfigurationEnum::getUserKey($this->company),
            $crmId
        );

        return $driveCentricUser;
    }

    /**
     * Execute pull user by DriveCentric CRM ID.
     */
    public function executeById(UserInterface $user, string $crmId): array
    {
        $users = $this->userService->searchUsers(['crmId' => $crmId]);

        if (empty($users)) {
            throw new DriveCentricException("User not found in DriveCentric with CrmId: {$crmId}");
        }

        $driveCentricUser = $users[0];

        // Save CRM ID to Kanvas user
        $user->set(
            CustomFieldEnums::DRIVE_CENTRIC_USER_ID->value,
            $crmId
        );

        return $driveCentricUser;
    }

    /**
     * Extract CRM ID from user identifiers array.
     */
    protected function extractCrmId(array $userData): ?string
    {
        $identifiers = $userData['identifiers'] ?? [];

        foreach ($identifiers as $identifier) {
            // Check for crmId type (case-insensitive)
            $type = strtolower($identifier['type'] ?? '');
            if ($type === 'crmid') {
                return $identifier['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * Check if user is already synced with DriveCentric.
     */
    public function isSynced(UserInterface $user): bool
    {
        return ! empty($user->get(ConfigurationEnum::getUserKey($this->company)));
    }

    /**
     * Get DriveCentric user ID from Kanvas user.
     */
    public function getDriveCentricUserId(UserInterface $user): ?string
    {
        return $user->get(ConfigurationEnum::getUserKey($this->company));
    }
}
