<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Actions\ProcessPeopleDriverLicenseVerificationAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'SalesAssist Process Person Driver Licence',
    description: 'Reads a driver licence image on a person or lead and runs identity verification on what it '
        . 'extracts. Verification can notify the person, so this is not purely an extraction step.',
    integration: IntegrationsEnum::SALESASSIST,
)]
class PeopleProcessDriverLicenseImageActivity extends KanvasActivity
{
    public $tries = 3;

    protected Apps $app;
    protected Companies $company;
    protected Users $user;

    public function execute(People|Lead $people, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $this->app = $app;
        $this->company = $people->company;
        $this->user = $people->user;

        $sentParticipants = $params['participant'] ?? [];

        if (empty($sentParticipants) || ! isset($sentParticipants['peopleId'])) {
            return [
                'success' => false,
                'message' => 'No participants sent',
            ];
        }

        $leadParticipant = LeadParticipant::where('peoples_id', $sentParticipants['peopleId'])
           ->whereHas('lead', function (Builder $query) {
               $query->where('is_deleted', 0)
                   ->whereHas('status', function (Builder $query) {
                       $query->whereIn('name', ['active', 'created']);
                   });
           })
           ->with('lead')
           ->orderBy('created_at', 'desc')
           ->first();

        $lead = $leadParticipant ? $leadParticipant->lead : null;
        $people = $leadParticipant ? $leadParticipant->people : null;

        if ($lead === null) {
            return [
                'success' => false,
                'message' => 'No active lead found for this person',
            ];
        }

        if ($people === null) {
            return [
                'success' => false,
                'person_id' => $sentParticipants['peopleId'],
                'message' => 'No people found for this person',
            ];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) use ($params, $lead) {
                sleep(30);

                return new ProcessPeopleDriverLicenseVerificationAction(
                    $people,
                    $params,
                    $lead,
                )->execute();
            },
            company: $people->company,
        );
    }
}
