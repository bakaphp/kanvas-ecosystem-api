<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\DataTransferObject\EventVersion;
use Kanvas\Event\Events\Models\EventVersion as ModelsEventVersion;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreateEventVersionAction
{
    protected bool $runWorkflow = true;

    public function __construct(
        protected EventVersion $eventVersion,
    ) {
    }

    public function disableWorkflow(): self
    {
        $this->runWorkflow = false;

        return $this;
    }

    public function execute(): ModelsEventVersion
    {
        $baseSlug = $this->eventVersion->slug ?? Str::slug($this->eventVersion->name);
        $appId = $this->eventVersion->event->app->getId();
        $companyId = $this->eventVersion->event->company->getId();

        $startAt = null;
        $endAt = null;

        if ($this->eventVersion->dates && $this->eventVersion->dates->count() > 0) {
            $firstDate = $this->eventVersion->dates->first();
            $lastDate = $this->eventVersion->dates->last();

            $startAt = Carbon::parse($firstDate->date->format('Y-m-d') . ' ' . $firstDate->start_time);
            $endAt = Carbon::parse($lastDate->date->format('Y-m-d') . ' ' . $lastDate->end_time);
        }

        $highestVersion = (int) ModelsEventVersion::withTrashed()
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where(function ($query) use ($baseSlug) {
                $query->where('slug', $baseSlug)
                    ->orWhere('slug', 'like', $baseSlug . '-%');
            })
            ->max('version');

        $version = $highestVersion > 0 ? $highestVersion + 1 : (int) $this->eventVersion->version;
        $slug = $highestVersion > 0 ? $baseSlug . '-' . $version : $baseSlug;

        $eventVersion = new ModelsEventVersion();
        $eventVersion->apps_id = $appId;
        $eventVersion->companies_id = $companyId;
        $eventVersion->users_id = $this->eventVersion->user->getId();
        $eventVersion->event_id = $this->eventVersion->event->getId();
        $eventVersion->currency_id = $this->eventVersion->currency->getId();
        $eventVersion->time_slot_id = $this->eventVersion->timeSlotId;
        $eventVersion->name = $this->eventVersion->name;
        $eventVersion->version = $version;
        $eventVersion->description = $this->eventVersion->description;
        $eventVersion->price_per_ticket = $this->eventVersion->pricePerTicket;
        $eventVersion->agenda = $this->eventVersion->agenda ?? null;
        $eventVersion->metadata = $this->eventVersion->metadata ?? null;
        $eventVersion->slug = $slug;
        $eventVersion->start_at = $startAt;
        $eventVersion->end_at = $endAt;
        $eventVersion->saveOrFail();

        $eventVersion->addDates($this->eventVersion->dates);

        if ($this->runWorkflow) {
            $eventVersion->fireWorkflow(
                WorkflowEnum::EVENT_VERSIONS_WORKFLOW->value,
                true,
                [
                    'app' => $this->eventVersion->event->app,
                    'company' => $this->eventVersion->event->company,
                    'event_version_change' => WorkflowEnum::CREATED->value,
                ],
            );
        }

        return $eventVersion;
    }
}
