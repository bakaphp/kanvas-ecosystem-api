<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\HumanResources\Meetings\DataTransferObject\Meeting as MeetingData;
use Kanvas\HumanResources\Meetings\Models\Meeting;

class CreateMeetingAction
{
    public function __construct(
        protected readonly MeetingData $data,
    ) {
    }

    /**
     * The client generates the uuid so it can retry a create it never got an answer for: the same uuid
     * from the same owner returns the meeting already stored instead of a second one.
     */
    public function execute(): Meeting
    {
        $existing = $this->findByUuid();

        if ($existing !== null) {
            return $this->answerRetry($existing);
        }

        $meeting = new Meeting();
        $meeting->uuid = $this->data->uuid;
        $meeting->apps_id = $this->data->app->getId();
        $meeting->companies_id = $this->data->company->getId();
        $meeting->users_id = $this->data->user->getId();
        $meeting->people_id = $this->data->people?->getId();
        $meeting->title = $this->data->title;
        $meeting->started_at = $this->data->startedAt;
        $meeting->ended_at = $this->data->endedAt;

        try {
            $meeting->saveOrFail();
        } catch (UniqueConstraintViolationException) {
            // The original request and its retry were both in flight; the other one won the insert.
            return $this->answerRetry($this->findByUuid());
        }

        return $meeting->refresh();
    }

    protected function findByUuid(): ?Meeting
    {
        return $this->data->uuid !== null
            ? Meeting::query()->where('uuid', $this->data->uuid)->first()
            : null;
    }

    protected function answerRetry(Meeting $meeting): Meeting
    {
        $isFromSameOwner = $meeting->apps_id === $this->data->app->getId()
            && $meeting->companies_id === $this->data->company->getId()
            && $meeting->users_id === $this->data->user->getId();

        return $isFromSameOwner
            ? $meeting
            : throw new ValidationException('This meeting uuid cannot be used');
    }
}
