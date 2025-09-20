<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Webhooks;

use Baka\Support\Str;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\People as PeopleModel;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Spatie\LaravelData\DataCollection;

class ProcessTwilioWebhookJob extends ProcessWebhookJob
{
    protected bool $hijackSession = false;

    #[Override]
    public function execute(array $params = []): array
    {
        $request = $this->webhookRequest->payload;

        if ($this->receiver->company->get('allow_session_hijack', false)
            && $this->receiver->company->get('overwrite_phone_number') !== null
        ) {
            $overwriteConfig = $this->receiver->company->get('overwrite_phone_number');
            $originalRemoteJid = $request['From'];

            if (isset($overwriteConfig[$originalRemoteJid])) {
                $newPhone = $overwriteConfig[$originalRemoteJid];
                $this->hijackSession = true;
                $request['From'] = $newPhone;
            }
        }
        $isFromMe = $request['From'] === $request['To'];
        if (! $isFromMe) {
            $people = $this->processContactFromMessage($request);
            $lead = $this->createLeadFromPeople($people);
        }

        $messageSlug = $this->createMessageSlug($request['SmsMessageSid'], $request['From']);

        $channel = $this->getOrCreateChannel($request['From'], lead: $lead);

        $existingMessage = Message::where('uuid', $messageSlug)
            ->where('companies_id', $this->receiver->company->getId())
            ->where('apps_id', $this->receiver->app->getId())
            ->first();
        $lastMessage = $channel->getLastMessage();

        if ($existingMessage) {
            $message = $existingMessage;
        } else {
            // Get the appropriate message type
            $messageTypeModel = (new CreateMessageTypeAction(
                new MessageTypeInput(
                    $this->receiver->app->getId(),
                    0,
                    'twilio-sms',
                    'twilio-sms',
                )
            ))->execute();

            // Create the message using the action
            $messageInput = new MessageInput(
                app: $this->receiver->app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: $messageTypeModel,
                message: [
                    'content' => $request['Body'],
                    'raw_data' => $request,
                    'message_id' => $request['SmsMessageSid'],
                    'chat_jid' => $request['From'],
                    'from_me' => $request['From'] === $request['To'],
                ],
                is_public: 1,
                slug: $messageSlug,
                tags: [$request['From']]
            );

            $createMessageAction = new CreateMessageAction($messageInput);
            $message = $createMessageAction->execute();
        }

        if (isset($lead) && $lead instanceof Lead) {
            $message->addEntity($lead);
        }
        $lead->set(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, 'sms');
        $channel->fireWorkflow(
            WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
            true,
            [
                'message' => $message,
                'user' => $message->user,
                'app' => $message->app,
                'company' => $message->company,
                'text' => $request['Body'],
            ]
        );

        return [
            [
                'message_id' => $message->getId(),
                'uuid' => $message->uuid,
                'channel_id' => $channel->getId(),
                'chat_jid' => $request['From'],
                'text' => $request['Body'],
                'is_from_me' => $request['From'] === $request['To'],
                'type' => $messageTypeModel->name,
            ],
        ];
    }

    public function createLeadFromPeople(PeopleModel $people): Lead
    {
        $activeLead = LeadsRepository::getPeopleActiveLead($people);

        if ($activeLead) {
            return $activeLead;
        }

        $leadType = LeadType::fromApp($people->app)
        ->fromCompany($people->company)
        ->where('name', 'Warm')
        ->firstOrFail();

        $leadSource = new CreateLeadSourceAction(
            new LeadSource(
                $people->app,
                $people->company,
                $leadType->getId(),
                'sms',
                true,
                'sms'
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

        $leadData = new DataTransferObjectLead(
            app: $people->app,
            branch: $people->company->defaultBranch,
            user: $people->user,
            title: $people->name . ' Twilio Opp',
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
        );

        $lead = new CreateLeadAction($leadData)->execute();
        $lead->addTags([
            'SMS',
            'ai-agent',
        ]);

        return $lead;
    }

    public function processContactFromMessage(array $request): PeopleModel
    {
        $phoneNumber = $request['From'];

        $existingCustomer = People::getByCustomField(
            'twilio_jid',
            $phoneNumber,
            $this->receiver->company
        );

        // also find customer by phone number if not found by JID
        if (! $existingCustomer) {
            $existingCustomer = People::whereHas('contacts', function (Builder $query) use ($phoneNumber) {
                $query->whereRaw("REGEXP_REPLACE(value, '[^0-9]', '') = ?", [$phoneNumber])
                      ->whereIn('contacts_types_id', [ContactTypeEnum::CELLPHONE->value, ContactTypeEnum::PHONE->value]);
            })->fromCompany($this->receiver->company)
                ->fromApp($this->receiver->app)
            ->first();
        }

        if ($existingCustomer && $this->hijackSession) {
            return $existingCustomer;
        }

        $contactData = [
                    [
                        'value' => str_replace('+', '', $request['From']),
                        'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                        'weight' => 100,
                    ],
                ];

        $peopleDto = new PeopleDto(
            app: $this->receiver->app,
            branch: $this->receiver->company->defaultBranch,
            user: $this->receiver->user,
            firstname: $existingCustomer ? $existingCustomer->firstname : $phoneNumber,
            contacts: Contact::collect($contactData, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $existingCustomer ? $existingCustomer->lastname : '',
            custom_fields: [
                'twilio_jid' => $phoneNumber,
            ],
            tags: ['sms', 'twilio']
        );

        if ($existingCustomer) {
            $peopleDto->id = $existingCustomer->getId();
        }

        $createAction = new CreatePeopleAction($peopleDto);

        return $createAction->execute();
    }

    protected function createMessageSlug(string $messageId, string $from): string
    {
        return 'twilio-' . Str::slug($messageId . '-' . $from);
    }

    protected function getOrCreateChannel(string $from, ?string $name = null, ?Lead $lead = null): Channel
    {
        $slug = Str::slug('twilio-' . $from);

        return DB::transaction(function () use ($slug, $from, $name, $lead) {
            $channel = Channel::where('slug', $slug)
                        ->where('companies_id', $this->receiver->company->getId())
                        ->where('apps_id', $this->receiver->app->getId())
                        ->lockForUpdate()  // This applies a database-level lock
                        ->first();
            if (! $channel) {
                $channel = Channel::create([
                      'users_id' => $this->receiver->users_id,
                      'apps_id' => $this->receiver->app->getId(),
                      'companies_id' => $this->receiver->company->getId(),
                      'slug' => $slug,
                      'name' => $name ?? $from,
                      'description' => 'Channel Twilio for ' . $from,
                ]);
            }
            if ($lead) {
                $channel->entity_namespace = get_class($lead->people);
                $channel->entity_id = $lead->people->getId();
            }

            $channel->save();

            $channel->addTags([
                'twilio',
                'ai-agent',
            ]);
            $channel->set(ConfigurationEnum::AGENT_CHANNEL_TYPE->value, 'SMS');

            return $channel;
        }, 5);
    }
}
