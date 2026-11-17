<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Override;

class ProcessContactTagUpdatedAction extends BaseRespondIOAction
{
    #[Override]
    public function execute(): array
    {
        $tag = $this->payload['tag'] ?? null;
        $action = $this->payload['action'] ?? null;
        $people = $this->resolvePeople();

        if ($people === null) {
            return $this->unresolved('contact_not_found');
        }

        /** @var array<int, string> $allTags */
        $allTags = $this->payload['contact']['tags'] ?? [];
        if (! empty($allTags)) {
            $people->syncTags($allTags);
        } elseif ($tag !== null && $action === 'add') {
            $people->addTag($tag);
        } elseif ($tag !== null && $action === 'remove') {
            $people->removeTag($tag);
        }

        return [
            'status' => 'contact_tag_updated',
            'people_id' => $people->getId(),
            'tag' => $tag,
            'action' => $action,
        ];
    }
}
