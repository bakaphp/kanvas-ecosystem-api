<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\GoogleADKService;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class ADKAgent
{
    protected ?Agent $agent = null;
    protected ?Apps $app = null;
    protected ?Companies $company = null;
    protected ?Model $entity = null;
    protected ?string $externalReferenceId = null;
    protected string $content = '';

    public function setConfiguration(
        Agent $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
    ): void {
        $this->agent = $agent;
        $this->entity = $entity;
        $this->app = $agent->app;
        $this->company = $agent->company;
    }

    public function chat(
        Channel $channel,
        Message $message,
        string $messageContent,
        ?callable $onChunk = null,
        ?Session $session = null
    ): self {
        $googleADKService = new GoogleADKService(
            $channel->app,
            $channel->company
        );

        $sessionId = $session ? $session->uuid : $channel->slug;

        $googleADKService->startSession(
            (string) $message->users_id,
            $sessionId
        );

        $this->content = $googleADKService->chat(
            (string) $message->users_id,
            $sessionId,
            $messageContent,
            $onChunk
        );

        return $this;
    }

    public function chatSimple(
        Apps $app,
        Companies $company,
        string $userId,
        string $sessionId,
        string $message
    ): self {
        $googleADKService = new GoogleADKService(
            $app,
            $company
        );

        $googleADKService->startSession(
            $userId,
            $sessionId
        );

        $this->content = $googleADKService->chat(
            $userId,
            $sessionId,
            $message
        );

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
