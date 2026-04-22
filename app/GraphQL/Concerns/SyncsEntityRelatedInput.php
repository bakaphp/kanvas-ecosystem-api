<?php

declare(strict_types=1);

namespace App\GraphQL\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared helpers for GraphQL mutations that apply the common
 * `custom_fields`, `tags`, and `files` input blocks to a target model.
 *
 * The target model is expected to use `HasCustomFields`, `HasTagsTrait`,
 * and `HasFilesystemTrait`. Safe to reuse across any domain whose
 * `*Input` types include these three optional arrays.
 */
trait SyncsEntityRelatedInput
{
    protected static function syncCustomFields(Model $entity, array $input): void
    {
        if (empty($input['custom_fields']) || ! is_array($input['custom_fields'])) {
            return;
        }

        $entity->setAllCustomFields($input['custom_fields']);
    }

    protected static function syncTags(Model $entity, array $input): void
    {
        if (! array_key_exists('tags', $input) || ! is_array($input['tags'])) {
            return;
        }

        $tagNames = [];
        foreach ($input['tags'] as $tag) {
            if (is_array($tag) && isset($tag['name'])) {
                $tagNames[] = (string) $tag['name'];
            } elseif (is_string($tag)) {
                $tagNames[] = $tag;
            }
        }

        if (empty($tagNames) || ! method_exists($entity, 'syncTags')) {
            return;
        }

        $entity->syncTags($tagNames);
    }

    protected static function syncFiles(Model $entity, array $input): void
    {
        if (empty($input['files']) || ! is_array($input['files'])) {
            return;
        }

        $entity->addMultipleFilesFromUrl($input['files']);
    }

    protected static function syncEntityRelatedInput(Model $entity, array $input): void
    {
        self::syncCustomFields($entity, $input);
        self::syncTags($entity, $input);
        self::syncFiles($entity, $input);
    }
}
