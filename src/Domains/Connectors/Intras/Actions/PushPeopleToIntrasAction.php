<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Carbon\Carbon;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;

/**
 * Writes an approved change set (DiffPeopleWithIntrasAction's shape) to SIPGO — the snapshot the
 * approver saw, not the People as it is now, so a SIPGO pull landing between request and approval
 * cannot change what gets written.
 */
class PushPeopleToIntrasAction
{
    /**
     * @param array{participant?: array<string, array{from: ?string, to: string}>, custom_fields?: array<string, array{from: ?string, to: string}>} $changes
     */
    public function __construct(
        protected People $people,
        protected array $changes,
    ) {
    }

    public function execute(): array
    {
        $participantId = (int) $this->people->get(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value);

        if ($participantId <= 0) {
            throw new ValidationException('People ' . $this->people->getId() . ' is not linked to a SIPGO participant.');
        }

        $columns = array_map(fn (array $change) => $change['to'], $this->changes['participant'] ?? []);
        $customFields = array_map(fn (array $change) => $change['to'], $this->changes['custom_fields'] ?? []);

        if ($columns === [] && $customFields === []) {
            return ['participant_id' => $participantId, 'participant' => [], 'custom_fields' => []];
        }

        $client = new Client($this->people->app);

        $client->getConnection()->transaction(function () use ($client, $participantId, $columns, $customFields) {
            $client->table('participants')
                ->where('id', $participantId)
                ->update([
                    ...$columns,
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);

            $this->writeCustomFields($client, $participantId, $customFields);
        });

        return [
            'participant_id' => $participantId,
            'participant' => array_keys($columns),
            'custom_fields' => array_keys($customFields),
        ];
    }

    /**
     * participants_custom_fields has no unique key and SIPGO already carries duplicate rows per
     * (participant, field), so every matching row is updated and a row is inserted only when none
     * exists.
     *
     * @param array<string, string> $values
     */
    protected function writeCustomFields(Client $client, int $participantId, array $values): void
    {
        if ($values === []) {
            return;
        }

        $fieldIds = $client->table('custom_fields')
            ->where('modules_id', ParticipantMapper::PARTICIPANT_CUSTOM_FIELDS_MODULE_ID)
            ->whereIn('name', array_keys($values))
            ->pluck('id', 'name');

        $missing = array_diff(array_keys($values), $fieldIds->keys()->all());

        if ($missing !== []) {
            throw new ValidationException('SIPGO has no participant custom field named: ' . implode(', ', $missing));
        }

        foreach ($values as $name => $value) {
            $rows = $client->table('participants_custom_fields')
                ->where('participants_id', $participantId)
                ->where('custom_fields_id', $fieldIds[$name]);

            if ((clone $rows)->exists()) {
                $rows->update(['value' => $value]);

                continue;
            }

            $client->table('participants_custom_fields')->insert([
                'participants_id' => $participantId,
                'custom_fields_id' => $fieldIds[$name],
                'value' => $value,
            ]);
        }
    }
}
