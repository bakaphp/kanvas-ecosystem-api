<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

use Kanvas\AdminLinks\Enums\AdminLinkIdentifierEnum;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;

/**
 * The record types the admin chat draws as a live card (`entity`) or a live list (`records`).
 *
 * The frontend owns this vocabulary: which types exist, which id each one's page reads and which
 * filters a list of them takes. `tests/fixtures/admin-artifact-contract.json` is its export, and
 * `ArtifactContractTest` holds this enum to it — a type or filter the two disagree about is not an
 * error anyone sees, the block is simply stripped from the reply.
 */
enum ArtifactEntityTypeEnum: string
{
    case LEAD = 'lead';
    case DEAL = 'deal';
    case PEOPLE = 'people';
    case ORGANIZATION = 'organization';
    case PIPELINE = 'pipeline';
    case ROTATION = 'rotation';

    case ORDER = 'order';
    case DRAFT_ORDER = 'draft_order';
    case DISCOUNT = 'discount';
    case AFFILIATE = 'affiliate';
    case AFFILIATE_PROGRAM = 'affiliate_program';

    case PRODUCT = 'product';
    case VARIANT = 'variant';
    case WAREHOUSE = 'warehouse';
    case CATEGORY = 'category';
    case CHANNEL = 'channel';

    case EVENT = 'event';
    case EVENT_VERSION = 'event_version';
    case PARTICIPANT = 'participant';
    case FACILITATOR = 'facilitator';

    case AGENT = 'agent';
    case AGENT_FLEET = 'agent_fleet';
    case AGENT_PROJECT = 'agent_project';

    case COMPANY = 'company';
    case USER = 'user';
    case ROLE = 'role';
    case SUBSCRIPTION_PLAN = 'subscription_plan';
    case EMAIL_TEMPLATE = 'email_template';

    private const array AFFILIATE_STATUSES = ['pending', 'approved', 'active', 'suspended', 'inactive', 'rejected'];

    private const array AFFILIATE_TYPES = ['individual', 'business', 'influencer', 'agency'];

    private const array AGENT_FLEET_STATUSES = ['active', 'draft', 'archived'];

    private const array AGENT_PROJECT_STATUSES = [
        'draft',
        'active',
        'on_hold',
        'blocked',
        'done',
        'archived',
        'cancelled',
    ];

    private const array ORDER_STATUSES = ['pending', 'completed', 'draft', 'canceled', 'failed'];

    private const array FULFILLMENT_STATUSES = ['pending', 'fulfilled', 'canceled'];

    /**
     * The admin screen a record of this type opens on. Null for an edition: it hangs off its event
     * (`events/<event>/versions/<id>`), a route with two identifiers that no section models.
     */
    public function section(): ?AdminLinkSectionEnum
    {
        return match ($this) {
            self::LEAD => AdminLinkSectionEnum::LEAD,
            self::DEAL => AdminLinkSectionEnum::DEAL,
            self::PEOPLE => AdminLinkSectionEnum::PEOPLE,
            self::ORGANIZATION => AdminLinkSectionEnum::ORGANIZATION,
            self::PIPELINE => AdminLinkSectionEnum::PIPELINE,
            self::ROTATION => AdminLinkSectionEnum::ROTATION,
            self::ORDER => AdminLinkSectionEnum::ORDER,
            self::DRAFT_ORDER => AdminLinkSectionEnum::DRAFT_ORDER,
            self::DISCOUNT => AdminLinkSectionEnum::DISCOUNT,
            self::AFFILIATE => AdminLinkSectionEnum::AFFILIATE,
            self::AFFILIATE_PROGRAM => AdminLinkSectionEnum::AFFILIATE_PROGRAM,
            self::PRODUCT => AdminLinkSectionEnum::PRODUCT,
            self::VARIANT => AdminLinkSectionEnum::PRODUCT_VARIANT,
            self::WAREHOUSE => AdminLinkSectionEnum::WAREHOUSE,
            self::CATEGORY => AdminLinkSectionEnum::CATEGORY,
            self::CHANNEL => AdminLinkSectionEnum::CHANNEL,
            self::EVENT => AdminLinkSectionEnum::EVENT,
            self::EVENT_VERSION => null,
            self::PARTICIPANT => AdminLinkSectionEnum::PARTICIPANT,
            self::FACILITATOR => AdminLinkSectionEnum::FACILITATOR,
            self::AGENT => AdminLinkSectionEnum::AGENT,
            self::AGENT_FLEET => AdminLinkSectionEnum::AGENT_SWARM,
            self::AGENT_PROJECT => AdminLinkSectionEnum::AGENT_PROJECT,
            self::COMPANY => AdminLinkSectionEnum::COMPANY,
            self::USER => AdminLinkSectionEnum::USER,
            self::ROLE => AdminLinkSectionEnum::ROLE,
            self::SUBSCRIPTION_PLAN => AdminLinkSectionEnum::SUBSCRIPTION_PLAN,
            self::EMAIL_TEMPLATE => AdminLinkSectionEnum::EMAIL_TEMPLATE,
        };
    }

    /**
     * Which identifier the record's page reads. Asked of the route table, never restated: the card
     * refuses to link an id of the wrong kind, because a uuid compared against an integer id is a
     * cast and opens somebody else's record.
     */
    public function identifier(): AdminLinkIdentifierEnum
    {
        return $this->section()?->identifier() ?? AdminLinkIdentifierEnum::EITHER;
    }

    /**
     * The filters a live list of this type takes, as prop rules, or null when the type has no list.
     * A rule marked `many` also takes a list of values.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function filters(): ?array
    {
        return match ($this) {
            self::LEAD => [
                'statusId' => self::ids(),
                'pipelineId' => self::id(),
                'stageId' => self::ids(),
                'personId' => self::id(),
                'ownerId' => self::id(),
                'receiverId' => self::ids(),
            ],
            self::DEAL => [
                'statusId' => self::ids(),
                'pipelineId' => self::id(),
                'stageId' => self::ids(),
                'personId' => self::id(),
                'organizationId' => self::id(),
                'leadId' => self::id(),
            ],
            self::PEOPLE => ['organizationId' => self::id()],
            self::ORGANIZATION => ['ids' => self::ids()],
            self::ORDER => [
                'status' => self::anyOf(self::ORDER_STATUSES),
                'fulfillmentStatus' => self::anyOf(self::FULFILLMENT_STATUSES),
                'orderTypeId' => self::ids(),
                'personId' => self::id(),
            ],
            self::DRAFT_ORDER => ['personId' => self::id()],
            self::DISCOUNT => ['active' => self::flag(), 'code' => self::text()],
            self::AFFILIATE => [
                'status' => self::anyOf(self::AFFILIATE_STATUSES),
                'affiliateType' => self::anyOf(self::AFFILIATE_TYPES),
                'programId' => self::id(),
            ],
            self::PRODUCT => [
                'statusId' => self::ids(),
                'categoryId' => self::ids(),
                'productTypeId' => self::ids(),
                'published' => self::flag(),
            ],
            self::VARIANT => [
                'productId' => self::id(),
                'sku' => self::text(),
                'published' => self::flag(),
            ],
            self::EVENT => [
                'statusId' => self::ids(),
                'typeId' => self::ids(),
                'categoryId' => self::ids(),
            ],
            self::EVENT_VERSION => ['eventId' => self::id()],
            self::PARTICIPANT => ['prospect' => self::flag(), 'personId' => self::id()],
            self::FACILITATOR => ['personId' => self::id()],
            self::AGENT_FLEET => ['status' => self::anyOf(self::AGENT_FLEET_STATUSES)],
            self::AGENT_PROJECT => [
                'status' => self::anyOf(self::AGENT_PROJECT_STATUSES),
                'fleetId' => self::id(),
                'agentId' => self::id(),
            ],
            self::USER => ['active' => self::flag(), 'roleId' => self::ids()],
            self::EMAIL_TEMPLATE => ['system' => self::flag()],
            self::AFFILIATE_PROGRAM,
            self::AGENT,
            self::COMPANY,
            self::SUBSCRIPTION_PLAN => ['active' => self::flag()],
            self::PIPELINE,
            self::ROTATION,
            self::ROLE => [],
            self::WAREHOUSE,
            self::CATEGORY,
            self::CHANNEL => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function listable(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->filters() !== null)
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function id(): array
    {
        return ['type' => 'record_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ids(): array
    {
        return ['type' => 'record_id', 'many' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function flag(): array
    {
        return ['type' => 'bool'];
    }

    /**
     * Matched exactly by the list, so it is a short value and never a paragraph.
     *
     * @return array<string, mixed>
     */
    private static function text(): array
    {
        return ['type' => 'string', 'nonEmpty' => true, 'maxLength' => 80];
    }

    /**
     * @param list<string> $values
     * @return array<string, mixed>
     */
    private static function anyOf(array $values): array
    {
        return ['type' => 'enum', 'values' => $values, 'many' => true];
    }
}
