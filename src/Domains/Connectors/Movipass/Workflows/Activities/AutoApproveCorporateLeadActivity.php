<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\CorporateApplications\Actions\ApproveCorporateApplicationAction;
use Kanvas\Companies\CorporateApplications\Concerns\SendsApplicationEmail;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationApprovalModeEnum as ApprovalMode;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Connectors\Movipass\Actions\ValidateCorporateFieldsAction;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class AutoApproveCorporateLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use SendsApplicationEmail;

    public const string DEFAULT_TEMPLATE = 'corporate-needs-review';

    #[Override]
    public function execute(Model $lead, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function (Lead $lead, $app, $integrationCompany, $additionalParams) {
                $mode = ApprovalMode::resolveFor($lead, $app);

                if ($mode === null) {
                    return $this->skip($lead, 'receiver does not take corporate applications');
                }

                $validationError = $this->validate($lead);

                if ($mode === ApprovalMode::MANUAL) {
                    return $this->markPending($lead, $app, $validationError);
                }

                if ($validationError !== null) {
                    return $this->markNeedsReview($lead, $app, $validationError);
                }

                return new ApproveCorporateApplicationAction($lead, $this->appModel($app))->execute();
            },
            company: $lead->company,
        );
    }

    private function validate(Lead $lead): ?string
    {
        $read = fn (string $key): mixed => Field::readApplication($lead, $key);
        $missing = Field::missing(Field::requiredFor($lead->receiver), $read);

        if ($missing !== []) {
            return 'Missing required fields: ' . implode(', ', $missing);
        }

        return new ValidateCorporateFieldsAction([
            'rnc' => $read('rnc'),
            'contact_email' => $read('contact_email'),
        ])->execute();
    }

    private function markPending(Lead $lead, AppInterface $app, ?string $validationHint): array
    {
        Field::STATUS->writeTo($lead, CorporateApplicationStatusEnum::PENDING->value);

        if ($validationHint !== null) {
            Field::VALIDATION_HINT->writeTo($lead, $validationHint);
        }

        $this->sendApplicantEmail(
            $app,
            $lead,
            'Recibimos tu solicitud',
            ['lead' => $lead, 'contactName' => Field::contactName($lead)],
        );

        return [
            'lead' => $lead->getId(),
            'status' => CorporateApplicationStatusEnum::PENDING->value,
            'validation_hint' => $validationHint,
        ];
    }

    private function markNeedsReview(Lead $lead, AppInterface $app, string $reason): array
    {
        Field::STATUS->writeTo($lead, CorporateApplicationStatusEnum::NEEDS_REVIEW->value);
        Field::STATUS_REASON->writeTo($lead, $reason);

        $this->sendApplicantEmail(
            $app,
            $lead,
            'Tu solicitud está en revisión',
            ['lead' => $lead, 'reason' => $reason, 'contactName' => Field::contactName($lead)],
        );

        return [
            'lead' => $lead->getId(),
            'status' => CorporateApplicationStatusEnum::NEEDS_REVIEW->value,
            'reason' => $reason,
        ];
    }

    private function skip(Lead $lead, string $reason): array
    {
        return [
            'lead' => $lead->getId(),
            'status' => 'skipped',
            'reason' => $reason,
        ];
    }

    private function sendApplicantEmail(
        AppInterface $app,
        Lead $lead,
        string $subject,
        array $data,
    ): void {
        $this->sendApplicationEmail(
            $app,
            (string) ($app->get(ConfigurationEnum::CORPORATE_NEEDS_REVIEW_TEMPLATE->value) ?: self::DEFAULT_TEMPLATE),
            $subject,
            $data,
            (string) $lead->email,
            $lead,
        );
    }
}
