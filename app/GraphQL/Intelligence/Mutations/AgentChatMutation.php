<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
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

        [$mergedImages, $mergedFiles] = $this->mergeUploadsWithUrls($input, $user, $app, $session ?? $agent);

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
        )->execute();
    }

    public function createSession(mixed $root, array $req): string
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $req['input'] ?? [];
        $agent = Agent::getByIdFromCompanyApp(
            id: $input['agent_id'],
            app: $app,
            company: $company
        );

        $lead = Lead::getByIdFromCompanyApp(
            id: $input['lead_id'],
            app: $app,
            company: $company
        );

        $channelName = 'Manual Channel for Lead ' . $lead->getId();
        $slug = Str::simpleSlug($channelName);

        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $app,
                companies: $company,
                users: $user,
                name: $channelName,
                description: 'Channel for lead ' . $lead->getId(),
                entity_id: $lead->getId(),
                entity_namespace: Lead::class,
                slug: $slug,
            )
        )->execute();

        $chatSession = new CreateSessionAction(
            DataTransferObjectSession::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
                'canal_id' => $input['canal_id'],
                'user' => [
                    'name' => $lead->people->getName(),
                    'id' => $lead->people->getId(),
                    'email' => $lead->people->getEmails()->first()?->value,
                ],
                'agent' => $agent,
            ])
        )->execute();

        return $chatSession->uuid;
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
        $company = $user->getCurrentCompany();
        /** @var array<string, mixed> $input */
        $input = $req['input'] ?? [];

        $agent = Agent::getByIdWithGlobalFallback(
            id: (int) $input['agent_id'],
            app: $app,
            company: $company
        );

        if (! empty($input['session_id'])) {
            $session = Session::fromApp($app)
                ->fromCompany($company)
                ->where('uuid', (string) $input['session_id'])
                ->firstOrFail();
        } elseif (! empty($input['lead_id'])) {
            $lead = Lead::getByIdFromCompanyApp(
                id: $input['lead_id'],
                app: $app,
                company: $company
            );

            $channelName = 'Manual Channel for Lead ' . $lead->getId();
            $slug = Str::simpleSlug($channelName);

            $channel = new CreateChannelAction(
                new ChannelDto(
                    apps: $app,
                    companies: $company,
                    users: $user,
                    name: $channelName,
                    description: 'Channel for lead ' . $lead->getId(),
                    entity_id: $lead->getId(),
                    entity_namespace: Lead::class,
                    slug: $slug,
                )
            )->execute();

            $session = new CreateSessionAction(
                DataTransferObjectSession::from([
                    'app' => $app,
                    'company' => $company,
                    'channel' => $channel,
                    'entity_namespace' => Lead::class,
                    'entity_id' => $lead->getId(),
                    'canal_id' => $agent->getId(),
                    'user' => [
                        'name' => $lead->people->getName(),
                        'id' => $lead->people->getId(),
                        'email' => $lead->people->getEmails()->first()?->value,
                    ],
                    'agent' => $agent,
                ])
            )->execute();
        } else {
            $session = new CreateUserSessionAction(
                agent: $agent,
                user: $user,
                app: $app,
                company: $company,
            )->execute();
        }

        [$mergedImages, $mergedFiles] = $this->mergeUploadsWithUrls($input, $user, $app, $session);

        $response = new ProcessAgentChatAction(
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
        )->execute();

        return [
            'response' => $response,
            'session_id' => $session->uuid,
        ];
    }

    /**
     * Push uploaded files through `HasMutationUploadFiles::uploadFilesAndCollect` — which
     * uses the canonical FilesystemServices pipeline AND records the attachment on the
     * given entity (session preferred, agent as fallback) for chat-history purposes — then
     * classify each result via {@see Filesystem::mediaType} and merge the URLs with the
     * client-supplied `images` / `files` lists. Downstream code doesn't need to know
     * whether an attachment arrived as a pre-existing URL or as an `Upload`.
     *
     * @param array<string, mixed> $input
     * @return array{0: list<string>, 1: list<string>} `[$images, $files]`
     */
    private function mergeUploadsWithUrls(array $input, Users $user, Apps $app, ?Model $attachTo): array
    {
        /** @var list<string> $images */
        $images = $input['images'] ?? [];
        /** @var list<string> $files */
        $files = $input['files'] ?? [];

        $uploads = array_values(array_filter(
            $input['uploads'] ?? [],
            static fn (mixed $item): bool => $item instanceof UploadedFile,
        ));

        if ($uploads === [] || $attachTo === null) {
            return [$images, $files];
        }

        foreach ($this->uploadFilesAndCollect($attachTo, $app, $user, $uploads) as $filesystem) {
            if ($filesystem->mediaType()->isImage()) {
                $images[] = $filesystem->url;
            } else {
                $files[] = $filesystem->url;
            }
        }

        return [$images, $files];
    }
}
