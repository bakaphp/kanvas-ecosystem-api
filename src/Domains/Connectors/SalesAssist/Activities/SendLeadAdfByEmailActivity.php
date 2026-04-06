<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\BuildLeadAdfXmlAction;
use Kanvas\Connectors\SalesAssist\Notifications\LeadAdfXmlNotification;
use Kanvas\Guild\Leads\Models\Lead;
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
        $attachmentName = 'lead-' . $lead->getId() . '.xml';

        $sendAsAttachment = (bool) ($params['send_as_attachment'] ?? false);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Lead $lead, AppInterface $app, mixed $integrationCompany, array $additionalParams) use ($to, $subject, $xml, $attachmentName, $sendAsAttachment): array {
                /** @var Apps $app */
                $this->sendAdfEmail(
                    lead: $lead,
                    app: $app,
                    to: $to,
                    subject: (string) $subject,
                    xml: $xml,
                    attachmentName: $attachmentName,
                    sendAsAttachment: $sendAsAttachment,
                );

                return [
                    'success' => true,
                    'message' => 'ADF lead email sent successfully',
                    'lead_id' => $lead->getId(),
                    'to' => $to,
                    'subject' => $subject,
                    'attachment' => $sendAsAttachment ? $attachmentName : null,
                    'delivery_mode' => $sendAsAttachment ? 'attachment' : 'body',
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
        return (new BuildLeadAdfXmlAction())->execute($lead, [
            'source' => $params['source'] ?? 'Sales Assist',
            'providerName' => $params['provider_name'] ?? 'Sales Assist',
            'service' => $params['service'] ?? 'Sales Assist Lead Delivery',
        ]);
    }

    protected function sendAdfEmail(
        Lead $lead,
        Apps $app,
        string $to,
        string $subject,
        string $xml,
        string $attachmentName,
        bool $sendAsAttachment = false
    ): void {
        $emailContent = $sendAsAttachment
            ? 'Please find attached the ADF lead XML export.'
            : $xml;

        $notification = new LeadAdfXmlNotification(
            lead: $lead,
            app: $app,
            subject: $subject,
            emailContent: $emailContent,
            xmlContent: $xml,
            attachmentName: $attachmentName,
            sendAsAttachment: $sendAsAttachment,
        );

        Notification::route('mail', $to)->notify($notification);
    }
}
