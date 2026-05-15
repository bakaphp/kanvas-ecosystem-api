<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Capability\DataTransferObject\Skill as SkillData;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Models\Skill;

class CreateSkillAction
{
    public function __construct(
        protected readonly SkillData $data,
        protected readonly ?int $actorUserId = null,
    ) {
    }

    public function execute(): Skill
    {
        $this->validateFrameworks();

        return DB::connection('intelligence')->transaction(function (): Skill {
            $skill = new Skill();
            $skill->apps_id = $this->data->app->getId();
            $skill->name = $this->data->name;
            $skill->description = $this->data->description;
            $skill->skill_type = $this->data->skillType->value;
            $skill->handler = $this->data->handler;
            $skill->definition = $this->data->definition;
            $skill->frameworks = $this->data->frameworks;
            $skill->version = $this->data->version;
            $skill->is_active = $this->data->isActive;
            $skill->saveOrFail();

            $skill->emitLedgerEvent(
                eventType: 'skill.created',
                payload: [
                    'name' => $skill->name,
                    'skill_type' => $skill->skill_type,
                    'frameworks' => $skill->frameworks,
                    'version' => $skill->version,
                ],
                actorType: $this->actorUserId !== null ? 'User' : 'System',
                actorId: $this->actorUserId,
            );

            return $skill;
        });
    }

    private function validateFrameworks(): void
    {
        $valid = CapabilityFrameworkEnum::values();

        foreach ($this->data->frameworks as $framework) {
            if (! in_array($framework, $valid, true)) {
                throw new ValidationException(sprintf(
                    'Unknown framework "%s". Allowed: %s',
                    $framework,
                    implode(', ', $valid),
                ));
            }
        }

        if (empty($this->data->frameworks)) {
            throw new ValidationException('A skill must declare at least one framework.');
        }
    }
}
