<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;

class SetupPeopleClassificationInIntrasAction
{
    public function __construct(private readonly AppInterface $app, private readonly bool $dryRun = false)
    {
    }

    /**
     * @return list<string> the custom fields that were missing from SIPGO
     */
    public function execute(): array
    {
        $client = new Client($this->app);

        $existing = $client->table('custom_fields')
            ->where('modules_id', ParticipantMapper::PARTICIPANT_CUSTOM_FIELDS_MODULE_ID)
            ->whereIn('name', array_keys(ParticipantMapper::CLASSIFICATION_FIELDS))
            ->pluck('name')
            ->all();

        $missing = array_diff_key(ParticipantMapper::CLASSIFICATION_FIELDS, array_flip($existing));

        if ($this->dryRun) {
            return array_keys($missing);
        }

        foreach ($missing as $name => $label) {
            $client->table('custom_fields')->insert([
                'modules_id' => ParticipantMapper::PARTICIPANT_CUSTOM_FIELDS_MODULE_ID,
                'type' => 'text',
                'label' => $label,
                'name' => $name,
            ]);
        }

        return array_keys($missing);
    }
}
