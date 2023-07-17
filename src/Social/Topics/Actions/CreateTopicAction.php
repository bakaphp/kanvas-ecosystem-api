<?php

declare(strict_types=1);

namespace Kanvas\Social\Topics\Actions;

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
            'apps_id' => $this->data->apps_id,
            'companies_id' => $this->data->companies_id,
            'users_id' => $this->data->users_id,
            'name' => $this->data->name,
            'slug' => $this->data->slug,
            'weight' => $this->data->weight,
            'is_feature' => $this->data->is_feature,
            'status' => $this->data->status,
        ]);
    }
}
