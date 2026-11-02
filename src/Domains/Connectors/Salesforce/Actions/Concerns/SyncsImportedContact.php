<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Customers\Models\People;

/**
 * Shared by PullPropertyContactAction (Location_Contact__c) and PullProjectContactAction
 * (Project_Contact__c): writing a contact field, and replacing the tag(s) that stand in for a
 * People<->Product/Variant relationship Kanvas has no dedicated table for (see ListPeopleTool).
 */
trait SyncsImportedContact
{
    private function syncContact(People $people, int $typeId, string $value): void
    {
        $exists = $people->contacts()
            ->where('contacts_types_id', $typeId)
            ->where('value', $value)
            ->exists();

        if (! $exists) {
            $people->contacts()->create([
                'contacts_types_id' => $typeId,
                'value' => $value,
                'weight' => 0,
            ]);
        }
    }

    /**
     * Drops any tag a previous run of this action wrote for this record — identified by its
     * relationship suffix (see relationshipTagSuffix()) — before writing the current set, so a
     * re-sync with a changed role or interest level doesn't leave the old tag standing alongside
     * the new one.
     *
     * @param list<string> $currentTags
     */
    private function replaceRelationshipTags(People $people, Model $record, array $currentTags): void
    {
        $people->tags()
            ->where('name', 'like', '%' . $this->relationshipTagSuffix($record))
            ->get()
            ->each(fn ($tag) => $people->removeTag($tag->name));

        foreach ($currentTags as $tag) {
            $people->addTag($tag);
        }
    }

    /**
     * Products and Variants are separate auto-incrementing tables, so their ids collide
     * constantly (a Products row #23 and a Variants row #23 both exist in any real catalog) —
     * suffixing on the id alone let a project-contact re-sync match and delete an unrelated
     * location-contact tag that happened to end in the same number. The record's own class name
     * disambiguates which id space the suffix belongs to.
     */
    private function relationshipTagSuffix(Model $record): string
    {
        return ' #' . class_basename($record) . ':' . $record->getKey();
    }
}
