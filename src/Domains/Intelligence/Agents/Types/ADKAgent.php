<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
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
        $userId = (string) $message->users_id;
        $dateAdkUserId = $this->company->get(ConfigurationEnum::DATE_ADK_AGENT_RESPONSES->value) ?? null;
        $dateParse = $dateAdkUserId ? Carbon::parse($dateAdkUserId) : null;
        $now = Carbon::now();

        if ($channel->entity_namespace == Lead::class && $dateParse && $now->greaterThan($dateParse)) {
            $userId = $channel->entity_id;
        }
        $googleADKService->startSession(
            $userId,
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

        $useStreaming = $this->agent?->config['use_streaming'] ?? true;

        if ($useStreaming) {
            $this->content = $googleADKService->chat(
                $userId,
                $sessionId,
                $message
            );
        } else {
            $response = $googleADKService->chatSimple(
                $userId,
                $sessionId,
                $message
            );
            $this->content = $this->extractResponseText($response);
        }

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    protected function extractResponseText(array $response): string
    {
        // Single event with content.parts
        if (isset($response['content']['parts'])) {
            return $this->extractPartsText($response['content']['parts']);
        }

        // Array of events — iterate in reverse to find the last model response with text
        if (array_is_list($response)) {
            $text = '';
            foreach (array_reverse($response) as $event) {
                if (isset($event['content']['parts'])) {
                    $extracted = $this->extractPartsText($event['content']['parts']);
                    if ($extracted !== '') {
                        $text = $extracted;

                        break;
                    }
                }
            }

            if ($text !== '') {
                return $text;
            }
        }

        return json_encode($response);
    }

    protected function extractPartsText(array $parts): string
    {
        $text = '';
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    public function sendDataToAgent(
        string $sessionId,
        array $data,
        ?string $userId = null
    ): void {
        $googleADKService = new GoogleADKService(
            $this->app,
            $this->company
        );
        // $googleADKService->sendData(
        //     $userId ?? (string) $this->agent->user_id,
        //     $sessionId,
        //     $data
        // );
    }
}
