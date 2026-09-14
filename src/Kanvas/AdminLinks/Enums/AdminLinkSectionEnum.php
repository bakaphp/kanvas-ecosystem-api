<?php

declare(strict_types=1);

namespace Kanvas\AdminLinks\Enums;

/**
 * The Kanvas Admin v2 route table, as data.
 *
 * Mirrors the route tree under `src/app/projects/[key]/`, the section map and the
 * company gate in the admin middleware. When a route moves on the frontend, this
 * is the single case that changes here.
 */
enum AdminLinkSectionEnum: string
{
    case DASHBOARD = 'dashboard';
    case SETTINGS = 'settings';

    case USER = 'users';
    case ROLE = 'roles';
    case COMPANY = 'companies';
    case SUBSCRIPTION_PLAN = 'plans';
    case EMAIL_TEMPLATE = 'emails';

    case LEAD = 'leads';
    case DEAL = 'deals';
    case PEOPLE = 'clients';
    case ORGANIZATION = 'organizations';
    case ORGANIZATION_TYPE = 'crm-organization-types';
    case LEAD_TYPE = 'crm-lead-types';
    case LEAD_SOURCE = 'crm-lead-sources';
    case RECEIVER = 'receivers';
    case ROTATION = 'rotations';
    case PIPELINE = 'pipelines';
    case PEOPLE_RELATIONSHIP = 'people-relationships';

    case ORDER = 'orders';
    case DRAFT_ORDER = 'draft';
    case DISCOUNT = 'discounts';
    case ORDER_STATUS = 'order-status';
    case ORDER_TYPE = 'order-types';
    case AFFILIATE = 'affiliates';
    case AFFILIATE_PROGRAM = 'affiliate-programs';

    case PRODUCT = 'product';
    case PRODUCT_VARIANT = 'product/variants';
    case PRODUCT_TYPE = 'type';
    case CATEGORY = 'category';
    case ATTRIBUTE = 'attributes';
    case STATUS = 'status';
    case REGION = 'region';
    case WAREHOUSE = 'warehouse';
    case CHANNEL = 'channels';

    case AGENT = 'agents';
    case AGENT_SWARM = 'agent-swarms';
    case AGENT_PROJECT = 'agent-projects';
    case LLM_CONFIG = 'llm-configs';
    case MACHINE = 'machines';

    case ACTION = 'actions';
    case COMPANY_ACTION = 'company-actions';
    case ACTION_PIPELINE = 'action-pipelines';
    case CHECKLIST = 'checklists';

    case MESSAGE = 'messages';
    case MESSAGE_TYPE = 'message-types';

    case EVENT = 'events';
    case FACILITATOR = 'facilitators';
    case PARTICIPANT = 'participants';
    case PARTICIPANT_TYPE = 'participant-types';

    case PLATFORM = 'platforms';
    case WORKFLOW = 'workflows';
    case WORKFLOW_RECEIVER = 'workflow-receivers';
    case RULE = 'rules';
    case RECEIVER_LOG = 'logs-receiver';

    case MAPPER = 'mapper';
    case MAPPER_IMPORT_HISTORY = 'mapper/import-history';

    case CONTROL_CENTER = 'control-center';
    case CONTROL_CENTER_CHAT = 'control-center/chat';

    public function segment(): string
    {
        return $this->value;
    }

    /**
     * A few sections hang their list screen off a different path than their detail route — the mapper
     * list is `mapper/list` while a mapper is `mapper/<id>` — so linking to the list by dropping the
     * identifier off the detail segment lands on nothing.
     */
    public function listSegment(): string
    {
        return match ($this) {
            self::MAPPER => 'mapper/list',
            default => $this->segment(),
        };
    }

    /**
     * Callers address a section by case name, never by segment. `plans` is the
     * *subscription* plan screen, so a caller reaching for a NervousSystem plan
     * by segment would silently get the wrong route — the alias makes that a
     * rejected input instead of a wrong link.
     */
    public function alias(): string
    {
        return strtolower($this->name);
    }

    /**
     * @return list<string>
     */
    public static function aliases(): array
    {
        return array_map(fn (self $section): string => $section->alias(), self::cases());
    }

    public static function tryFromAlias(string $alias): ?self
    {
        $normalized = strtoupper(str_replace('-', '_', trim($alias)));

        foreach (self::cases() as $section) {
            if ($section->name === $normalized) {
                return $section;
            }
        }

        return null;
    }

    public function identifier(): AdminLinkIdentifierEnum
    {
        return match ($this) {
            self::LEAD,
            self::DEAL,
            self::PEOPLE,
            self::EVENT,
            self::FACILITATOR,
            self::PARTICIPANT => AdminLinkIdentifierEnum::EITHER,

            self::ORGANIZATION_TYPE,
            self::LEAD_TYPE,
            self::LEAD_SOURCE,
            self::ATTRIBUTE,
            self::PRODUCT,
            self::PRODUCT_TYPE,
            self::PRODUCT_VARIANT,
            self::AGENT,
            self::AGENT_SWARM => AdminLinkIdentifierEnum::UUID,

            self::CATEGORY,
            self::STATUS,
            self::CHANNEL => AdminLinkIdentifierEnum::SLUG,

            default => AdminLinkIdentifierEnum::ID,
        };
    }

    /**
     * Sections exempt from the admin middleware's company-cookie gate. Anything
     * else silently bounces to the project dashboard when the recipient has no
     * company selected.
     */
    public function requiresCompany(): bool
    {
        return ! in_array(
            $this,
            [
                self::DASHBOARD,
                self::SETTINGS,
                self::USER,
                self::ROLE,
                self::COMPANY,
                self::SUBSCRIPTION_PLAN,
                self::EMAIL_TEMPLATE,
            ],
            true
        );
    }

    /**
     * Section the `allowed_sections` cookie is checked against. Null means the
     * admin does not gate this route.
     */
    public function sectionPermission(): ?string
    {
        return match ($this) {
            self::USER,
            self::ROLE,
            self::COMPANY,
            self::SUBSCRIPTION_PLAN,
            self::EMAIL_TEMPLATE => 'Ecosystem',

            self::LEAD,
            self::DEAL,
            self::PEOPLE,
            self::ORGANIZATION,
            self::ORGANIZATION_TYPE,
            self::LEAD_TYPE,
            self::LEAD_SOURCE,
            self::RECEIVER,
            self::ROTATION,
            self::PIPELINE,
            self::PEOPLE_RELATIONSHIP => 'CRM',

            self::ORDER,
            self::DRAFT_ORDER,
            self::DISCOUNT,
            self::ORDER_STATUS,
            self::ORDER_TYPE,
            self::AFFILIATE,
            self::AFFILIATE_PROGRAM => 'Commerce',

            self::PRODUCT,
            self::PRODUCT_VARIANT,
            self::PRODUCT_TYPE,
            self::CATEGORY,
            self::ATTRIBUTE,
            self::STATUS,
            self::REGION,
            self::WAREHOUSE,
            self::CHANNEL => 'Inventory',

            self::AGENT,
            self::AGENT_SWARM,
            self::AGENT_PROJECT,
            self::LLM_CONFIG,
            self::MACHINE => 'AI',

            self::ACTION,
            self::COMPANY_ACTION,
            self::ACTION_PIPELINE,
            self::CHECKLIST => 'ActionEngine',

            self::MESSAGE,
            self::MESSAGE_TYPE => 'Social',

            default => null,
        };
    }

    /**
     * Console deployments serve Control Center only, so these are the only
     * sections that survive on a console host.
     */
    public function isControlCenter(): bool
    {
        return str_starts_with($this->value, self::CONTROL_CENTER->value);
    }
}
