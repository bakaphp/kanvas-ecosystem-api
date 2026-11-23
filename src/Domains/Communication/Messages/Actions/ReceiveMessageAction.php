<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\DataTransferObject\InboundRoute;
use Kanvas\Communication\Messages\DataTransferObject\InboundSenderPolicy;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Actions\Communication\ProcessInboundConsentAction;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
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
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Actions\Communication\OpenLeadFromConversationAction;
use Kanvas\Guild\Leads\Actions\Communication\SendOptOutConfirmationAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Concerns\ChainsInboundBursts;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use LogicException;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * The workspace comes from the route, before anything is written: a number claimed by a workspace
 * the receiver does not serve, or unclaimed on an app-wide receiver, files nothing. Once filed, only
 * the automatic answer depends on the connection being on.
 */
final class ReceiveMessageAction
{
    use ChainsInboundBursts;
    use KanvasJobsTrait;

    private const int FILING_LOCK_SECONDS = 30;
    private const int FILING_LOCK_WAIT_SECONDS = 15;

    private Companies $company;

    private Users $user;

    private InboundSenderPolicy $policy;

    private ?Users $teammate = null;

    private ?People $people = null;

    private ?Lead $lead = null;

    private ?ConsentOutcome $consent = null;

    /** @var array<int, Lead|null> */
    private array $activeLeads = [];

    public function __construct(
        private readonly ReceiverWebhook $receiver,
        private readonly InboundMessage $inbound,
        private readonly InboundSource $source
    ) {
    }

    /**
     * @return array<string, mixed> the filed message, or why nothing was filed
     */
    public function execute(): array
    {
        $sharedReceiver = new CommunicationReceivers()->sharesAppReceiver($this->inbound->provider);
        $route = new RouteInboundMessageAction(
            $this->receiver->app,
            $sharedReceiver ? null : $this->receiver->company,
            $this->inbound
        )->execute();

        if ($route->company === null) {
            return $this->unfiled($route->reason);
        }

        $this->company = $route->company;
        $this->user = $this->company->user;
        $this->actForCompany();

        $filing = $this->holdFilingLock();

        try {
            // A vendor that resends a message it already delivered must not move the person, the
            // consent or the conversation a second time, and must never start a second answer.
            $redelivered = $this->filed();
            $filedIn = $redelivered?->channels()->first();

            if ($redelivered !== null && $filedIn !== null) {
                return ['redelivery' => true] + $this->summary($redelivered, $filedIn, suppressed: true);
            }

            if (! $this->resolveSender()) {
                return $this->unfiled('unknown_sender');
            }

            $channel = $this->conversation($this->people ?? $this->teammate);
            $message = $this->fileIn($channel, $route);
        } finally {
            $filing?->release();
        }

        $halted = ! $this->afterFiling($route, $channel, $message);

        return $this->summary($message, $channel, suppressed: $halted);
    }

    public static function filingLockKey(int $appId, int $companyId, string $messageSlug): string
    {
        return 'communication-inbound:' . $appId . ':' . $companyId . ':' . $messageSlug;
    }

    /**
     * Held from the redelivery check until the message is in its conversation: a vendor can hand the
     * same message over twice at once, and the check and the filing are several writes apart. The
     * second delivery waits and then reads as a redelivery. A wait that runs out files anyway: a
     * lost message is worse than one filed twice.
     */
    private function holdFilingLock(): ?Lock
    {
        if ($this->inbound->messageSlug === null) {
            return null;
        }

        $lock = Cache::lock(
            self::filingLockKey(
                (int) $this->receiver->apps_id,
                $this->company->getId(),
                $this->inbound->messageSlug
            ),
            self::FILING_LOCK_SECONDS
        );

        try {
            $lock->block(self::FILING_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return null;
        }

        return $lock;
    }

    /**
     * The webhook job runs as the receiver's workspace, and an app-wide receiver belongs to whoever
     * set it up first. Everything filed from here on, and every observer it fires, has to see the
     * workspace that owns the number instead.
     */
    private function actForCompany(): void
    {
        if ($this->company->getId() === (int) $this->receiver->companies_id) {
            return;
        }

        Auth::loginUsingId($this->user->getId());
        $this->overwriteAppServiceLocation($this->company->defaultBranch);
    }

    /**
     * Who wrote in: a colleague, or a person, the lead their conversation is about and the consent
     * this message settles. Our own side of a conversation is nobody to resolve.
     *
     * @return bool false for a stranger on an account that only takes mail from people it knows
     */
    private function resolveSender(): bool
    {
        $this->policy = $this->inbound->senderPolicy ?? InboundSenderPolicy::customers();

        if ($this->inbound->fromUs) {
            return true;
        }

        $this->teammate = $this->policy->recognizesTeammates ? $this->teammate() : null;

        if ($this->teammate !== null) {
            return true;
        }

        $this->people = $this->person();

        if ($this->people === null) {
            return false;
        }

        $this->lead = $this->policy->opensLead ? $this->lead($this->people) : $this->activeLead($this->people);

        if ($this->lead !== null) {
            $this->remember($this->lead, LeadConfigurationEnum::IS_ENGAGEMENT->value, true);
        }

        // After the lead exists: a stop request is person-wide and every outbound guard reads
        // the lead, so the lead has to be there for the flag to land anywhere useful.
        $this->consent = new ProcessInboundConsentAction(
            people: $this->people,
            sourceChannel: $this->inbound->channel->value,
            body: $this->inbound->text,
            lead: $this->lead,
            contactValue: $this->inbound->from,
            // Only the provider's own classification: our keyword match is the consent
            // action's to make, so it can still be second-guessed there.
            detectedSignal: $this->inbound->consentSignal,
        )->execute();

        return true;
    }

    /**
     * A user of this company writing in. They are a colleague of the agent, not a prospect: no
     * person, no lead, and no consent to settle.
     */
    private function teammate(): ?Users
    {
        if ($this->inbound->channel !== ChannelTypeEnum::EMAIL) {
            return null;
        }

        try {
            $user = UsersRepository::getUserOfAppByEmail(
                strtolower(trim($this->inbound->from)),
                $this->receiver->app
            );
            UsersRepository::belongsToCompany($user, $this->company);

            return $user;
        } catch (EloquentModelNotFoundException | ModelNotFoundException) {
            return null;
        }
    }

    /**
     * The person behind the address, as this message leaves them: found and left alone, found and
     * given what the message knows, or created. Null when nobody is on file and the account only
     * takes mail from people it knows.
     */
    private function person(): ?People
    {
        $byPhone = $this->inbound->channel->isAddressedByPhone();

        if (! $byPhone && $this->inbound->channel !== ChannelTypeEnum::EMAIL) {
            throw new LogicException(
                'Inbound ' . $this->inbound->channel->value . ' is not received through this pipeline yet.'
            );
        }

        $address = $byPhone
            ? Str::normalizePhoneNumber($this->inbound->from)
            : strtolower(trim($this->inbound->from));
        $existing = $this->personOnFile($address, $byPhone);

        // Mail to an agent's own address is not a lead form: who the person is stays as it is on file.
        if ($existing !== null && $this->policy->keepsPersonAsFound) {
            return $existing;
        }

        if ($existing === null && $this->policy->knownSendersOnly) {
            return null;
        }

        $name = $this->personName($existing, $address);

        $person = new PeopleDto(
            app: $this->receiver->app,
            branch: $this->company->defaultBranch,
            user: $this->user,
            firstname: $name['firstname'],
            contacts: Contact::collect([[
                'value' => $address,
                'contacts_types_id' => $byPhone ? ContactTypeEnum::CELLPHONE->value : ContactTypeEnum::EMAIL->value,
                'weight' => 100,
            ]], DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $name['lastname'],
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

    /**
     * The name a message carries is one the sender typed themselves, so it only fills a gap: a new
     * person, or one still filed under their number. A name someone put on the record stays.
     *
     * @return array{firstname: string, lastname: string|null}
     */
    private function personName(?People $existing, string $address): array
    {
        $sent = Str::trimToNull($this->inbound->fromName);

        if ($sent !== null && ($existing === null || $this->isFiledUnderTheirNumber($existing))) {
            return Str::parseFullName($sent);
        }

        return [
            'firstname' => $existing !== null ? $existing->firstname : $address,
            'lastname' => $existing !== null ? $existing->lastname : '',
        ];
    }

    private function isFiledUnderTheirNumber(People $person): bool
    {
        return Str::trimToNull($person->lastname) === null
            && Str::digitsOnly($person->firstname) !== ''
            && preg_match('/\p{L}/u', (string) $person->firstname) !== 1;
    }

    /**
     * Among several people on file with the address, the one with an open lead: that is the
     * conversation in progress.
     */
    private function personOnFile(string $address, bool $byPhone): ?People
    {
        $candidates = $byPhone
            ? PeoplesRepository::getByPhoneNumber(
                app: $this->receiver->app,
                company: $this->company,
                phoneNumbers: [$address, str_replace('+', '', $this->inbound->from)]
            )->get()
            : PeoplesRepository::getByAnyContact(
                app: $this->receiver->app,
                company: $this->company,
                emails: [$address]
            )->get();

        return $candidates->first(fn (People $person): bool => $this->activeLead($person) !== null)
            ?? $candidates->first();
    }

    private function lead(People $people): Lead
    {
        $activeLead = $this->activeLead($people);

        if ($activeLead !== null) {
            return $activeLead;
        }

        if ((bool) $this->company->get('use_last_lead_on_responder')) {
            $lastLead = LeadsRepository::getPeopleLastLead($people);

            if ($lastLead !== null) {
                return $lastLead;
            }
        }

        $medium = $this->inbound->channel->value;
        $lead = new OpenLeadFromConversationAction(
            people: $people,
            source: $medium,
            title: $people->name . ' ' . ucfirst($this->inbound->provider) . ' Opp',
            filing: $this->receiver->configuration ?? []
        )->execute();

        $lead->addTags([strtoupper($medium), 'ai-agent']);

        return $lead;
    }

    /**
     * Asked once per person: finding them, and then their lead, would otherwise each look it up.
     */
    private function activeLead(People $people): ?Lead
    {
        $id = (int) $people->getId();

        if (! array_key_exists($id, $this->activeLeads)) {
            $this->activeLeads[$id] = LeadsRepository::getPeopleActiveLead($people);
        }

        return $this->activeLeads[$id];
    }

    /**
     * One conversation per counterpart and provider, pointed at whoever wrote this message.
     */
    private function conversation(People|Users|null $counterpart): Channel
    {
        $channel = new FindOrCreateConversationChannelAction(
            $this->receiver->app,
            $this->company,
            $this->inbound->conversationSlug
        )->execute([
            'users_id' => $this->user->getId(),
            'name' => $this->inbound->from,
            'description' => 'Channel ' . ucfirst($this->inbound->provider) . ' for ' . $this->inbound->from,
        ]);

        if ($counterpart !== null) {
            $channel->entity_namespace = $counterpart::class;
            $channel->entity_id = $counterpart->getId();
        }

        $channel->save();

        $channel->addTags([$this->inbound->provider, 'ai-agent']);
        $channel->addCategory(
            'ai-agent',
            $this->receiver->app,
            $this->user,
            $this->company
        );
        $this->remember(
            $channel,
            IntelligenceConfigurationEnum::AGENT_CHANNEL_TYPE->value,
            strtoupper($this->inbound->channel->value)
        );

        return $channel;
    }

    private function filed(): ?Message
    {
        return $this->inbound->messageSlug === null ? null : Message::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($this->company->getId())
            ->where('slug', $this->inbound->messageSlug)
            ->first();
    }

    /**
     * The message, on whoever wrote it and in their conversation.
     */
    private function fileIn(Channel $channel, InboundRoute $route): Message
    {
        $message = $this->message($route);

        if ($this->teammate !== null) {
            $message->addEntity($this->teammate);
        }

        // The person first: the conversation is theirs, and an unqualified entity() reads the first
        // link. The lead is linked too, so its CRM views keep the turn.
        if ($this->people !== null) {
            $message->addEntity($this->people);
        }

        if ($this->lead !== null) {
            $message->addEntity($this->lead);
            $message->addTag('engagement');
        }

        $channel->addMessage($message);

        $this->recordInHistories($message);

        // Tagged on any recognised keyword, whatever the consent action then makes of it: a bare
        // "YES" matches the START set and is still a prospect answering "yes, book me in".
        $keyword = $this->inbound->consentSignal ?? ConsentKeywordService::detect($this->inbound->text);

        if ($keyword !== null) {
            $message->addTag($this->inbound->provider . '-consent');
            $message->addTag($keyword->value);
        }

        return $message;
    }

    /**
     * A customer's message also lands on the lead's history and the person's whole conversation,
     * the same two channels the agent's answer is written to. Never a reason to lose the message:
     * it is already filed in its conversation.
     */
    private function recordInHistories(Message $message): void
    {
        try {
            if ($this->lead !== null) {
                new LeadChannelService()->recordOnLeadAndPerson($message, $this->lead, $this->user);
            } elseif ($this->people !== null) {
                new PeopleChannelService()->recordOnPerson($message, $this->people, $this->user);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * A copy filed by a delivery that stopped before it reached the conversation is picked up
     * again, so the same vendor message is never filed twice.
     */
    private function message(InboundRoute $route): Message
    {
        $existing = $this->filed();

        if ($existing !== null) {
            return $existing;
        }

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
        $communication = $route->toMessagePayload(thread: $this->inbound->replyTo ?? $this->inbound->from);

        if ($communication !== null) {
            $payload['communication'] = $communication;
        }

        return new CreateMessageAction(new MessageInput(
            app: $this->receiver->app,
            company: $this->company,
            user: $this->user,
            type: MessageTypeService::getOrCreate($this->receiver->app, $this->inbound->messageVerb),
            message: $payload,
            is_public: 1,
            slug: $this->inbound->messageSlug,
            tags: [$this->inbound->from]
        ))->execute();
    }

    /**
     * What a filed message sets off: its media, the lead's stakeholders, and the turn it opens.
     *
     * @return bool false when a stop request, or a HELP the provider answers itself, ends it here:
     *              nothing is fetched, nobody is notified and nothing answers
     */
    private function afterFiling(InboundRoute $route, Channel $channel, Message $message): bool
    {
        $providerAnswersHelp = $this->source->answersHelpItself()
            && $this->consent?->signal === ConsentSignalEnum::HELP;

        if ($this->consent?->shouldHaltAgentTurn() === true || $providerAnswersHelp) {
            if ($this->lead !== null && $this->consent->applied) {
                new SendOptOutConfirmationAction($this->lead, $this->inbound->channel->value)->execute();
            }

            return false;
        }

        // Inbound collapses into a burst and is announced once the person goes quiet, so three lines
        // in a row get one answer. Our own messages are announced at once: there is no flurry to
        // collapse, and rules listening for them expect no delay.
        $answers = $route->allowsAutomaticReply();
        $burst = $answers && ! $this->inbound->fromUs
            ? $this->source->burstPolicy($this->inbound, $this->company)
            : null;

        // Chained before the media is fetched: a download takes seconds, and a message left
        // unparented that long is adopted as head by the next part of the burst.
        $head = $burst !== null ? $this->attachToBurst($channel, $message, $burst) : null;

        $this->attachFiles($message);

        if ($this->lead !== null) {
            $this->remember(
                $this->lead,
                LeadConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value,
                $this->inbound->channel->value
            );
            new NotifyLeadStakeholdersService($this->lead)->onCustomerEngagement($message);
        }

        if (! $answers) {
            $message->addTag(
                'communication-' . ($route->reason ?? $route->connection?->unavailableReason() ?? 'unrouted')
            );

            return true;
        }

        $medium = ['communication_channel' => $this->inbound->channel->value];

        if ($burst === null) {
            new AnnounceInboundTurnAction(
                $channel,
                $message,
                (string) ($message->message['content'] ?? ''),
                $medium
            )->execute();

            return true;
        }

        $this->armBurstClose(
            $this->receiver->app,
            $channel,
            $head ?? $message,
            $burst,
            FlushInboundBurstAction::class,
            $medium
        );

        return true;
    }

    private function attachFiles(Message $message): void
    {
        foreach ($this->inbound->attachments as $attachment) {
            try {
                $file = $this->source->downloadAttachment($message, $attachment);

                $message->addFile($file, $file->name);
                $this->lead?->addFile($file, $file->name);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * set() is a Redis write plus custom-field upserts, and these run on every inbound message for
     * the life of the conversation: only a value that changed is written.
     */
    private function remember(Lead|Channel $entity, string $key, string|bool $value): void
    {
        $current = $entity->get($key);
        $unchanged = is_bool($value) ? (bool) $current === $value : $current === $value;

        if (! $unchanged) {
            $entity->set($key, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function unfiled(?string $reason): array
    {
        return [
            'message_id' => null,
            'provider_message_id' => $this->inbound->providerMessageId,
            'inbound_identity' => $this->inbound->inboundIdentity,
            'filed' => false,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Message $message, Channel $channel, bool $suppressed): array
    {
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
            'consent_type' => $this->consent?->signal !== null
                ? strtoupper($this->consent->signal->value)
                : null,
            'automated_response_suppressed' => $suppressed,
        ], $this->consent?->toArray() ?? []);
    }
}
