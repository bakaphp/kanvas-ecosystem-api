<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\DealerSocket\ActivityClient;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Regions\Models\Regions;
use Throwable;

class DealerSocketActivityService
{
    public ActivityClient $activityClient;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
    ) {
        $this->activityClient = new ActivityClient(app: $app, company: $company, region: $region);
    }

    /**
     * Save an activity to DealerSocket
     * Can be used for any type of activity, not just lead attempts
     *
     * @param array $activityData Activity data array
     * @return array Response from DealerSocket
     */
    public function saveActivity(array $activityData): array
    {
        // Validate required fields
        $this->validateActivityData($activityData);

        $response = $this->activityClient->saveActivity($activityData);

        if (! $response['success']) {
            throw new Exception(
                $response['errorMessage'] ?? $response['error'] ?? 'Failed to save activity'
            );
        }

        return $response;
    }

    /**
     * Create activity from a Lead
     * Generic method that can handle any activity type
     *
     * @param Lead $lead The lead
     * @param string $activityTaskType Type of activity (e.g., "Appointment", "Outbound Call")
     * @param array $additionalData Additional activity data
     * @return array Response from DealerSocket
     */
    public function createActivityFromLead(
        Lead $lead,
        string $activityTaskType,
        array $additionalData = []
    ): array {
        $activityData = $this->buildActivityDataFromLead($lead, $activityTaskType, $additionalData);

        return $this->saveActivity($activityData);
    }

    /**
     * Update an existing activity
     *
     * @param int $activityId DealerSocket Activity ID
     * @param Lead $lead The lead
     * @param array $updateData Data to update
     * @return array Response from DealerSocket
     */
    public function updateActivity(int $activityId, Lead $lead, array $updateData = []): array
    {
        // Get required IDs
        $entityId = $lead->people->get(
            DealerSocketConfigurationService::getCustomerIdKey($lead->people, $this->region)
        );
        $eventId = $lead->get(
            DealerSocketConfigurationService::getLeadIdKey($lead, $this->region)
        );

        if (! $entityId || ! $eventId) {
            throw new Exception('Lead is missing DealerSocket IDs (EntityId or EventId)');
        }

        $activityData = array_merge([
            'activityId' => $activityId,
            'entityId' => (int) $entityId,
            'eventId' => (int) $eventId,
        ], $updateData);

        return $this->saveActivity($activityData);
    }

    /**
     * Create an appointment activity
     */
    public function createAppointment(
        Lead $lead,
        string $appointmentDateTime,
        ?string $assignedToUser = null,
        ?string $note = null
    ): array {
        return $this->createActivityFromLead($lead, 'Appointment', [
            'dueDateTime' => $appointmentDateTime,
            'assignedToUser' => $assignedToUser,
            'note' => $note,
        ]);
    }

    /**
     * Create a phone call activity
     */
    public function createPhoneCall(
        Lead $lead,
        string $callType = 'Outbound Call',
        ?string $note = null,
        ?string $status = null
    ): array {
        return $this->createActivityFromLead($lead, $callType, [
            'note' => $note,
            'status' => $status,
        ]);
    }

    /**
     * Create an email activity
     */
    public function createEmailActivity(
        Lead $lead,
        ?string $note = null
    ): array {
        return $this->createActivityFromLead($lead, 'Email', [
            'note' => $note,
            'status' => 'Completed',
        ]);
    }

    /**
     * Create a test drive activity
     */
    public function createTestDrive(
        Lead $lead,
        string $scheduledDateTime,
        ?string $note = null
    ): array {
        return $this->createActivityFromLead($lead, 'Demo', [
            'dueDateTime' => $scheduledDateTime,
            'note' => $note,
        ]);
    }

    /**
     * Build activity data from Lead
     */
    protected function buildActivityDataFromLead(
        Lead $lead,
        string $activityTaskType,
        array $additionalData = []
    ): array {
        $entityId = $lead->people->get(
            DealerSocketConfigurationService::getCustomerIdKey($lead->people, $this->region)
        );

        if (! $entityId) {
            throw new Exception('Customer does not have a DealerSocket Entity ID. Please create customer first.');
        }

        $eventId = $lead->get(
            DealerSocketConfigurationService::getLeadIdKey($lead, $this->region)
        );

        if (! $eventId) {
            throw new Exception('Lead does not have a DealerSocket Event ID. Please create lead first.');
        }

        $data = [
            'entityId' => (int) $entityId,
            'eventId' => (int) $eventId,
            'activityTaskType' => $activityTaskType,
            'dueDateTime' => $additionalData['dueDateTime'] ?? now()->toIso8601String(),
        ];

        // Add optional fields from additionalData
        if (! empty($additionalData['assignedToUserId'])) {
            $data['assignedToUserId'] = $additionalData['assignedToUserId'];
        } elseif (! empty($additionalData['assignedToUser'])) {
            $data['assignedToUser'] = $additionalData['assignedToUser'];
        } elseif (! empty($additionalData['assignedTo'])) {
            if (is_numeric($additionalData['assignedTo'])) {
                $data['assignedToUserId'] = $additionalData['assignedTo'];
            } else {
                $data['assignedToUser'] = $additionalData['assignedTo'];
            }
        } else {
            // Try to get from lead owner
            $assignedTo = $this->getAssignedToFromLead($lead);
            if ($assignedTo) {
                $data = array_merge($data, $assignedTo);
            }
        }

        // Status
        if (! empty($additionalData['status'])) {
            $data['status'] = $additionalData['status'];
        }

        // Note
        if (! empty($additionalData['note'])) {
            $data['note'] = $additionalData['note'];
        }

        // ActivityId (for updates)
        if (! empty($additionalData['activityId'])) {
            $data['activityId'] = (int) $additionalData['activityId'];
        }

        return $data;
    }

    /**
     * Get assigned to user from lead
     */
    protected function getAssignedToFromLead(Lead $lead): ?array
    {
        try {
            $owner = $lead->owner;

            if (! $owner) {
                return null;
            }

            $dmsUserId = $owner->get('dealersocket_user_id');
            if ($dmsUserId) {
                return ['assignedToUserId' => $dmsUserId];
            }

            $dsUsername = $owner->get('dealersocket_username');
            if ($dsUsername) {
                return ['assignedToUser' => $dsUsername];
            }

            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Validate activity data
     */
    protected function validateActivityData(array $data): void
    {
        $required = ['entityId', 'eventId', 'activityTaskType', 'dueDateTime'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Activity data is missing required field: {$field}");
            }
        }
    }
}
