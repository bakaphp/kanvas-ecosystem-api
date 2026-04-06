<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Connectors\SalesAssist\Actions\BuildLeadAdfXmlAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SendLeadAdfByEmailActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->bootstrapAppService($app);

        if (! $entity instanceof Lead) {
            return $this->failWorkflow([
                'message' => 'Entity must be a Lead',
                'entity_type' => get_class($entity),
            ]);
        }

        $lead = $entity;
        $to = $params['to'] ?? null;

        if (! is_string($to) || trim($to) === '') {
            return $this->failWorkflow([
                'message' => 'Missing required workflow param: to',
                'lead_id' => $lead->getId(),
                'data' => $params,
            ]);
        }

        if (! $lead->people) {
            return $this->failWorkflow([
                'message' => 'Lead must have people relation to build ADF email',
                'lead_id' => $lead->getId(),
            ]);
        }

        $subject = $params['subject'] ?? 'New ADF Lead from Sales Assist';
        $xml = $this->buildAdfXml($lead, $params);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Lead $lead, AppInterface $app, mixed $integrationCompany, array $additionalParams) use ($to, $subject, $xml): array {
                $this->sendAdfEmail(
                    lead: $lead,
                    app: $app,
                    to: $to,
                    subject: (string) $subject,
                    xml: $xml,
                );

                return [
                    'success' => true,
                    'message' => 'ADF lead email sent successfully',
                    'lead_id' => $lead->getId(),
                    'to' => $to,
                    'subject' => $subject,
                ];
            },
            company: $lead->company,
        );
    }

    protected function bootstrapAppService(AppInterface $app): void
    {
        $this->overwriteAppService($app);
    }

    protected function buildAdfXml(Lead $lead, array $params): string
    {
        return new BuildLeadAdfXmlAction()->execute($lead, [
            'source' => $params['source'] ?? 'Sales Assist',
            'providerName' => $params['provider_name'] ?? 'Sales Assist',
            'service' => $params['service'] ?? 'Sales Assist Lead Delivery',
        ]);
    }

    protected function sendAdfEmail(
        Lead $lead,
        AppInterface $app,
        string $to,
        string $subject,
        string $xml,
    ): void {
        $notification = new Blank(
            'blank',
            [
                'app' => $app,
                'subject' => $subject,
                'company' => $lead->company,
                'html' => $xml,
            ],
            ['mail'],
            $lead,
        );

        $notification->setSubject($subject);

        Notification::route('mail', $to)->notify($notification);
    }
}
