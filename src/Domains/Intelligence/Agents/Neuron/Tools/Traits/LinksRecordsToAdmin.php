<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Database\Eloquent\Model;

/**
 * A record in a tool result carries its admin URL, so the model never spends a round on
 * build_admin_link for something it was just handed. The tool's app stands in for the record's own
 * relation: the record is already scoped to it, and a list of fifty would otherwise load it fifty times.
 */
trait LinksRecordsToAdmin
{
    protected function adminUrlOf(Model $record): ?string
    {
        if (! method_exists($record, 'adminUrl')) {
            return null;
        }

        if (isset($this->app) && ! $record->relationLoaded('app')) {
            $record->setRelation('app', $this->app);
        }

        return $record->adminUrl();
    }
}
