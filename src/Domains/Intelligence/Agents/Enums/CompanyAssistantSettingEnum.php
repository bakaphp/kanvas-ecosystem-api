<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum CompanyAssistantSettingEnum: string
{
    /**
     * A COMPANY setting: the id of the Company Brain agent that is this company's assistant. Unset, the
     * oldest active one is. Only matters to a company with more than one.
     */
    case AGENT_ID = 'company_assistant_agent_id';
}
