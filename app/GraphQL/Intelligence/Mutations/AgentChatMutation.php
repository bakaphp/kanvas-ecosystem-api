<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\ProcessAgentChatAction;
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Factories\NeuronAgentFactory;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\Actions\CreateUserSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as DataTransferObjectSession;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
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

        return new ProcessAgentChatAction(
            agent: $agent,
            session: $session,
            message: AttachmentPromptBuilder::withAttachments(
                (string) $input['message'],
                $mergedFiles,
            ),
            app: $app,
            company: $company,
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

        return $this->createLeadSession(
            $lead,
            $agent,
            $user,
            $app,
            $company,
            (string) $input['canal_id'],
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

        $session = $this->resolveSession(
            $input,
            $agent,
            $user,
            $app,
            $company
        );

        [$mergedImages, $mergedFiles, $attachments] = $this->mergeUploadsWithUrls(
            $input,
            $user,
            $app,
            $session
        );

        $processor = new ProcessAgentChatAction(
            agent: $agent,
            session: $session,
            message: AttachmentPromptBuilder::withAttachments(
                (string) $input['message'],
                $mergedFiles,
            ),
            app: $app,
            company: $company,
            user: $user,
            images: $mergedImages,
            attachments: $attachments,
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
     * @param array<string, mixed> $input
     */
    protected function resolveSession(
        array $input,
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

        if (! empty($input['lead_id'])) {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp(
                id: $input['lead_id'],
                app: $app,
                company: $company
            );

            return $this->createLeadSession(
                $lead,
                $agent,
                $user,
                $app,
                $company
            );
        }

        return new CreateUserSessionAction(
            agent: $agent,
            user: $user,
            app: $app,
            company: $company,
        )->execute();
    }

    protected function createLeadSession(
        Lead $lead,
        Agent $agent,
        Users $user,
        Apps $app,
        Companies $company,
        ?string $canalId = null,
    ): Session {
        $channelName = 'Manual Channel for Lead ' . $lead->getId();

        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $app,
                companies: $company,
                users: $user,
                name: $channelName,
                description: 'Channel for lead ' . $lead->getId(),
                entity_id: $lead->getId(),
                entity_namespace: Lead::class,
                slug: Str::simpleSlug($channelName),
            )
        )->execute();

        return new CreateSessionAction(
            DataTransferObjectSession::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
                'canal_id' => $canalId ?? (string) $agent->getId(),
                'user' => [
                    'name' => $lead->people->getName(),
                    'id' => $lead->people->getId(),
                    'email' => $lead->people->getEmails()->first()?->value,
                ],
                'agent' => $agent,
            ])
        )->execute();
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
