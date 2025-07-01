<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\GoogleADKService;
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

    public function chat(Channel $channel, Message $message, string $messageContent, array $params = []): self
    {
        $googleADKService = new GoogleADKService(
            $channel->app,
            $channel->company
        );
        $googleADKService->startSession(
            (string) $message->users_id,
            $channel->slug
        );

        $this->content = $googleADKService->chat(
            (string) $message->users_id,
            $channel->slug,
            $messageContent,
        );

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
