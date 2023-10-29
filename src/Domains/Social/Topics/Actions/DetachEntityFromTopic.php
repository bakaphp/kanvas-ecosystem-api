<?php

declare(strict_types=1);

namespace Kanvas\Social\Topics\Actions;

use Kanvas\Social\Topics\Models\Topic;

class DetachEntityFromTopic
{
    public function __construct(
        public Topic $topic,
        public string $entityId,
        public string $entityNamespace
    ) {
    }

    public function execute(): Topic
    {
        $this->topic->entities()->where([
            'entity_id' => $this->entityId,
            'entity_namespace' => $this->entityNamespace,
        ])->delete();

        return $this->topic;
    }
}
