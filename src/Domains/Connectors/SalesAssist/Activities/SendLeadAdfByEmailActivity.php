<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Send Lead As ADF Email',
    description: 'Emails the lead as an ADF XML document to a fixed address — the standard way to hand a lead '
        . 'to a dealer system that ingests ADF. It CONTACTS whoever the address belongs to, which is a '
        . 'partner rather than the customer. The recipient is configured on the rule, so check it '
        . 'before attaching.',
    integration: IntegrationsEnum::INTERNAL,
    params: [
        'to' => 'Email address that receives the ADF. Required — the step fails without it.',
        'template_name' => 'Template used to render the email. Defaults to adf-email-template.',
        'subject' => 'Subject line. Defaults to "New ADF Lead from Sales Assist".',
    ],
)]
class SendLeadAdfByEmailActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Lead) {
            return $this->failWorkflow([
                'message' => 'Entity must be a Lead',
                'entity_type' => get_class($entity),
            ]);
        }

        $lead = $entity;
        $to = $params['to'] ?? null;
        $templateName = $params['template_name'] ?? 'adf-email-template';

        if (! is_string($to) || trim($to) === '') {
            return $this->failWorkflow([
                'message' => 'Missing required workflow param: to',
                'lead_id' => $lead->getId(),
                'data' => $params,
            ]);
        }

        if (! is_string($templateName) || trim($templateName) === '') {
            return $this->failWorkflow([
                'message' => 'Missing required workflow param: template_name',
                'lead_id' => $lead->getId(),
                'data' => $params,
            ]);
        }

        $subject = $params['subject'] ?? 'New ADF Lead from Sales Assist';

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Lead $lead, AppInterface $app, mixed $integrationCompany, array $additionalParams) use ($to, $subject, $templateName): array {
                $notification = new Blank(
                    $templateName,
                    [
                        'lead' => $lead,
                        'subject' => $subject,
                    ],
                    ['mail'],
                    $lead,
                );

                $notification->setSubject($subject);

                Notification::route('mail', $to)->notify($notification);

                return [
                    'success' => true,
                    'message' => 'ADF lead email sent successfully',
                    'lead_id' => $lead->getId(),
                    'to' => $to,
                    'subject' => $subject,
                    'template' => $templateName,
                ];
            },
            company: $lead->company,
        );
    }
}
