<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Enums;

enum OnboardingSettingEnum: string
{
    /** App setting: true on the one app whose users may call startOnboarding. */
    case PLATFORM = 'onboarding_platform';
    /** App setting: name of the template agent that runs setup; the Company Brain row when unset. */
    case SETUP_AGENT = 'onboarding_setup_agent';
    case RESEARCH_LLM_CONFIG = 'company_research_llm_config_id';
    case RESEARCH_MODEL = 'company_research_model';

    public const string DEFAULT_SETUP_AGENT = 'Ginny';
}
