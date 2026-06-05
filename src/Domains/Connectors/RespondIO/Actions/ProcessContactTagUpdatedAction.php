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
        /** @var array<string, mixed> $contact */
        $contact = $this->payload['contact'] ?? [];
        $tag = $this->payload['tag'] ?? null;
        $action = $this->payload['action'] ?? null;

        $identifier = $this->getContactIdentifier($contact);

        if ($identifier === null) {
            return ['error' => 'Missing contact identifier'];
        }

        $people = $this->findPeopleByIdentifier($this->app, $this->company, $identifier);

        if (! $people) {
            return ['status' => 'contact_not_found', 'identifier' => $identifier];
        }

        /** @var array<int, string> $allTags */
        $allTags = $contact['tags'] ?? [];
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
