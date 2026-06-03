<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Factories\NeuronAgentFactory;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\Actions\CreateUserSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as DataTransferObjectSession;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\DataTransferObject\SystemModuleEntityInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Messages\UserMessage;

class AgentChatMutation
{
    use HasMutationUploadFiles;

    public function chat(mixed $root, array $req): string
    {
        /** @var array<string, mixed> $input */
        $input = $req['input'] ?? [];
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        /** @var Agent $agent */
        $agent = Agent::getByIdWithGlobalFallback(
            id: (int) $input['agent_id'],
            app: $app,
            company: $company
        );

        $sessionId = (string) $input['session_id'];
        $session = Session::fromApp($app)->fromCompany($company)->where('uuid', $sessionId)->first();

        [$mergedImages, $mergedFiles, $attachments] = $this->mergeUploadsWithUrls(
            $input,
            $user,
            $app,
            $session ?? $agent
        );

        return new AgentChatKernel(
            agent: $agent,
            session: $session,
            message: AttachmentPromptBuilder::withAttachments(
                (string) $input['message'],
                $mergedFiles,
            ),
            user: $user,
            images: $mergedImages,
            attachments: $attachments,
        )->execute();
    }

    public function createSession(mixed $root, array $req): string
    {
        $app = app(Apps::class);
        $user = auth()->user();
        /** @var Companies $company */
        $company = $user->getCurrentCompany();
        $input = $req['input'] ?? [];
        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp(
            id: $input['agent_id'],
            app: $app,
            company: $company
        );

        /** @var Lead $lead */
        $lead = Lead::getByIdFromCompanyApp(
            id: $input['lead_id'],
            app: $app,
            company: $company
        );

        $people = $lead->people;
        if ($people === null) {
            throw new ValidationException(
                "Lead {$lead->getId()} has no people record; cannot create a People-keyed session."
            );
        }

        return $this->findOrCreatePeopleSession(
            people: $people,
            agent: $agent,
            user: $user,
            app: $app,
            company: $company,
            canalId: (string) $input['canal_id'],
        )->uuid;
    }

    public function neuronChat(mixed $root, array $req): string
    {
        // $app = app(Apps::class);
        // $user = auth()->user();
        // $input = $req['input'] ?? [];

        // $entity = SystemModulesRepository::getEntityFromInput(
        //     new SystemModuleEntityInput(
        //         name: (string) $input['name'],
        //         systemModuleUuid: (string) $input['system_modules_uuid'],
        //         entityId: (string) $input['entity_id'],
        //     ),
        //     $user,
        // );

        // $neuronAgent = NeuronAgentFactory::fromName(
        //     name: (string) $input['name'],
        //     app: $app,
        //     entity: $entity,
        //     user: $user,
        // );
        // $neuronAgent->setThreadId((string) $input['entity_id']);

        // $response = $neuronAgent->chat(new UserMessage((string) $input['message']))->getMessage();

        // return (string) $response->getContent();
        return '';
    }

    public function userChat(mixed $root, array $req): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        /** @var Companies $company */
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $req['input'] ?? [];

        $agent = Agent::getByIdWithGlobalFallback(
            id: (int) $input['agent_id'],
            app: $app,
            company: $company
        );

        $currentLead = ! empty($input['lead_id'])
            ? Lead::getByIdFromCompanyApp(id: (int) $input['lead_id'], app: $app, company: $company)
            : null;

        $session = $this->resolveSession(
            $input,
            $currentLead,
            $agent,
            $user,
            $app,
            $company,
        );

        // Post-promote sessions are People-keyed and the frontend won't know
        // the new lead_id — resolve it server-side so the agent's prompt has
        // lead context (otherwise it would re-fire create_lead every turn).
        if ($currentLead === null && $session->entity_namespace === People::class) {
            $people = $session->entity();
            if ($people instanceof People) {
                $currentLead = LeadsRepository::getPeopleActiveLead($people);
            }
        }

        [$mergedImages, $mergedFiles, $attachments] = $this->mergeUploadsWithUrls(
            $input,
            $user,
            $app,
            $session
        );

        $processor = new AgentChatKernel(
            agent: $agent,
            session: $session,
            message: AttachmentPromptBuilder::withAttachments(
                (string) $input['message'],
                $mergedFiles,
            ),
            user: $user,
            images: $mergedImages,
            attachments: $attachments,
            currentLead: $currentLead,
        );

        $response = $processor->execute();
        /** @var Message $reply userChat always supplies a Session, so persistence always runs and sets the reply (or throws). */
        $reply = $processor->persistedReply();

        return [
            'response' => $response,
            'session_id' => $session->uuid,
            'message' => $reply,
            'channel' => $reply->channels()->first(),
        ];
    }

    /**
     *  - session_id explicit → continue that session (back-compat)
     *  - lead_id → find or create the prospect's People-keyed session (one per
     *    People + Agent for the lifetime of the prospect)
     *  - neither → anonymous User-scoped session
     *
     * @param array<string, mixed> $input
     */
    protected function resolveSession(
        array $input,
        ?Lead $currentLead,
        Agent $agent,
        Users $user,
        Apps $app,
        Companies $company,
    ): Session {
        if (! empty($input['session_id'])) {
            $session = Session::fromApp($app)
                ->fromCompany($company)
                ->where('uuid', (string) $input['session_id'])
                ->first();

            if ($session !== null) {
                return $session;
            }
        }

        if ($currentLead !== null) {
            $people = $currentLead->people;

            if ($people !== null) {
                return $this->findOrCreatePeopleSession(
                    people: $people,
                    agent: $agent,
                    user: $user,
                    app: $app,
                    company: $company,
                );
            }
        }

        return new CreateUserSessionAction(
            agent: $agent,
            user: $user,
            app: $app,
            company: $company,
        )->execute();
    }

    /**
     * One session per (People, Agent, App, Company) — the durable thread that
     * accumulates every conversation with this prospect across every Lead.
     */
    protected function findOrCreatePeopleSession(
        People $people,
        Agent $agent,
        Users $user,
        Apps $app,
        Companies $company,
        ?string $canalId = null,
    ): Session {
        $session = Session::fromApp($app)
            ->fromCompany($company)
            ->where('agents_id', $agent->getId())
            ->where('entity_namespace', People::class)
            ->where('entity_id', $people->getId())
            ->first();

        if ($session !== null) {
            return $session;
        }

        $channel = new PeopleChannelService()->findOrCreateForPeople($people, $app, $company, $user);

        return new CreateSessionAction(
            DataTransferObjectSession::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => People::class,
                'entity_id' => $people->getId(),
                'canal_id' => $canalId ?? (string) $agent->getId(),
                'user' => [
                    'name' => $people->getName(),
                    'id' => $people->getId(),
                    'email' => $people->getEmails()->first()?->value,
                ],
                'agent' => $agent,
            ])
        )->execute();
    }

    /**
     * @deprecated Thin alias around findOrCreatePeopleSession kept for any
     *             external callers — delete once we confirm no one calls it.
     */
    protected function createLeadSession(
        Lead $lead,
        Agent $agent,
        Users $user,
        Apps $app,
        Companies $company,
        ?string $canalId = null,
    ): Session {
        $people = $lead->people;
        if ($people === null) {
            throw new ValidationException(
                "Lead {$lead->getId()} has no people record; cannot create a People-keyed session."
            );
        }

        return $this->findOrCreatePeopleSession(
            people: $people,
            agent: $agent,
            user: $user,
            app: $app,
            company: $company,
            canalId: $canalId,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: list<string>, 1: list<string>, 2: list<\Kanvas\Filesystem\Models\Filesystem>} `[$images, $files, $attachments]`
     */
    protected function mergeUploadsWithUrls(
        array $input,
        Users $user,
        Apps $app,
        ?Model $attachTo
    ): array {
        /** @var list<string> $images */
        $images = $input['images'] ?? [];
        /** @var list<string> $files */
        $files = $input['files'] ?? [];
        $attachments = [];

        $uploads = array_values(array_filter(
            $input['uploads'] ?? [],
            static fn (mixed $item): bool => $item instanceof UploadedFile,
        ));

        if ($uploads === [] || $attachTo === null) {
            return [$images, $files, $attachments];
        }

        foreach ($this->uploadFilesAndCollect($attachTo, $app, $user, $uploads) as $filesystem) {
            if ($filesystem->mediaType()->isImage()) {
                $images[] = $filesystem->url;
            } else {
                $files[] = $filesystem->url;
            }
            $attachments[] = $filesystem;
        }

        return [$images, $files, $attachments];
    }
}
