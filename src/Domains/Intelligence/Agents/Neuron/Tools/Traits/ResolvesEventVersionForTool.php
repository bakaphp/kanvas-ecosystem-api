<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Exceptions\ModelNotFoundException;

/**
 * Look up an event version from a tool's __invoke, returning the version OR a structured error, so
 * a hallucinated or foreign `version_id` answers the model instead of crashing the chat. Scoped to
 * the host's tenant: the host provides `$app` / `$company`, normally through HasKanvasContext.
 *
 *   $version = $this->resolveEventVersionOrError($version_id);
 *   if (is_array($version)) {
 *       return $version;
 *   }
 */
trait ResolvesEventVersionForTool
{
    /**
     * @return EventVersion|array{error: string}
     */
    protected function resolveEventVersionOrError(int $versionId): EventVersion|array
    {
        try {
            return EventVersion::getByIdFromCompanyApp($versionId, $this->company, $this->app);
        } catch (ModelNotFoundException) {
            return ['error' => sprintf('No event version #%d found in this company.', $versionId)];
        }
    }
}
