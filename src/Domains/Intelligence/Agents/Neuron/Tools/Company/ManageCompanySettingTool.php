<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Company;

use JsonException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Manage Company Setting', category: 'company')]
class ManageCompanySettingTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use RunsInExplicitCompany;
    use ReportsToolOutcome;
    use TrackByInputs;

    private const array SETTINGS = [
        'ai' => 'boolean',
        'sales_assist_ai_assist_enabled' => 'boolean',
        'enable_ai_notes_channel' => 'boolean',
        'ai-agent-user-id' => 'agent_user',
        'agent_reach_out_default_agent_id' => 'agent',
        'adf_sources' => 'sources',
        'TWILIO_ACCOUNT_SID' => 'credential',
        'TWILIO_AUTH_TOKEN' => 'credential',
        'twilio_sender_account_sid' => 'credential',
        'twilio_from_phone_number' => 'phone',
        'twilio_phone_number' => 'phone',
        'twilio_allowed_from_phone_numbers' => 'phones',
        'twilio_enforce_a2p_registration' => 'boolean',
        'twilio_max_message_body_length' => 'positive_integer',
        'twilio_batch_delay_seconds' => 'nonnegative_integer',
        'twilio-carrier-retry-delay-minutes' => 'nonnegative_integer',
        'twilio_messaging_service_sid' => 'messaging_service',
    ];

    protected string $name = 'manage_company_setting';

    protected ?string $description = 'Inspect or change any company setting by its exact key in THIS company. Admin only. '
        . 'Use operation=catalog for known setting types, get to inspect any key, set to save a JSON value. '
        . 'All writes are private. Agent references must already belong to this company. Credentials '
        . 'are status-only: configure them through the existing secure administrator setup, never chat. '
        . 'Changing AI settings may enable automation immediately. Phone syntax does not prove Twilio '
        . 'ownership or A2P registration; verify those separately before applying approved values. '
        . 'Custom fields are separate from company settings. Unknown keys accept non-null JSON values.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'operation',
                type: PropertyType::STRING,
                description: 'catalog, get or set.',
                required: true,
            ),
            new ToolProperty(
                name: 'key',
                type: PropertyType::STRING,
                description: 'Exact company setting key, including custom keys outside catalog. Required for get/set.',
                required: false,
            ),
            new ToolProperty(
                name: 'value_json',
                type: PropertyType::STRING,
                description: 'For set: JSON-encoded value, e.g. true, 1600, 123, "+18095550123", or an array. Never credentials.',
                required: false,
            ),
            new ToolProperty(
                name: 'company_uuid',
                type: PropertyType::STRING,
                description: 'Optional company UUID for this call only. Requires the Company Configuration Administrator at app scope '
                    . 'and an identified app administrator. Omit to use the current company.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        string $operation,
        ?string $key = null,
        ?string $value_json = null,
        ?string $company_uuid = null,
    ): array {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->contextAgent(), fn (self $tool): array => $tool($operation, $key, $value_json));
        }

        if (! $this->hasTenantContext() || (int) $this->company->getId() <= 0) {
            return $this->denied('A concrete company context is required. Nothing was changed.');
        }
        if ($denied = $this->requireRequestingAdminOrError()) {
            return $this->denied($denied['message']);
        }

        $operation = trim($operation);
        $key = trim((string) $key);
        if ($operation === 'catalog') {
            return $this->ok(['settings' => array_map(
                fn (string $type): array => ['type' => $type, 'writable' => $type !== 'credential', 'public' => false],
                self::SETTINGS,
            )]);
        }
        if (! in_array($operation, ['get', 'set'], true) || $key === '' || strlen($key) > 255 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return $this->invalidArgs('Use get or set with a nonempty key of at most 255 bytes and no control characters. Nothing was changed.');
        }

        $type = self::SETTINGS[$key] ?? ($this->isCredentialKey($key) ? 'credential' : 'json');
        if ($operation === 'set' && $type === 'credential') {
            return $this->denied('Configure credentials through secure administrator setup, never through chat. Nothing was changed.');
        }

        try {
            if ($operation === 'get') {
                $value = $this->company->get($key);
                $result = ['key' => $key, 'configured' => $value !== null && $value !== ''];
                if ($type !== 'credential') {
                    $result['value'] = $value;
                }
                return $this->ok($result);
            }
            if ($value_json === null) {
                return $this->invalidArgs('set requires value_json. Nothing was changed.');
            }
            $value = json_decode($value_json, true, 64, JSON_THROW_ON_ERROR);
            if ($error = $this->validateValue($type, $value)) {
                return $this->invalidArgs($error . ' Nothing was changed.');
            }
            if (! $this->company->set($key, $value, false)) {
                return $this->failed('The setting was not saved.');
            }
            return $this->ok(['key' => $key, 'updated' => true, 'public' => false, 'value' => $value]);
        } catch (JsonException) {
            return $this->invalidArgs('value_json must be valid JSON. Nothing was changed.');
        } catch (Throwable $e) {
            report($e);
            return $this->failed('Could not complete the setting operation. Verify the saved state before retrying.');
        }
    }

    private function validateValue(string $type, mixed $value): ?string
    {
        $valid = match ($type) {
            'boolean' => is_bool($value),
            'agent', 'agent_user', 'positive_integer' => is_int($value) && $value > 0,
            'nonnegative_integer' => is_int($value) && $value >= 0,
            'phone' => $this->isPhone($value),
            'phones' => is_array($value) && array_is_list($value) && $value !== []
                && count(array_filter($value, $this->isPhone(...))) === count($value)
                && count(array_unique($value)) === count($value),
            'messaging_service' => is_string($value) && preg_match('/^MG[a-fA-F0-9]{32}$/D', $value) === 1,
            'sources' => is_array($value) && array_is_list($value),
            'json' => $value !== null,
            default => false,
        };
        if (! $valid) {
            return 'Value does not match the required type: ' . $type . '.';
        }
        if (in_array($type, ['agent', 'agent_user'], true)) {
            if (! $this->localAgentReferenceExists($type, $value)) {
                return 'The reference must identify an existing agent (or its user) in this app and company.';
            }
        }
        if ($type === 'sources') {
            return $this->validateSources($value);
        }
        return null;
    }

    protected function localAgentReferenceExists(string $type, int $value): bool
    {
        return Agent::query()->fromApp($this->app)->fromCompany($this->company)->notDeleted()
            ->where($type === 'agent' ? 'id' : 'user_id', $value)->exists();
    }

    private function isCredentialKey(string $key): bool
    {
        return preg_match('/secret|token|password|passwd|credential|private.?key|api.?key|client.?key|access.?key|authorization/i', $key) === 1;
    }

    private function isPhone(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\+[1-9][0-9]{7,14}$/D', $value) === 1;
    }

    private function validateSources(array $sources): ?string
    {
        $fields = ['Up Type', 'Source', 'Sub_Source', 'Description', 'Lead_Intention', 'Default_Completion_Status', 'Backend'];
        $seen = [];
        foreach ($sources as $row) {
            if (! is_array($row) || count($row) !== count($fields) || array_diff($fields, array_keys($row)) !== []) {
                return 'Each adf_sources row must contain exactly: ' . implode(', ', $fields) . '.';
            }
            foreach ($fields as $field) {
                if (! is_string($row[$field]) || ($field !== 'Sub_Source' && trim($row[$field]) === '')) {
                    return 'adf_sources fields must be strings; required fields cannot be blank.';
                }
            }
            if (! in_array($row['Default_Completion_Status'], ['Complete', 'Incomplete'], true)) {
                return 'Default_Completion_Status must be Complete or Incomplete.';
            }
            $identity = json_encode([$row['Source'], $row['Sub_Source']]);
            if (isset($seen[$identity])) {
                return 'Duplicate Source/Sub_Source pair in adf_sources.';
            }
            $seen[$identity] = true;
        }
        return null;
    }
}
