<?php

declare(strict_types=1);

namespace App\GraphQL\Approvals\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Services\ApproverResolverRegistryService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\SystemModules\Models\SystemModules;

class ApprovalPolicyMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): ApprovalPolicy
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        return ApprovalPolicy::create([
            'apps_id' => $ctx->app->getId(),
            'companies_id' => $ctx->company->getId(),
            'system_modules_id' => $this->systemModule($input)->getId(),
            ...$this->attributes($input),
        ]);
    }

    public function update(mixed $rootValue, array $request): ApprovalPolicy
    {
        $ctx = $this->actingContext();

        /** @var ApprovalPolicy $policy */
        $policy = ApprovalPolicy::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        $policy->fill([
            'system_modules_id' => $this->systemModule($request['input'])->getId(),
            ...$this->attributes($request['input']),
        ]);
        $policy->saveOrFail();

        return $policy;
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var ApprovalPolicy $policy */
        $policy = ApprovalPolicy::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return $policy->softDelete();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $input): array
    {
        $steps = $this->validatedSteps($input['steps'] ?? []);

        return [
            'approval_type' => (string) $input['approval_type'],
            'steps' => $steps,
            'handler' => $input['handler'] ?? null,
            'trigger' => ApprovalTriggerEnum::tryFrom((string) ($input['trigger'] ?? 'manual'))
                ?? ApprovalTriggerEnum::MANUAL,
            'trigger_condition' => $input['trigger_condition'] ?? null,
            'trigger_event' => $input['trigger_event'] ?? null,
            'reject_policy' => in_array($input['reject_policy'] ?? 'any', ['any', 'step'], true)
                ? $input['reject_policy'] ?? 'any'
                : 'any',
            'fallback_resolver' => $input['fallback_resolver'] ?? null,
            'fallback_config' => $input['fallback_config'] ?? null,
            'notify' => (string) ($input['notify'] ?? 'all'),
            'expires_after_hours' => $input['expires_after_hours'] ?? null,
            'allow_authority_override' => (bool) ($input['allow_authority_override'] ?? false),
        ];
    }

    /**
     * A policy naming a resolver that does not exist would produce requests nobody can ever approve,
     * so it is rejected at configuration time rather than discovered when the first record is gated.
     */
    private function validatedSteps(mixed $steps): array
    {
        $steps = (array) $steps;

        if ($steps === []) {
            throw new ValidationException('A policy needs at least one step.');
        }

        foreach ($steps as $step) {
            $resolver = (string) (((array) $step)['resolver'] ?? '');

            if (! ApproverResolverRegistryService::has($resolver)) {
                throw new ValidationException("Unknown approver resolver \"{$resolver}\".");
            }
        }

        return $steps;
    }

    private function systemModule(array $input): SystemModules
    {
        $ctx = $this->actingContext();

        // getById scopes to the acting app, which is what we want: policies must target the app's own
        // system module row, the same one getByModelName() resolves at request time.
        /** @var SystemModules $systemModule */
        $systemModule = SystemModules::getById((int) $input['system_module_id'], $ctx->app);

        return $systemModule;
    }
}
