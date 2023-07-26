<?php

declare(strict_types=1);

namespace Kanvas\Social\Topics\Actions;

use Kanvas\Social\Topics\Models\Topic;

class AttachEntityToTopic
{
    public function __construct(
        public Topic $topic,
        public string $entityId,
        public string $entityNamespace
    ) {
    }

    public function execute()
    {
        $this->topic->entities()->create([
            'entity_id' => $this->entityId,
            'entity_namespace' => $this->entityNamespace,
        ]);
    }
}
