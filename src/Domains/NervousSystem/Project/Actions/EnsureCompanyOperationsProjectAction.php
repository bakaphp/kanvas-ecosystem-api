<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\CompanyAssistantLocatorService;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * The one board a company's assistant hands work out on. Found or created, and always owned by the
 * company's designated assistant: no caller picks the owner, or whichever agent delegated first would
 * keep the board for good.
 */
class EnsureCompanyOperationsProjectAction
{
    private const int LOCK_SECONDS = 10;
    private const int LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly Apps $app, private readonly Companies $company)
    {
    }

    public function execute(): Project
    {
        $assistant = CompanyAssistantLocatorService::for($this->app, $this->company);

        if ($assistant === null) {
            throw new ValidationException('This company has no Company Brain agent to own an Operations board.');
        }

        $key = sprintf('ns:company-operations:%d:%d', $this->app->getId(), $this->company->getId());

        // Two people delegating at the same moment must not open two boards.
        return Cache::lock($key, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, fn (): Project => $this->findOrCreate($assistant));
    }

    private function findOrCreate(Agent $assistant): Project
    {
        // On the primary: a board another request committed a moment ago is not on the replica yet,
        // and missing it here opens a second one.
        $existing = Project::query()
            ->useWritePdo()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->companyOperations()
            ->orderBy('id')
            ->first();

        if ($existing instanceof Project) {
            return $this->ownedBy($existing, $assistant);
        }

        $project = new CreateProjectAction(
            ProjectData::from(
                $this->app,
                $assistant->user,
                $this->company,
                [
                    'title' => 'Operations',
                    'objective' => 'Work Company Brain hands to other agents on behalf of the team.',
                    'agent_id' => $assistant->getId(),
                ],
            ),
        )->execute();

        $project->metadata = [...($project->metadata ?? []), Project::OPERATIONS_MARKER => true];
        $project->saveQuietly();

        return $project;
    }

    private function ownedBy(Project $project, Agent $assistant): Project
    {
        // The user is compared too: an assistant moved onto a user of its own keeps its id.
        if ($project->agent_id === $assistant->getId() && $project->users_id === $assistant->user_id) {
            return $project;
        }

        $project->agent_id = $assistant->getId();
        $project->users_id = $assistant->user_id;
        $project->saveQuietly();

        return $project;
    }
}
