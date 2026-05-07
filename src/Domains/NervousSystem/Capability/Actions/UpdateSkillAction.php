<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Capability\DataTransferObject\Skill as SkillData;
use Kanvas\NervousSystem\Capability\Models\Skill;

class UpdateSkillAction
{
    public function __construct(
        protected readonly Skill $skill,
        protected readonly SkillData $data,
        protected readonly ?int $actorUserId = null,
    ) {
    }

    public function execute(): Skill
    {
        return DB::connection('intelligence')->transaction(function (): Skill {
            $diff = [
                'name' => [$this->skill->name, $this->data->name],
                'version' => [$this->skill->version, $this->data->version],
                'frameworks' => [$this->skill->frameworks, $this->data->frameworks],
            ];

            $this->skill->name = $this->data->name;
            $this->skill->description = $this->data->description;
            $this->skill->skill_type = $this->data->skillType->value;
            $this->skill->handler = $this->data->handler;
            $this->skill->definition = $this->data->definition;
            $this->skill->frameworks = $this->data->frameworks;
            $this->skill->version = $this->data->version;
            $this->skill->is_active = $this->data->isActive;
            $this->skill->saveOrFail();

            $this->skill->emitLedgerEvent(
                eventType: 'skill.updated',
                payload: ['diff' => $diff],
                actorType: $this->actorUserId !== null ? 'User' : 'System',
                actorId: $this->actorUserId,
            );

            return $this->skill->fresh() ?? $this->skill;
        });
    }
}
