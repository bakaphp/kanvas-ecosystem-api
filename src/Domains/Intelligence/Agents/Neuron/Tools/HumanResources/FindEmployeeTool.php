<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Resolves a person's name or email to the HR employees it could be, so the agent can turn
 * "Alice" or "alice@acme.com" into the employee whose leave/profile you want to act on. Returns
 * candidates to disambiguate rather than a single guess.
 */
#[AgentTool(name: 'Find Employee', category: 'human_resources')]
class FindEmployeeTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_employee',
            description: 'Finds HR employees matching a name or email, each with their email, position, department '
                . 'and status. Use this to resolve who an employee is before checking their leave balance or updating '
                . 'their profile. Returns candidates — confirm the right one if there is more than one.',
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
                name: 'query',
                type: PropertyType::STRING,
                description: 'The employee name or email to look up.',
                required: true,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max candidates to return. Defaults to 10.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $query, ?int $limit = null): array
    {
        $limit = max(1, min(50, $limit ?? 10));
        $term = trim($query);
        $like = '%' . $term . '%';

        // People (crm) and Users (ecosystem) live on other connections than Employee (hr), so a
        // cross-connection whereHas can't run — resolve the matching ids per connection first.
        $peopleIds = People::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->where('name', 'like', $like)
            ->limit(200)
            ->pluck('id')
            ->all();

        $userIds = Users::query()
            ->where('email', 'like', $like)
            ->limit(200)
            ->pluck('id')
            ->all();

        $matches = Employee::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where(function (Builder $q) use ($peopleIds, $userIds): void {
                $q->whereRaw('1 = 0');
                if ($peopleIds !== []) {
                    $q->orWhereIn('people_id', $peopleIds);
                }
                if ($userIds !== []) {
                    $q->orWhereIn('users_id', $userIds);
                }
            })
            ->limit($limit)
            ->get();

        $employees = $matches->map(fn (Employee $employee): array => [
            'employee_id' => $employee->getId(),
            'name' => $employee->people?->name,
            'email' => $employee->user?->email,
            'position' => $employee->position?->title,
            'department' => $employee->department?->name,
            'status' => $employee->status,
        ])->all();

        return [
            'query' => $query,
            'count' => count($employees),
            'employees' => $employees,
            'note' => $employees === []
                ? "No employee matches \"{$query}\". Do NOT call find_employee again with the same query — "
                    . 'tell the user no match was found and ask them to verify the name/email or confirm the '
                    . 'person has been onboarded as an employee.'
                : 'If more than one candidate is listed, confirm which one before acting.',
        ];
    }
}
