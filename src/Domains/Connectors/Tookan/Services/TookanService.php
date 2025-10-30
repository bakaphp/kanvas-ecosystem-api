<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Tookan\Client;
use Kanvas\Connectors\Tookan\DataTransferObject\TaskDetail;
use Kanvas\Connectors\Tookan\Enums\ConfigurationEnum;

class TookanService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $config = []
    ) {
        $this->client = new Client($app, $company, $config);
    }

    /**
     * Create a new task in Tookan.
     */
    public function createTask(TaskDetail $task): array
    {
        $response = $this->client->post(
            ConfigurationEnum::CREATE_TASK_PATH->value,
            $task->toArray()
        );

        return [
            'job_id' => $response['data']['job_id'] ?? null,
            'job_status' => $response['data']['job_status'] ?? null,
            'job_token' => $response['data']['job_token'] ?? null,
            'tracking_link' => $response['data']['tracking_link'] ?? null,
            'fleet_id' => $response['data']['fleet_id'] ?? null,
            'fleet_name' => $response['data']['fleet_name'] ?? null,
        ];
    }

    /**
     * Update an existing task.
     */
    public function updateTask(int $jobId, array $updateData): array
    {
        $data = array_merge(['job_id' => $jobId], $updateData);

        $response = $this->client->post(
            ConfigurationEnum::UPDATE_TASK_PATH->value,
            $data
        );

        return $response['data'] ?? [];
    }

    /**
     * Get task details by job ID.
     */
    public function getTaskDetails(int $jobId): array
    {
        $response = $this->client->post(
            ConfigurationEnum::GET_TASK_DETAILS_PATH->value,
            ['job_id' => $jobId]
        );

        return $response['data'] ?? [];
    }

    /**
     * Get job details (similar to task details but different endpoint).
     */
    public function getJobDetails(array $jobIds): array
    {
        $response = $this->client->post(
            ConfigurationEnum::GET_JOB_DETAILS_PATH->value,
            ['job_ids' => $jobIds]
        );

        return $response['data'] ?? [];
    }

    /**
     * Delete a task.
     */
    public function deleteTask(int $jobId): array
    {
        $response = $this->client->post(
            ConfigurationEnum::DELETE_TASK_PATH->value,
            ['job_id' => $jobId]
        );

        return [
            'success' => $response['status'] == 200,
            'message' => $response['message'] ?? 'Task deleted successfully',
        ];
    }

    /**
     * Get all available agents/fleets.
     */
    public function getAvailableAgents(?int $teamId = null): array
    {
        $data = [];
        if ($teamId) {
            $data['team_id'] = $teamId;
        }

        $response = $this->client->post(
            ConfigurationEnum::GET_AGENT_PATH->value,
            $data
        );

        return $response['data'] ?? [];
    }

    /**
     * Assign an agent to a task.
     */
    public function assignAgent(int $jobId, int $fleetId): array
    {
        $response = $this->client->post(
            ConfigurationEnum::ASSIGN_AGENT_PATH->value,
            [
                'job_id' => $jobId,
                'fleet_id' => $fleetId,
            ]
        );

        return [
            'success' => $response['status'] == 200,
            'message' => $response['message'] ?? 'Agent assigned successfully',
        ];
    }

    /**
     * Get all tasks with filters.
     */
    public function getAllTasks(array $filters = []): array
    {
        $response = $this->client->post(
            ConfigurationEnum::GET_ALL_TASKS_PATH->value,
            $filters
        );

        return $response['data'] ?? [];
    }

    /**
     * Get all fleets/agents.
     */
    public function getAllFleets(?int $teamId = null): array
    {
        $data = [];
        if ($teamId) {
            $data['team_id'] = $teamId;
        }

        $response = $this->client->post(
            ConfigurationEnum::GET_ALL_FLEETS_PATH->value,
            $data
        );

        return $response['data'] ?? [];
    }

    /**
     * Create a merchant.
     */
    public function createMerchant(array $merchantData): array
    {
        $response = $this->client->post(
            ConfigurationEnum::CREATE_MERCHANT_PATH->value,
            $merchantData
        );

        return $response['data'] ?? [];
    }

    /**
     * Update a merchant.
     */
    public function updateMerchant(int $merchantId, array $merchantData): array
    {
        $data = array_merge(['merchant_id' => $merchantId], $merchantData);

        $response = $this->client->post(
            ConfigurationEnum::UPDATE_MERCHANT_PATH->value,
            $data
        );

        return $response['data'] ?? [];
    }

    /**
     * Delete a merchant.
     */
    public function deleteMerchant(int $merchantId): array
    {
        $response = $this->client->post(
            ConfigurationEnum::DELETE_MERCHANT_PATH->value,
            ['merchant_id' => $merchantId]
        );

        return [
            'success' => $response['status'] == 200,
            'message' => $response['message'] ?? 'Merchant deleted successfully',
        ];
    }

    /**
     * Get merchant details.
     */
    public function getMerchant(int $merchantId): array
    {
        $response = $this->client->post(
            ConfigurationEnum::GET_MERCHANT_PATH->value,
            ['merchant_id' => $merchantId]
        );

        return $response['data'] ?? [];
    }
}
