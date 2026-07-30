<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Kanvas\Connectors\Movipass\Actions\ApproveCorporateLeadAction;
use Kanvas\Connectors\Movipass\Actions\ValidateCorporateFieldsAction;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\CorporateApplicationStatusEnum;
use Kanvas\Connectors\Movipass\Enums\CorporateLeadFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

/**
 * Despite the name, approval is manual by default — `movipass_corporate_auto_approve`
 * defaults to false because self-reported RNCs and phone numbers turned out to be frequently
 * fake, so an internal admin decides via approveCorporateApplication /
 * rejectCorporateApplication. The name stays because renaming a #[WorkflowAction] orphans its
 * `workflows_actions` rows and every workflow rule pointing at them.
 */
#[WorkflowAction]
class AutoApproveCorporateLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $lead, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) {
                /** @var Lead $lead */
                $configuredReceiverId = $app->get(ConfigurationEnum::CORPORATE_RECEIVER_ID->value);

                if (empty($configuredReceiverId)) {
                    return $this->skip($lead, 'corporate receiver not configured for this app');
                }

                if ((int) $lead->leads_receivers_id !== (int) $configuredReceiverId) {
                    return $this->skip($lead, 'lead is not from the corporate receiver');
                }

                $validationError = $this->validate($lead);

                if (! (bool) ($app->get(ConfigurationEnum::CORPORATE_AUTO_APPROVE->value) ?? false)) {
                    return $this->markPending($lead, $app, $validationError);
                }

                if ($validationError !== null) {
                    return $this->markNeedsReview($lead, $app, $validationError);
                }

                return new ApproveCorporateLeadAction($lead)->execute();
            },
            company: $lead->company,
        );
    }

    private function validate(Lead $lead): ?string
    {
        return new ValidateCorporateFieldsAction([
            'rnc' => $lead->get('rnc'),
            'legal_name' => $lead->get('legal_name'),
            'contact_email' => $lead->get('contact_email') ?: $lead->email,
        ])->execute();
    }

    /**
     * Validation runs but does not block: a malformed RNC is exactly what the reviewer needs
     * to see, not a reason to keep the application out of the queue.
     */
    private function markPending(Lead $lead, AppInterface $app, ?string $validationHint): array
    {
        $lead->set(CorporateLeadFieldEnum::STATUS->value, CorporateApplicationStatusEnum::PENDING->value);

        if ($validationHint !== null) {
            $lead->set(CorporateLeadFieldEnum::VALIDATION_HINT->value, $validationHint);
        }

        $this->sendApplicantEmail(
            $app,
            $lead,
            ConfigurationEnum::CORPORATE_NEEDS_REVIEW_TEMPLATE,
            'corporate-needs-review',
            'Recibimos tu solicitud',
            ['lead' => $lead, 'contactName' => $lead->get('contact_name') ?? $lead->firstname],
        );

        return [
            'lead' => $lead->getId(),
            'status' => CorporateApplicationStatusEnum::PENDING->value,
            'validation_hint' => $validationHint,
        ];
    }

    private function markNeedsReview(Lead $lead, AppInterface $app, string $reason): array
    {
        $lead->set(CorporateLeadFieldEnum::STATUS->value, CorporateApplicationStatusEnum::NEEDS_REVIEW->value);
        $lead->set(CorporateLeadFieldEnum::STATUS_REASON->value, $reason);

        $this->sendApplicantEmail(
            $app,
            $lead,
            ConfigurationEnum::CORPORATE_NEEDS_REVIEW_TEMPLATE,
            'corporate-needs-review',
            'Tu solicitud está en revisión',
            ['lead' => $lead, 'reason' => $reason, 'contactName' => $lead->get('contact_name') ?? $lead->firstname],
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
        ConfigurationEnum $templateSetting,
        string $fallbackTemplate,
        string $subject,
        array $data
    ): void {
        $email = trim((string) $lead->email);

        if ($email === '') {
            return;
        }

        $data['app'] = $app;
        $templateName = (string) ($app->get($templateSetting->value) ?: $fallbackTemplate);

        $notification = new Blank($templateName, $data, ['mail'], $lead);
        $notification->setSubject($subject);

        try {
            LaravelNotification::route('mail', $email)->notify($notification);
        } catch (Throwable $e) {
            // Email failures don't fail the activity — the Lead status is the source of truth.
            report($e);
        }
    }
}
