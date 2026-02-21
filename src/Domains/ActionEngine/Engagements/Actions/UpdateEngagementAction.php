<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Engagements\DataTransferObject\UpdateEngagement as UpdateEngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Social\Messages\Models\Message;

class UpdateEngagementAction
{
    public function __construct(
        protected readonly Engagement $engagement,
        protected readonly UpdateEngagementData $data,
    ) {
    }

    public function execute(): Engagement
    {
        return DB::connection('action_engine')->transaction(function () {
            if ($this->data->status !== null) {
                $pipeline = Pipeline::getBySlug($this->engagement->slug, $this->engagement->app, $this->engagement->company);
                $stage = $pipeline->stages()->where('slug', $this->data->status->value)->firstOrFail();
                $this->engagement->pipelines_stages_id = $stage->getId();
            }

            /** @var Message|null $message */
            $message = $this->engagement->message;

            if ($message) {
                $messageData = is_array($message->message) ? $message->message : [];
                $existingData = is_array($messageData['data'] ?? null) ? $messageData['data'] : [];
                $messageData['data'] = array_merge($existingData, $this->data->data);

                if ($this->data->description !== null) {
                    $messageData['description'] = $this->data->description;
                }

                if ($this->data->via !== null) {
                    $messageData['via'] = $this->data->via;
                }

                $message->message = $messageData;
                $message->saveOrFail();
            }

            $this->engagement->saveOrFail();

            return $this->engagement;
        });
    }
}
