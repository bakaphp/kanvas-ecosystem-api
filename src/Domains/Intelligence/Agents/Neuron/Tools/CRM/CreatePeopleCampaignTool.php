<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Campaigns\Actions\CreateBatchCampaignAction;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Campaigns\Services\CampaignAttachmentMatcherService;
use Kanvas\Guild\Customers\Actions\SendEmailToPeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Services\BatchRecipientResolverService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCompanyUserForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Templates\Repositories\TemplatesRepository;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use Override;
use Throwable;

#[AgentTool(name: 'Create People Campaign', category: 'crm')]
class CreatePeopleCampaignTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesCompanyUserForTool;

    protected string $name = 'create_people_campaign';

    protected ?string $description = 'Send or schedule an email campaign to a list of people where each person gets something of '
        . 'their own: their own attachment(s), their own template variables, or files paired to them by name '
        . '(certificates, personalised greetings, ids). If every recipient gets the identical email and the same '
        . 'attachments, use create_lead_campaign instead. Pass recipients as '
        . '{person_id, variables, attachment_urls}: variables is a JSON object string whose keys become top-level '
        . 'template values; the template also always sees `people` (name, firstname, lastname, email, phone, '
        . 'custom_fields) and `content` (the message). To pair uploaded files to people without listing them, pass '
        . 'attachment_filesystem_ids plus attachment_match_field: a file matches the person whose custom field equals '
        . 'the file name without its extension (PA40876.pdf -> participant_id PA40876); people with no matching file '
        . 'are excluded and reported. attachment_urls at the top level go to everyone. Eligibility is re-verified: '
        . 'opted-out / do-not-contact / undeliverable / duplicate people are excluded even if passed. ALWAYS run once '
        . 'with test_to set to the requesting user\'s email first: that renders the first recipient and mails it to '
        . 'that teammate only, creating no campaign; only after they approve call again without test_to. Use '
        . 'create_template / list_templates for template_name. Set important only when the user explicitly asks. '
        . 'Admin-only.';

    private const int MAX_RECIPIENTS = 5000;

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'recipients',
                description: 'One entry per person. At least one is required, max ' . self::MAX_RECIPIENTS . '.',
                required: true,
                items: new ObjectProperty(
                    name: 'recipient',
                    description: 'A person and what is specific to their email.',
                    properties: [
                        new ToolProperty(
                            name: 'person_id',
                            type: PropertyType::INTEGER,
                            description: 'The person id (required).',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'variables',
                            type: PropertyType::STRING,
                            description: 'Optional JSON object of template values for this person, e.g. {"Saludo":"Hola","IDParticipante":"40876"}.',
                            required: false,
                        ),
                        new ToolProperty(
                            name: 'attachment_urls',
                            type: PropertyType::STRING,
                            description: 'Optional comma-separated file URLs for this person only.',
                            required: false,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'message',
                type: PropertyType::STRING,
                description: 'The message body, available to the template as `content`.',
                required: true,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'Email subject line.',
                required: false,
            ),
            new ToolProperty(
                name: 'schedule_at',
                type: PropertyType::STRING,
                description: 'Optional future date-time (e.g. "2026-08-10 09:00") to schedule the send. Omit to send now.',
                required: false,
            ),
            new ToolProperty(
                name: 'template_name',
                type: PropertyType::STRING,
                description: 'Name of a templates row to render the email in (from create_template or list_templates). Omit to use the default look.',
                required: false,
            ),
            new ArrayProperty(
                name: 'attachment_urls',
                description: 'Optional file URLs attached for every recipient (e.g. from upload_file_to_message or get_file_link).',
                required: false,
                items: new ToolProperty(name: 'url', type: PropertyType::STRING, description: 'A file URL.'),
            ),
            new ArrayProperty(
                name: 'attachment_filesystem_ids',
                description: 'Optional ids of uploaded files to pair to people by file name; requires attachment_match_field.',
                required: false,
                items: new ToolProperty(name: 'filesystem_id', type: PropertyType::INTEGER, description: 'A filesystem id from an attached file.'),
            ),
            new ToolProperty(
                name: 'attachment_match_field',
                type: PropertyType::STRING,
                description: 'The person custom field whose value equals the file name without extension, e.g. participant_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'test_to',
                type: PropertyType::STRING,
                description: 'Email of a user in this company. When set, only that user receives the first recipient\'s rendered email and no campaign is created.',
                required: false,
            ),
            new ToolProperty(
                name: 'important',
                type: PropertyType::BOOLEAN,
                description: 'Flag the email as important/high-priority in the recipient\'s mail client. Defaults to false; only set true when the user explicitly asks for it.',
                required: false,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $recipients
     * @param  list<string>|null  $attachment_urls
     * @param  list<int>|null  $attachment_filesystem_ids
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        array $recipients,
        string $message,
        ?string $subject = null,
        ?string $schedule_at = null,
        ?string $template_name = null,
        ?array $attachment_urls = null,
        ?bool $important = null,
        ?array $attachment_filesystem_ids = null,
        ?string $attachment_match_field = null,
        ?string $test_to = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $this->denied($denied['message']);
        }

        $message = trim($message);
        if ($message === '') {
            return $this->invalidArgs('A message body is required.');
        }
        $important ??= false;

        $extras = $this->normalizeRecipients($recipients);
        if (is_string($extras)) {
            return $this->invalidArgs($extras);
        }
        if ($extras === []) {
            return $this->invalidArgs('Provide at least one recipient with a valid person_id.');
        }
        if (count($extras) > self::MAX_RECIPIENTS) {
            return $this->invalidArgs('Too many people in one batch (max ' . self::MAX_RECIPIENTS . '). Narrow the list.');
        }

        $scheduledAt = null;
        if (($schedule_at = trim((string) $schedule_at)) !== '') {
            try {
                $scheduledAt = Carbon::parse($schedule_at);
            } catch (Throwable) {
                return $this->invalidArgs('Could not understand schedule_at. Use a date-time like "2026-08-10 09:00".');
            }
            if ($scheduledAt->isPast()) {
                return $this->invalidArgs('schedule_at must be in the future.');
            }
        }

        $templateName = Str::trimToNull($template_name);
        if ($templateName !== null) {
            try {
                TemplatesRepository::getByName($templateName, $this->app, $this->company);
            } catch (ModelNotFoundException) {
                return $this->notFound(
                    ['template_name' => $templateName],
                    "No template named \"{$templateName}\" exists for this company. Call create_template to make it, "
                    . 'or list_templates to see what already exists.',
                );
            }
        }

        $matchField = trim((string) $attachment_match_field);
        $fileIds = array_values(array_filter(array_map('intval', $attachment_filesystem_ids ?? []), static fn (int $id): bool => $id > 0));
        if ($fileIds !== [] && $matchField === '') {
            return $this->invalidArgs('attachment_match_field is required when attachment_filesystem_ids is given: name the person custom field the file names match.');
        }

        $sharedUrls = $this->urls($attachment_urls ?? []);

        $people = People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('id', array_keys($extras))
            ->get();

        $resolved = new BatchRecipientResolverService()->resolvePeople($people, 'email');
        $eligible = $resolved['eligible'];
        $excluded = $resolved['excluded'];
        $unmatchedFiles = [];

        if ($fileIds !== []) {
            $eligibleIds = array_map(static fn (array $row): int => (int) $row['people_id'], $eligible);
            $match = new CampaignAttachmentMatcherService()->match(
                $people->whereIn('id', $eligibleIds)->values(),
                $this->files($fileIds),
                $matchField,
            );
            $unmatchedFiles = $match['unmatched_files'];

            foreach ($eligible as $index => $row) {
                $peopleId = (int) $row['people_id'];
                if (isset($match['matched'][$peopleId])) {
                    $extras[$peopleId]['attachment_urls'] = [...$extras[$peopleId]['attachment_urls'], ...$match['matched'][$peopleId]];

                    continue;
                }
                $excluded[] = [...$row, 'compliance_status' => 'missing_attachment'];
                unset($eligible[$index]);
            }
            $eligible = array_values($eligible);
        }

        if ($eligible === []) {
            return $this->noop(
                ['excluded' => $excluded, 'unmatched_files' => $unmatchedFiles],
                'None of the given people are eligible to contact by email; nothing was sent. Tell the user which ones and why (see excluded[].compliance_status).',
            );
        }

        foreach ($eligible as $index => $row) {
            $eligible[$index] = [...$row, ...$extras[(int) $row['people_id']]];
        }

        $testTo = Str::trimToNull($test_to);
        if ($testTo !== null) {
            return $this->sendTest(
                $testTo,
                $eligible[0],
                $people,
                $message,
                $subject,
                $sharedUrls,
                $templateName,
                $important,
                $excluded,
                $unmatchedFiles,
            );
        }

        $campaign = new CreateBatchCampaignAction(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            channel: 'email',
            message: $message,
            subject: Str::trimToNull($subject),
            eligibleRecipients: $eligible,
            criteria: [
                'people_ids' => array_keys($extras),
                'channel' => 'email',
                'agents_id' => $this->contextAgent()?->getId(),
                'attachment_match_field' => $matchField !== '' ? $matchField : null,
            ],
            attachmentUrls: $sharedUrls,
            templateName: $templateName,
            important: $important,
            scheduledAt: $scheduledAt,
        )->execute();

        return $this->ok(
            [
                'campaign_id' => $campaign->getId(),
                'campaign_uuid' => $campaign->uuid,
                'campaign_status' => $campaign->status,
                'important' => $campaign->is_important,
                'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
                'total_recipients' => count($eligible),
                'excluded' => $excluded,
                'unmatched_files' => $unmatchedFiles,
            ],
            $this->buildNote($scheduledAt !== null, $excluded, $unmatchedFiles),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $recipients
     * @return array<int, array{attachment_urls: list<string>, variables: array<string, mixed>}>|string  keyed by person id; a string is the error
     */
    private function normalizeRecipients(array $recipients): array|string
    {
        $extras = [];
        foreach ($recipients as $recipient) {
            if (! is_array($recipient)) {
                continue;
            }
            $peopleId = (int) ($recipient['person_id'] ?? 0);
            if ($peopleId <= 0) {
                continue;
            }

            $variables = $this->decodeVariables($recipient['variables'] ?? null);
            if ($variables === null) {
                return "variables for person_id {$peopleId} must be a JSON object such as {\"Saludo\":\"Hola\"}; fix it and call again, nothing was sent.";
            }

            $urls = $recipient['attachment_urls'] ?? [];
            $extras[$peopleId] = [
                'attachment_urls' => $this->urls(is_string($urls) ? explode(',', $urls) : (array) $urls),
                'variables' => $variables,
            ];
        }

        return $extras;
    }

    /**
     * @return array<string, mixed>|null  null when the value is not a JSON object
     */
    private function decodeVariables(mixed $raw): ?array
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<int, mixed>  $urls
     * @return list<string>
     */
    private function urls(array $urls): array
    {
        $clean = [];
        foreach ($urls as $url) {
            if (is_string($url) && trim($url) !== '') {
                $clean[] = trim($url);
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Filesystem>
     */
    private function files(array $ids): Collection
    {
        return Filesystem::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $row  the first eligible recipient, extras merged in
     * @param  Collection<int, People>  $people
     * @param  list<string>  $sharedUrls
     * @param  array<int, array<string, mixed>>  $excluded
     * @param  list<string>  $unmatchedFiles
     * @return array<string, mixed>
     */
    private function sendTest(
        string $testTo,
        array $row,
        Collection $people,
        string $message,
        ?string $subject,
        array $sharedUrls,
        ?string $templateName,
        bool $important,
        array $excluded,
        array $unmatchedFiles,
    ): array {
        $member = $this->resolveCompanyUserByEmail($testTo);
        if ($member === null) {
            return $this->denied(
                "test_to must be the email of a user in this company; \"{$testTo}\" is not one. Nothing was sent.",
                guidance: 'Ask for a teammate\'s email, or omit test_to to send the campaign itself.',
            );
        }

        /** @var People $person */
        $person = $people->firstWhere('id', (int) $row['people_id']);

        try {
            new SendEmailToPeopleAction($person)->execute(
                to: $member->email,
                message: $message,
                subject: Str::trimToNull($subject),
                attachmentUrls: CampaignRecipient::mergeAttachmentUrls($sharedUrls, $row['attachment_urls']),
                templateName: $templateName,
                important: $important,
                fromAgent: $this->contextAgent(),
                signatureOwner: $this->user,
                variables: $row['variables'],
                trackOpens: false,
            );
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                'The test email could not be sent: ' . $e->getMessage(),
                guidance: 'Do not create the campaign until a test send succeeds.',
            );
        }

        return $this->ok(
            [
                'test_sent_to' => $member->email,
                'rendered_for_person_id' => $person->getId(),
                'rendered_for_name' => $person->getName(),
                'would_send_to' => count($excluded) > 0 ? 'the eligible people only' : 'everyone given',
                'excluded' => $excluded,
                'unmatched_files' => $unmatchedFiles,
            ],
            'A single test email went to that teammate and NO campaign was created. Ask them to check it, then '
            . 'call again without test_to to send the real campaign.',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $excluded
     * @param  list<string>  $unmatchedFiles
     */
    private function buildNote(bool $isScheduled, array $excluded, array $unmatchedFiles): string
    {
        $note = $isScheduled
            ? 'Campaign scheduled. It will send at the scheduled time to the queued recipients.'
            : 'Campaign queued and sending now to the eligible people.';

        if ($excluded !== []) {
            $note .= ' ' . count($excluded) . ' of the given people were excluded and will NOT receive this email; '
                . 'always tell the user which ones and why (see excluded[].compliance_status: missing_attachment means '
                . 'no uploaded file matched that person, no_contact_info means no email on file). Never state or imply '
                . 'that everyone requested got the email.';
        }

        if ($unmatchedFiles !== []) {
            $note .= ' ' . count($unmatchedFiles) . ' uploaded file(s) matched nobody (see unmatched_files); tell the user.';
        }

        return $note;
    }
}
