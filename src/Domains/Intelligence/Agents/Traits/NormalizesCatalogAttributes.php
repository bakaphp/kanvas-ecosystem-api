<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

/**
 * Turns whatever shape a model sends for product/variant specs into the `[['name' => …, 'value' => …]]`
 * list that Products::addAttributes() and Variants::addAttributes() expect.
 *
 * Both spellings show up in practice — a map, which is what a model reaches for when told to send
 * `{"Colour": "Red"}`, and the list-of-objects the importers and GraphQL input use — so both are
 * accepted rather than making the model guess which one this tool wanted.
 *
 * Names are resolved (and created when new) by ResolvesAttributesTrait, so the model never needs an
 * attribute id.
 */
trait NormalizesCatalogAttributes
{
    /**
     * @param array<mixed> $attributes
     * @return list<array{name: string, value: mixed}>
     */
    protected function toCatalogAttributePairs(array $attributes): array
    {
        $pairs = [];

        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                $name = trim((string) ($value['name'] ?? ''));
                $attributeValue = $value['value'] ?? null;
            } else {
                $name = trim((string) $key);
                $attributeValue = $value;
            }

            if ($name === '' || $attributeValue === null || $attributeValue === '') {
                continue;
            }

            $pairs[] = [
                'name' => $name,
                'value' => is_scalar($attributeValue) ? (string) $attributeValue : $attributeValue,
            ];
        }

        return $pairs;
    }

    /**
     * The removal side takes names only. A model that sends `{"Warranty": null}` in the set payload
     * is trying to remove, but AddAttributeAction treats an empty value as a no-op, so the two
     * spellings have to stay separate — this normalizes whichever list shape the removal arrives in.
     *
     * @param array<mixed> $names
     * @return list<string>
     */
    protected function toCatalogAttributeNames(array $names): array
    {
        $clean = [];

        foreach ($names as $name) {
            $name = trim((string) (is_array($name) ? ($name['name'] ?? '') : $name));

            if ($name !== '') {
                $clean[] = $name;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Products and variants remove attributes through separate same-named actions, so only the call
     * differs — the resolve-skip-collect loop around it is shared. Host supplies
     * resolveCatalogAttributeByName() via ResolvesCatalogEntities.
     *
     * @param list<string> $names
     * @param callable(object): void $remove receives the resolved Attributes model
     * @return list<string> the names that actually matched an attribute
     */
    protected function removeCatalogAttributesByName(array $names, callable $remove): array
    {
        $removed = [];

        foreach ($names as $name) {
            $attribute = $this->resolveCatalogAttributeByName($name);

            if ($attribute === null) {
                continue;
            }

            $remove($attribute);
            $removed[] = $name;
        }

        return $removed;
    }

    /**
     * Names that matched nothing are reported rather than swallowed: silently succeeding on a
     * misspelled attribute leaves the model believing it removed something it did not.
     *
     * @param list<string> $requested
     * @param list<string> $removed
     * @return array<string, mixed>
     */
    protected function catalogRemovalOutcome(array $requested, array $removed): array
    {
        $outcome = ['attributes_removed' => $removed];
        $missed = array_values(array_diff($requested, $removed));

        if ($missed !== []) {
            $outcome['attributes_not_found'] = $missed;
        }

        return $outcome;
    }
}
