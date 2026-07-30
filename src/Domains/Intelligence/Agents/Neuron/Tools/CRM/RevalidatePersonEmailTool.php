<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\Mailgun\Actions\ValidatePeopleEmailAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Re-checks the deliverability of a person's email address(es) and updates their validation status —
 * the fix side of the bounce tooling (list_bounces surfaces bad emails; this re-verifies one person).
 * Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Revalidate Person Email', category: 'crm')]
class RevalidatePersonEmailTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'revalidate_person_email',
            description: 'Re-check whether a person\'s email address(es) are deliverable and refresh their validation '
                . 'status (valid / soft_bounce / hard_bounce / invalid). Use after "is this email still good?" or to '
                . 're-verify a contact flagged by list_bounces. Identify the person by person_id.',
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
                name: 'person_id',
                type: PropertyType::INTEGER,
                description: 'The id of the person whose email(s) to re-validate.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $person_id): array
    {
        try {
            /** @var People $person */
            $person = People::getByIdFromCompanyApp($person_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No person #%d found in this company.', $person_id)];
        }

        if ($person->getEmails()->isEmpty()) {
            return ['people_id' => $person->getId(), 'validated' => [], 'message' => 'This person has no email addresses to validate.'];
        }

        try {
            $result = new ValidatePeopleEmailAction($person, $this->app)->execute();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return $result;
    }
}
