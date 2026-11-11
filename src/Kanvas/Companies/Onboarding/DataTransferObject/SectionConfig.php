<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Companies\Onboarding\Enums\RequirementModeEnum;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingSetupHookRegistry;
use Kanvas\Exceptions\ValidationException;

/**
 * The typed view over onboarding_sections.config. Agents and modules are the items a card
 * provisions; requirements are the slots those items need filled. Sharing between sections is
 * by requirement slug, so a slug an agent asks for may be declared in any active section of the
 * same app or of app 0.
 */
final readonly class SectionConfig
{
    /**
     * @param list<array<string, mixed>> $agents
     * @param list<array<string, mixed>> $modules
     * @param list<array<string, mixed>> $requirements
     * @param list<string> $capabilities
     * @param list<string> $setupSteps every step's title, whatever the country
     * @param list<array{title: string, readiness: ?string, countries: list<string>}> $setupStepDefinitions
     * @param string|null $setup post-provision hook key (OnboardingSetupHookRegistry)
     */
    public function __construct(
        public array $agents = [],
        public array $modules = [],
        public array $requirements = [],
        public array $capabilities = [],
        public array $setupSteps = [],
        public array $setupStepDefinitions = [],
        public ?string $setup = null,
    ) {
    }

    public static function fromArray(?array $config): self
    {
        $config ??= [];
        $steps = array_map(self::stepDefinition(...), array_values((array) ($config['setup_steps'] ?? [])));

        return new self(
            agents: array_values((array) ($config['agents'] ?? [])),
            modules: array_values((array) ($config['modules'] ?? [])),
            requirements: array_values((array) ($config['requirements'] ?? [])),
            capabilities: array_values(array_map('strval', (array) ($config['capabilities'] ?? []))),
            setupSteps: array_column($steps, 'title'),
            setupStepDefinitions: $steps,
            setup: Str::trimToNull((string) ($config['setup'] ?? '')),
        );
    }

    /**
     * A step is a title, or {title, readiness, countries}: `readiness` names the requirement whose turning
     * ready closes the task, `countries` limits the step to companies in those countries.
     *
     * @return array{title: string, readiness: ?string, countries: list<string>}
     */
    private static function stepDefinition(mixed $step): array
    {
        if (! is_array($step)) {
            return ['title' => (string) $step, 'readiness' => null, 'countries' => []];
        }

        return [
            'title' => (string) ($step['title'] ?? ''),
            'readiness' => Str::trimToNull((string) ($step['readiness'] ?? '')),
            'countries' => self::countries($step),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    public static function countries(array $entry): array
    {
        return array_values(array_map(
            fn (mixed $country): string => strtoupper(trim((string) $country)),
            (array) ($entry['countries'] ?? []),
        ));
    }

    /**
     * Whether a requirement or step limited to `countries` applies to a company in $country. An entry
     * without `countries` applies everywhere; one with them never applies to a company of unknown country.
     *
     * @param array<string, mixed> $entry
     */
    public static function appliesTo(array $entry, ?string $country): bool
    {
        $countries = self::countries($entry);

        return $countries === [] || ($country !== null && in_array(strtoupper($country), $countries, true));
    }

    /**
     * @return list<string> the step titles that apply to a company in $country
     */
    public function setupStepsFor(?string $country): array
    {
        return array_values(array_column(
            array_filter($this->setupStepDefinitions, fn (array $step): bool => self::appliesTo($step, $country)),
            'title',
        ));
    }

    /**
     * The post-provision item, when the section declares a setup: one per setup key, run after every
     * agent of the run exists.
     *
     * @return array<string, mixed>|null
     */
    public function setupItem(): ?array
    {
        if ($this->setup === null) {
            return null;
        }

        return [
            'slug' => 'setup_' . $this->setup,
            'kind' => 'setup',
            'setup' => $this->setup,
            'name' => ucfirst($this->setup) . ' setup',
            'description' => '',
            'requirements' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>> agents then modules, each with a `kind`
     */
    public function items(): array
    {
        $items = [];
        foreach ($this->agents as $agent) {
            $items[] = $agent + ['kind' => 'agent'];
        }
        foreach ($this->modules as $module) {
            $items[] = $module + ['kind' => 'module', 'requirements' => []];
        }

        return $items;
    }

    public function agentBySlug(string $slug): ?array
    {
        foreach ($this->agents as $agent) {
            if (($agent['slug'] ?? null) === $slug) {
                return $agent;
            }
        }

        return null;
    }

    public function requirementBySlug(string $slug): ?array
    {
        foreach ($this->requirements as $requirement) {
            if (($requirement['slug'] ?? null) === $slug) {
                return $requirement;
            }
        }

        return null;
    }

    /**
     * @return list<string> every requirement slug an item of this section asks for
     */
    public function requestedRequirementSlugs(): array
    {
        $slugs = [];
        foreach ($this->agents as $agent) {
            foreach ((array) ($agent['requirements'] ?? []) as $slug) {
                $slugs[(string) $slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * Slugs become dot paths inside the onboarding row's JSON (`provisioning.{slug}`), so a dot
     * would silently nest the write somewhere else.
     */
    private static function assertSlugShape(OnboardingSection $section, string $slug): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $slug)) {
            throw new ValidationException("Section {$section->slug}: slug \"{$slug}\" must be lowercase letters, digits and underscores");
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function assertCountries(OnboardingSection $section, string $what, array $entry): void
    {
        foreach (self::countries($entry) as $country) {
            if (! preg_match('/^[A-Z]{2}$/', $country)) {
                throw new ValidationException("Section {$section->slug}: {$what} lists \"{$country}\", not an ISO 3166-1 alpha-2 country code");
            }
        }
    }

    public function validate(OnboardingSection $section): void
    {
        $seen = [];
        foreach ($this->items() as $item) {
            $slug = (string) ($item['slug'] ?? '');
            if ($slug === '') {
                throw new ValidationException("Section {$section->slug}: every agent and module needs a slug");
            }
            if (isset($seen[$slug])) {
                throw new ValidationException("Section {$section->slug}: duplicate item slug \"{$slug}\"");
            }
            self::assertSlugShape($section, $slug);
            $seen[$slug] = true;

            if ($item['kind'] === 'agent' && empty($item['source_agent'])) {
                throw new ValidationException("Section {$section->slug}: agent \"{$slug}\" needs a source_agent");
            }
        }

        if ($this->setup !== null && ! OnboardingSetupHookRegistry::has($this->setup)) {
            throw new ValidationException("Section {$section->slug}: unknown setup \"{$this->setup}\"");
        }

        foreach ($this->setupStepDefinitions as $step) {
            if (trim($step['title']) === '') {
                throw new ValidationException("Section {$section->slug}: every setup step needs a title");
            }
            self::assertCountries($section, 'setup step "' . $step['title'] . '"', $step);
        }

        foreach ($this->requirements as $requirement) {
            $requirementSlug = (string) ($requirement['slug'] ?? '');
            if ($requirementSlug === '') {
                throw new ValidationException("Section {$section->slug}: every requirement needs a slug");
            }
            self::assertSlugShape($section, $requirementSlug);
            self::assertCountries($section, "requirement \"{$requirementSlug}\"", $requirement);

            foreach ((array) ($requirement['options'] ?? []) as $option) {
                $mode = RequirementModeEnum::tryFrom((string) ($option['mode'] ?? ''));
                if ($mode === null) {
                    throw new ValidationException(sprintf(
                        'Section %s: requirement "%s" option "%s" has an unknown mode "%s"',
                        $section->slug,
                        $requirementSlug,
                        $option['label'] ?? '?',
                        $option['mode'] ?? '',
                    ));
                }

                if ($mode === RequirementModeEnum::CONNECT && empty($option['integration_id']) && empty($option['integration_name']) && empty($option['connector'])) {
                    throw new ValidationException(sprintf(
                        'Section %s: requirement "%s" option "%s" is mode connect but names no integration_id, integration_name or connector',
                        $section->slug,
                        $requirementSlug,
                        $option['label'] ?? '?',
                    ));
                }
            }
        }

        $declared = $section->declaredRequirementSlugs();
        foreach ($this->requestedRequirementSlugs() as $slug) {
            if (! in_array($slug, $declared, true)) {
                throw new ValidationException(sprintf(
                    'Section %s: requirement "%s" is requested by an agent but declared in no active section of app %d or app 0',
                    $section->slug,
                    $slug,
                    (int) $section->apps_id,
                ));
            }
        }
    }
}
