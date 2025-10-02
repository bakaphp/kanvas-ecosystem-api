<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ofac\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Exception;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\EngagementMessage;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Connectors\Ofac\Client;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Messages\Models\Message;

class OfacClientScreeningAction
{
    public function __construct(
        protected Lead $lead,
        protected Message $message,
        protected AppInterface $app,
        protected People $people
    ) {
    }

    public function execute(): string
    {
        $ofacClient = new Client($this->app);

        // Get the person associated with the lead
        $person = $this->people;

        if (! $person || empty($person->firstname . ' ' . $person->lastname)) {
            throw new Exception('Lead must have an associated person with a name');
        }

        $name = trim($person->firstname . ' ' . $person->lastname);
        $data['cases'][0]['name'] = $name;

        // Add email if available
        $emails = $person->getEmails();
        if ($emails->count() > 0) {
            $data['cases'][0]['ids'] = [
                [
                    'type' => 'email',
                    'id' => $emails->first()->value,
                ],
            ];
        }

        $response = $ofacClient->post('/v3', $data);

        if ((bool) $response['error']) {
            throw new Exception('OFAC API Error: ' . json_encode($response));
        }

        // Generate PDF from template
        $fileLink = $this->createPdfFromMessage($response);

        // Update the existing message with OFAC results
        $this->updateOfacMessage($response, $fileLink);

        // Create engagement based on the updated message
        $this->createEngagement();

        return $fileLink;
    }

    protected function createPdfFromMessage(array $ofacResponse): string
    {
        // Generate PDF using PdfService with OFAC template
        $pdfFile = PdfService::generatePdfFromTemplate(
            $this->app,
            $this->message->user,
            'ofac-v2', // Template name - should exist in resources/views/templates/
            $this->message,
            [
                'ofac' => $ofacResponse,
                'lead' => $this->lead,
                'person' => $this->people,
            ]
        );

        // Attach PDF to the message
        $this->message->addFile($pdfFile, 'ofac_screening_report.pdf');

        // If message has a parent, also attach to parent
        if ($this->message->parent) {
            $this->message->parent->addFile($pdfFile, 'ofac_screening_report.pdf');
        }

        return $pdfFile->url;
    }

    /**
     * Update the existing message with OFAC screening results
     */
    protected function updateOfacMessage(array $ofacResponse, string $fileLink): void
    {
        // Create engagement message DTO with the exact structure you need
        $engagementMessage = new EngagementMessage(
            data: [
                'form' => [
                    'file' => $fileLink,
                    'ofac' => $ofacResponse,
                ],
            ],
            text: 'Ofac',
            verb: 'ofac',
            status: ActionStatusEnum::SUBMITTED->value,
            actionLink: 'https://app.salesassist.io/',
            source: 'ofac-connector',
            linkPreview: 'https://app.salesassist.io/',
            engagementStatus: ActionStatusEnum::SUBMITTED->value,
            visitorId: Str::uuid()->toString(),
            hashtagVisited: 'ofac',
        );

        // Update the existing message content instead of creating new
        // $currentMessage = $this->message->message;
        $updatedMessage = $engagementMessage->toArray();

        $this->message->message = $updatedMessage;
        $this->message->saveOrFail();
    }

    /**
     * Create engagement based on the updated message
     */
    protected function createEngagement(): Engagement
    {
        // Create or get channel for this lead
        $channel = new CreateChannelAction(new Channel(
            apps: $this->app,
            companies: $this->lead->company,
            users: $this->lead->user,
            entity_id: $this->lead->getId(),
            entity_namespace: Lead::class,
            name: $this->lead->uuid,
            slug: $this->lead->uuid,
            description: $this->lead->uuid,
        ))->execute();

        // Add message to channel
        if ($channel) {
            $channel->addMessage($this->message, $this->message->user);
        }

        // Get pipeline and stage for SUBMITTED status
        $pipeline = Pipeline::getBySlug('ofac', $this->app, $this->lead->company);
        $stage = $pipeline->stages()->where('slug', ActionStatusEnum::SUBMITTED->value)->firstOrFail();

        // Get company action for OFAC screening
        $companyAction = CompanyAction::where('companies_id', $this->lead->company->getId())
            ->where('apps_id', $this->app->getId())
            ->whereHas('action', function ($query) {
                $query->where('slug', 'ofac');
            })
            ->firstOrFail();

        // Create engagement following legacy pattern
        $engagement = Engagement::firstOrCreate([
            'companies_id' => $this->lead->company->getId(),
            'apps_id' => $this->app->getId(),
            'users_id' => $this->lead->user->getId(),
            'leads_id' => $this->lead->getId(),
            'people_id' => $this->people->getId(),
            'companies_actions_id' => $companyAction->getId(),
            'message_id' => $this->message->getId(),
            'slug' => 'ofac',
            'entity_uuid' => $this->lead->uuid,
            'pipelines_stages_id' => $stage->getId(),
        ]);

        return $engagement;
    }
}
