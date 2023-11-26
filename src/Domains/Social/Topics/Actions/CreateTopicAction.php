<?php

declare(strict_types=1);

namespace Kanvas\Social\Topics\Actions;

use Baka\Support\Str;
use Kanvas\Social\Topics\DataTransferObject\TopicInput;
use Kanvas\Social\Topics\Models\Topic;

class CreateTopicAction
{
    public function __construct(
        private TopicInput $data
    ) {
    }

    public function execute(): Topic
    {
        return Topic::create([
            'apps_id' => $this->data->app->getId(),
            'companies_id' => $this->data->company->getId(),
            'users_id' => $this->data->user->getId(),
            'name' => $this->data->name,
            'slug' => Str::slug($this->data->name),
            'weight' => $this->data->weight,
            'is_feature' => $this->data->is_feature,
            'status' => $this->data->status,
        ]);
    }
}
