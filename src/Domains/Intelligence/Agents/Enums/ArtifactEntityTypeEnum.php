<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

use BackedEnum;
use Kanvas\AdminLinks\Enums\AdminLinkIdentifierEnum;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\Souk\Affiliates\Enums\AffiliateStatusEnum;
use Kanvas\Souk\Affiliates\Enums\AffiliateTypeEnum;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;

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

    /**
     * The longest a text filter runs. The list matches it exactly, so it is a value, never a paragraph.
     */
    public const int MAX_FILTER_TEXT = 80;

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
     * What the card can find the record by. Wider than what its page reads: the card reads the row and
     * links with the identifier the row carries, so an order is found by its uuid though its page
     * opens by id, and a product by the numeric id every tool returns though its page wants the uuid.
     *
     * A slug-routed type is found by the slug alone. A project's uuid is the secret of its webhook, so
     * no card is given it: the tool turns one it is handed into the id before the block exists.
     *
     * @return list<AdminLinkIdentifierEnum>
     */
    public function lookups(): array
    {
        return match ($this) {
            self::CATEGORY,
            self::CHANNEL => [AdminLinkIdentifierEnum::SLUG],
            self::PIPELINE,
            self::ROTATION,
            self::WAREHOUSE,
            self::AGENT_PROJECT,
            self::ROLE,
            self::SUBSCRIPTION_PLAN,
            self::EMAIL_TEMPLATE => [AdminLinkIdentifierEnum::ID],
            default => [AdminLinkIdentifierEnum::ID, AdminLinkIdentifierEnum::UUID],
        };
    }

    /**
     * Whether a list of this type takes a free-text search. The API answers a search on these lists
     * with an error, so the admin refuses to run one.
     */
    public function searchable(): bool
    {
        return ! in_array(
            $this,
            [self::DRAFT_ORDER, self::PARTICIPANT, self::FACILITATOR, self::AGENT_PROJECT],
            true
        );
    }

    /**
     * The filters a live list of this type takes, as prop rules, or null when the type has no list.
     * A rule marked `many` also takes a list of values.
     *
     * The values of a status filter are the cases of the domain's own enum, never a copy: the contract
     * test then fails when either the admin or the domain moves.
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
                'status' => self::anyOf(OrderStatusEnum::cases()),
                'fulfillmentStatus' => self::anyOf(OrderFulfillmentStatusEnum::cases()),
                'orderTypeId' => self::ids(),
                'personId' => self::id(),
            ],
            self::DRAFT_ORDER => ['personId' => self::id()],
            self::DISCOUNT => ['active' => self::flag(), 'code' => self::text()],
            self::AFFILIATE => [
                'status' => self::anyOf(AffiliateStatusEnum::cases()),
                'affiliateType' => self::anyOf(AffiliateTypeEnum::cases()),
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
            self::AGENT_FLEET => ['status' => self::anyOf(AgentSwarmStatusEnum::cases())],
            self::AGENT_PROJECT => [
                'status' => self::anyOf(ProjectStatusEnum::cases()),
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
     * The integer id, never the uuid: the column behind every list filter is an integer one.
     *
     * @return array<string, mixed>
     */
    private static function id(): array
    {
        return ['type' => 'integer_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ids(): array
    {
        return ['type' => 'integer_id', 'many' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function flag(): array
    {
        return ['type' => 'bool'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(): array
    {
        return ['type' => 'string', 'nonEmpty' => true, 'maxLength' => self::MAX_FILTER_TEXT];
    }

    /**
     * @param list<BackedEnum> $cases
     * @return array<string, mixed>
     */
    private static function anyOf(array $cases): array
    {
        return ['type' => 'enum', 'values' => array_column($cases, 'value'), 'many' => true];
    }
}
