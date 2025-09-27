<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Actions\ProcessPeopleDriverLicenseVerificationAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PeopleProcessDriverLicenseImageActivity extends KanvasActivity
{
    public $tries = 3;

    protected Apps $app;
    protected Companies $company;
    protected Users $user;

    public function execute(People $people, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $this->app = $app;
        $this->company = $people->company;
        $this->user = $people->user;

        /**
        * @todo for now it will only work with lead participants
        * combine with processlead cause we have a lot of repeated code
        */
        $leadParticipant = LeadParticipant::where('peoples_id', $people->getId())
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

        if ($lead === null) {
            return [
                'success' => false,
                'message' => 'No active lead found for this person',
            ];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) use ($params) {
                // Use the new action class
                sleep(30); // wait for 30 seconds to make sure the image is processed
                $action = new ProcessPeopleDriverLicenseVerificationAction(
                    $people,
                    $params
                );

                return $action->execute();
            },
            company: $people->company,
        );
    }
}
