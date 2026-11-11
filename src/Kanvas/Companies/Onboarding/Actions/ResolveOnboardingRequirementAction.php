<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Enums\RequirementModeEnum;
use Kanvas\Companies\Onboarding\Enums\RequirementStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Notifications\IntegrationSetupRequestedNotification;
use Kanvas\Companies\Onboarding\Services\OnboardingSectionsService;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;

/**
 * Records the company's answer to one requirement slot. The answer is written once at company
 * level, so a shared slot reads as answered on every card that declares it. Connecting happens in
 * the connector's own flow afterwards; this only records that it was chosen.
 */
final class ResolveOnboardingRequirementAction
{
    /**
     * @param array<string, mixed> $input the ResolveOnboardingRequirementInput
     */
    public function __construct(
        private readonly CompanyOnboarding $row,
        private readonly Users $user,
        private readonly array $input,
    ) {
    }

    /**
     * @return array<string, mixed> the requirement entry as the state renders it
     */
    public function execute(): array
    {
        $app = $this->row->app;
        $slug = trim((string) ($this->input['requirement'] ?? ''));

        if (! isset($this->requirementsBySlug()[$slug])) {
            throw new ValidationException("Requirement \"{$slug}\" is not needed by any selected item");
        }

        $definition = new OnboardingSectionsService()->requirementDefinitions($app)[$slug];
        $answer = $this->answer($slug, $definition) + [
            'resolved_at' => Carbon::now()->toIso8601String(),
            'resolved_by' => $this->user->getId(),
        ];

        $this->row->dataSet("requirements.{$slug}", $answer);

        if ($answer['status'] === RequirementStatusEnum::SETUP_REQUESTED->value) {
            $this->notifyOwners(
                $app,
                (string) ($definition['label'] ?? $slug),
                (string) $answer['option'],
                $answer['notes'],
            );
        }

        $this->row->emitLedgerEvent(
            'onboarding.requirement.resolved',
            payload: ['requirement' => $slug, 'status' => $answer['status'], 'mode' => $answer['mode'], 'option' => $answer['option']],
            actorType: 'User',
            actorId: $this->user->getId(),
        );

        return $this->requirementsBySlug()[$slug];
    }

    /**
     * @return array<string, array<string, mixed>> the requirements as the state renders them
     */
    private function requirementsBySlug(): array
    {
        return array_column(new OnboardingStateService()->state($this->row->refresh())->requirements, null, 'slug');
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function answer(string $slug, array $definition): array
    {
        $notes = Str::trimToNull($this->input['notes'] ?? null);
        $base = [
            'mode' => null,
            'integration_id' => null,
            'connector' => null,
            'filesystem_id' => null,
            'option' => null,
            'tool_name' => null,
            'notes' => $notes,
        ];

        if (! empty($this->input['not_used'])) {
            if (empty($definition['allow_not_used'])) {
                throw new ValidationException("Requirement \"{$slug}\" cannot be marked as not used");
            }

            return $base + ['status' => RequirementStatusEnum::NOT_USED->value];
        }

        $toolName = Str::trimToNull($this->input['tool_name'] ?? null);
        if ($toolName !== null) {
            if (empty($definition['allow_not_listed'])) {
                throw new ValidationException("Requirement \"{$slug}\" does not accept a tool that is not listed");
            }

            return ['tool_name' => $toolName, 'status' => RequirementStatusEnum::NOT_LISTED->value] + $base;
        }

        $mode = RequirementModeEnum::tryFrom(strtolower((string) ($this->input['mode'] ?? '')))
            ?? throw new ValidationException("Requirement \"{$slug}\" needs a mode, not_used or tool_name");
        $option = $this->option($slug, $definition, $mode);

        $answer = [
            'mode' => $mode->value,
            'integration_id' => isset($option['integration_id']) ? (int) $option['integration_id'] : null,
            'connector' => $option['connector'] ?? null,
            'option' => (string) ($option['label'] ?? ''),
        ] + $base;

        return match ($mode) {
            RequirementModeEnum::CONNECT => $answer + ['status' => RequirementStatusEnum::PENDING_CONNECT->value],
            RequirementModeEnum::REQUEST_SETUP => $answer + ['status' => RequirementStatusEnum::SETUP_REQUESTED->value],
            RequirementModeEnum::UPLOAD => ['filesystem_id' => $this->uploadedFile($slug), 'status' => RequirementStatusEnum::UPLOADED->value] + $answer,
            // Nothing to connect: the platform side already exists, so the slot is answered on pick.
            RequirementModeEnum::KANVAS_PROVIDED,
            RequirementModeEnum::WEBHOOK => $answer + ['status' => RequirementStatusEnum::CONNECTED->value],
        };
    }

    /**
     * The option the input points at: by integration_id or connector when the input names one,
     * otherwise the single option of that mode.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function option(string $slug, array $definition, RequirementModeEnum $mode): array
    {
        $integrationId = (int) ($this->input['integration_id'] ?? 0);
        $connector = Str::trimToNull($this->input['connector'] ?? null);

        foreach ((array) ($definition['options'] ?? []) as $option) {
            if (($option['mode'] ?? null) !== $mode->value) {
                continue;
            }
            if ($integrationId > 0 && (int) ($option['integration_id'] ?? 0) !== $integrationId) {
                continue;
            }
            if ($connector !== null && ($option['connector'] ?? null) !== $connector) {
                continue;
            }
            if ($integrationId === 0 && $connector === null && (! empty($option['integration_id']) || ! empty($option['connector']))) {
                continue;
            }

            return $option;
        }

        throw new ValidationException("Requirement \"{$slug}\" has no {$mode->value} option matching the input");
    }

    private function uploadedFile(string $slug): int
    {
        $fileId = (int) ($this->input['filesystem_id'] ?? 0);
        $exists = $fileId > 0 && Filesystem::query()
            ->whereKey($fileId)
            ->fromApp($this->row->app)
            ->fromCompany($this->row->company)
            ->notDeleted()
            ->exists();

        if (! $exists) {
            throw new ValidationException("Requirement \"{$slug}\" needs the uploaded file's filesystem_id");
        }

        return $fileId;
    }

    private function notifyOwners(
        Apps $app,
        string $requirementLabel,
        string $optionLabel,
        ?string $notes,
    ): void {
        // "We'll set it up with you" is a promise by the platform team, not by the customer who
        // owns a self-serve app: route the request to the platform app's owners when there is one.
        $platformAppId = (int) $this->row->dataGet('platform_apps_id', 0);
        $recipientsApp = $platformAppId > 0 ? Apps::getById($platformAppId) : $app;

        // Roles are scope-filtered; the owners live in the app's global scope, whatever the request's.
        $owners = Bouncer::scope()->onceTo(
            RolesEnums::getScope($recipientsApp),
            fn () => Users::whereIs(RolesEnums::OWNER->value)->get(),
        );

        if ($owners->isEmpty()) {
            return;
        }

        Notification::send($owners, new IntegrationSetupRequestedNotification(
            $this->row,
            $requirementLabel,
            $optionLabel,
            $notes,
            $this->user,
        ));
    }
}
