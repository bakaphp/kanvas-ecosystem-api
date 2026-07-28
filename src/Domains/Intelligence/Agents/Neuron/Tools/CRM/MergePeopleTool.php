<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Actions\MergePeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Merges a duplicate person into a surviving one — moves leads, deals, contacts, addresses,
 * organizations, employment history and custom fields onto the target, then soft-deletes the source.
 * Irreversible; confirm with the user before calling. Company-wide write — internal-teammate only.
 */
#[AgentTool(name: 'Merge People', category: 'crm')]
class MergePeopleTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'merge_people',
            description: 'Merge a duplicate contact into another. source_person_id is the duplicate (soft-deleted '
                . 'after), target_person_id is the survivor that keeps everything. All leads, contacts, addresses, '
                . 'organizations and custom fields move to the target. This is irreversible — confirm the two ids '
                . 'with the user first (use find_person / get_person to verify they are the same person).',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'source_person_id',
                type: PropertyType::INTEGER,
                description: 'The duplicate person id to merge FROM (soft-deleted after the merge).',
                required: true,
            ),
            new ToolProperty(
                name: 'target_person_id',
                type: PropertyType::INTEGER,
                description: 'The surviving person id to merge INTO (keeps all data).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $source_person_id, int $target_person_id): array
    {
        if ($source_person_id === $target_person_id) {
            return ['error' => 'source_person_id and target_person_id must be different.'];
        }

        try {
            /** @var People $source */
            $source = People::getByIdFromCompanyApp($source_person_id, $this->company, $this->app);
            /** @var People $target */
            $target = People::getByIdFromCompanyApp($target_person_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => 'One or both people were not found in this company.'];
        }

        try {
            $survivor = new MergePeopleAction($source, $target, $this->user)->execute();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'person_id' => $survivor->getId(),
            'name' => $survivor->getName(),
            'merged_from' => $source_person_id,
            'message' => 'People merged.',
        ];
    }
}
