<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\DataTransferObject\InboundRoute;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\Actions\ProcessInboundConsentAction;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\ConsentOutcome;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Customers\Services\ConsentKeywordService;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\Actions\SendOptOutConfirmationAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadDto;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Concerns\ChainsInboundBursts;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use LogicException;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * Everything after a provider has parsed its payload, the same for every medium: which connection
 * the message arrived on, the person and lead behind it, consent, the conversation it is filed in,
 * and the announcement that lets a responder answer it.
 *
 * The message is always filed. Only the automatic answer depends on the connection being on, and on
 * the identity not belonging to another workspace.
 */
final class ReceiveMessageAction
{
    use ChainsInboundBursts;

    public function __construct(
        private readonly ReceiverWebhook $receiver,
        private readonly InboundMessage $inbound,
        private readonly InboundSource $source
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $route = new RouteInboundMessageAction(
            $this->receiver->app,
            $this->receiver->company,
            $this->inbound
        )->execute();

        $consentSignal = $this->inbound->consentSignal ?? ConsentKeywordService::detect($this->inbound->text);
        $consentOutcome = null;
        $people = null;
        $lead = null;

        if (! $this->inbound->fromUs) {
            $people = $this->person();

            $lead = $this->lead($people);
            $lead->set(LeadConfigurationEnum::IS_ENGAGEMENT->value, true);

            // After the lead exists: a stop request is person-wide and every outbound guard reads
            // the lead, so the lead has to be there for the flag to land anywhere useful.
            $consentOutcome = new ProcessInboundConsentAction(
                people: $people,
                sourceChannel: $this->inbound->channel->value,
                body: $this->inbound->text,
                lead: $lead,
                contactValue: $this->inbound->from,
                detectedSignal: $consentSignal,
            )->execute();
        }

        $channel = $this->conversation($people);
        $message = $this->file($route);

        // The person first: the conversation is theirs, and an unqualified entity() reads the first
        // link. The lead is linked too, so its CRM views keep the turn.
        if ($people !== null) {
            $message->addEntity($people);
        }

        if ($lead !== null) {
            $message->addEntity($lead);
            $message->addTag('engagement');
        }

        $channel->addMessage($message);

        // Tag on any recognised keyword, but suppress the agent only for a real stop. A bare "YES"
        // matches the START set and would cancel the turn for a prospect answering "yes, book me in".
        if ($consentSignal !== null) {
            $message->addTag($this->inbound->provider . '-consent');
            $message->addTag($consentSignal->value);
        }

        $providerAnswersHelp = $this->source->answersHelpItself() && $consentOutcome?->signal === ConsentSignalEnum::HELP;

        if ($consentOutcome?->shouldHaltAgentTurn() === true || $providerAnswersHelp) {
            if ($lead !== null && $consentOutcome->applied) {
                new SendOptOutConfirmationAction($lead, $this->inbound->channel->value)->execute();
            }

            return [$this->summary($message, $channel, $consentOutcome, suppressed: true)];
        }

        $this->attachFiles($message, $lead);

        $lead?->set(LeadConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, $this->inbound->channel->value);

        if (! $this->inbound->fromUs) {
            new NotifyLeadStakeholdersService($lead)->onCustomerEngagement($message);
        }

        if ($route->allowsAutomaticReply()) {
            $this->announce($channel, $message);
        } else {
            $message->addTag('communication-' . ($route->reason ?? $route->connection?->unavailableReason() ?? 'unrouted'));
        }

        return [$this->summary($message, $channel, $consentOutcome, suppressed: false)];
    }

    /**
     * The person behind the address. Among several on file with it, the one with an open lead: that
     * is the conversation in progress.
     */
    private function person(): People
    {
        if (! $this->inbound->channel->isAddressedByPhone()) {
            throw new LogicException('Inbound ' . $this->inbound->channel->value . ' is not received through this pipeline yet.');
        }

        $address = Str::normalizePhoneNumber($this->inbound->from);

        $candidates = PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: [$address, str_replace('+', '', $this->inbound->from)]
        )->get();

        $existing = $candidates->first(fn (People $person): bool => LeadsRepository::getPeopleActiveLead($person) !== null)
            ?? $candidates->first();

        if ($existing !== null && $this->inbound->reuseKnownPerson) {
            return $existing;
        }

        $person = new PeopleDto(
            app: $this->receiver->app,
            branch: $this->receiver->company->defaultBranch,
            user: $this->receiver->user,
            firstname: $existing !== null ? $existing->firstname : $address,
            contacts: Contact::collect([[
                'value' => $address,
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'weight' => 100,
            ]], DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $existing !== null ? $existing->lastname : '',
            custom_fields: $this->inbound->personCustomFields,
            tags: $this->inbound->personTags,
            // We only know the address the message came from — anything else the person has (email,
            // other phones) must survive the update.
            mergeContacts: true
        );

        return $existing !== null
            ? new UpdatePeopleAction($existing, $person)->execute()
            : new CreatePeopleAction($person)->execute();
    }

    private function lead(People $people): Lead
    {
        $activeLead = LeadsRepository::getPeopleActiveLead($people);

        if ($activeLead !== null) {
            return $activeLead;
        }

        if ((bool) $this->receiver->company->get('use_last_lead_on_responder')) {
            $lastLead = LeadsRepository::getPeopleLastLead($people);

            if ($lastLead !== null) {
                return $lastLead;
            }
        }

        $medium = $this->inbound->channel->value;
        $leadType = LeadType::fromApp($people->app)
            ->fromCompany($people->company)
            ->where('name', 'Warm')
            ->firstOrFail();

        $leadSource = new CreateLeadSourceAction(
            new LeadSource(
                $people->app,
                $people->company,
                $leadType->getId(),
                $medium,
                true,
                $medium
            )
        )->execute();

        $leadReceiver = new CreateLeadReceiverAction(
            new LeadReceiver(
                app: $people->app,
                branch: $people->company->defaultBranch,
                user: $people->user,
                agent: $people->user,
                name: 'Agent',
                source: 'AI Agent',
                isDefault: false,
                lead_sources_id: $leadSource->getId(),
                lead_types_id: $leadType->getId()
            )
        )->execute();

        $lead = new CreateLeadAction(new LeadDto(
            app: $people->app,
            branch: $people->company->defaultBranch,
            user: $people->user,
            title: $people->name . ' ' . ucfirst($this->inbound->provider) . ' Opp',
            pipeline_stage_id: 0,
            people: new PeopleDto(
                $people->app,
                $people->company->defaultBranch,
                $people->user,
                $people->firstname,
                Contact::collect($people->contacts()->get()->toArray(), DataCollection::class),
                Address::collect([], DataCollection::class),
                $people->lastname,
                $people->id
            ),
            leads_owner_id: 0,
            status_id: 0,
            type_id: $leadType->getId(),
            source_id: $leadSource->getId(),
            receiver_id: $leadReceiver->getId()
        ))->execute();

        $lead->addTags([strtoupper($medium), 'ai-agent']);

        return $lead;
    }

    /**
     * One conversation per counterpart and provider. Locked, because parts of a burst arrive as
     * parallel jobs and would otherwise open two.
     */
    private function conversation(?People $people): Channel
    {
        $app = $this->receiver->app;
        $company = $this->receiver->company;
        $slug = $this->inbound->conversationSlug;

        return DB::transaction(function () use ($app, $company, $slug, $people): Channel {
            $channel = Channel::query()
                ->where('slug', $slug)
                ->where('companies_id', $company->getId())
                ->where('apps_id', $app->getId())
                ->orderByDesc('last_message_id')
                ->lockForUpdate()
                ->first();

            $channel ??= Channel::firstOrCreate(
                [
                    'users_id' => $this->receiver->users_id,
                    'apps_id' => $app->getId(),
                    'companies_id' => $company->getId(),
                    'slug' => $slug,
                ],
                [
                    'name' => $this->inbound->from,
                    'description' => 'Channel ' . ucfirst($this->inbound->provider) . ' for ' . $this->inbound->from,
                ]
            );

            if ($people !== null) {
                $channel->entity_namespace = $people::class;
                $channel->entity_id = $people->getId();
            }

            $channel->save();

            $channel->addTags([$this->inbound->provider, 'ai-agent']);
            $channel->addCategory('ai-agent', $app, $this->receiver->user, $company);
            $channel->set(IntelligenceConfigurationEnum::AGENT_CHANNEL_TYPE->value, strtoupper($this->inbound->channel->value));

            return $channel;
        }, 5);
    }

    /**
     * A redelivery of the same vendor message finds the copy already filed.
     */
    private function file(InboundRoute $route): Message
    {
        $existing = $this->inbound->messageSlug !== null
            ? Message::query()
                ->where('slug', $this->inbound->messageSlug)
                ->where('companies_id', $this->receiver->company->getId())
                ->where('apps_id', $this->receiver->app->getId())
                ->first()
            : null;

        if ($existing !== null) {
            return $existing;
        }

        $type = new CreateMessageTypeAction(
            new MessageTypeInput(
                $this->receiver->app->getId(),
                0,
                $this->inbound->messageVerb,
                $this->inbound->messageVerb,
            )
        )->execute();

        $payload = AiChatMessagePayload::from([
            'content' => $this->inbound->text,
            'from_me' => $this->inbound->fromUs,
            'from_ia' => false,
            'raw_data' => $this->inbound->raw,
            'message_id' => $this->inbound->providerMessageId,
            'chat_jid' => $this->inbound->from,
        ])->toArray();

        // What a reply reads to leave through the same connection, and what a responder queued
        // before an Off checks to stand down.
        $communication = $route->toMessagePayload(thread: $this->inbound->from);

        if ($communication !== null) {
            $payload['communication'] = $communication;
        }

        return new CreateMessageAction(new MessageInput(
            app: $this->receiver->app,
            company: $this->receiver->company,
            user: $this->receiver->user,
            type: $type,
            message: $payload,
            is_public: 1,
            slug: $this->inbound->messageSlug,
            tags: [$this->inbound->from]
        ))->execute();
    }

    private function attachFiles(Message $message, ?Lead $lead): void
    {
        foreach ($this->inbound->attachments as $attachment) {
            try {
                $file = $this->source->downloadAttachment($message, $attachment);

                $message->addFile($file, $file->name);
                $lead?->addFile($file, $file->name);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Inbound collapses into a burst and is announced once the person goes quiet, so three lines in
     * a row get one answer. Our own messages are announced at once: there is no flurry to collapse,
     * and rules listening for them expect no delay.
     */
    private function announce(Channel $channel, Message $message): void
    {
        $policy = $this->inbound->fromUs ? null : $this->source->burstPolicy($this->inbound, $this->receiver->company);

        if ($policy === null) {
            $channel->fireWorkflow(
                WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
                true,
                [
                    'message' => $message,
                    'user' => $message->user,
                    'app' => $message->app,
                    'company' => $message->company,
                    'text' => $message->message['content'] ?? '',
                    'communication_channel' => $this->inbound->channel->value,
                ]
            );

            return;
        }

        $this->fileIntoBurst(
            $this->receiver->app,
            $channel,
            $message,
            $policy,
            $this->source->burstHandler(),
            ['communication_channel' => $this->inbound->channel->value]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(
        Message $message,
        Channel $channel,
        ?ConsentOutcome $consentOutcome,
        bool $suppressed
    ): array {
        return array_merge([
            'message_id' => $message->getId(),
            'uuid' => $message->uuid,
            'channel_id' => $channel->getId(),
            'chat_jid' => $this->inbound->from,
            'text' => $this->inbound->text ?? '',
            'is_from_me' => $this->inbound->fromUs,
            'type' => $message->messageType->name,
            // From the outcome, not the keyword matcher: a phrase-tier stop is recognised inside
            // ProcessInboundConsentAction and leaves the matcher's result null. Uppercase is the
            // shape this payload has always had.
            'consent_type' => $consentOutcome?->signal !== null
                ? strtoupper($consentOutcome->signal->value)
                : null,
            'automated_response_suppressed' => $suppressed,
        ], $consentOutcome?->toArray() ?? []);
    }
}
