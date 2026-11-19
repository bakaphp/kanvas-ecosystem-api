<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Enums;

use Kanvas\Intelligence\Agents\Neuron\CRM\SalesManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;

/**
 * Who works a meeting routed to a lead or an organization, keyed by the company setting that names
 * the agent. The manager-facing Sales Manager takes leads, never the customer-facing SalesAgent: a
 * transcript is internal work. Projects already carry their own PM.
 */
enum MeetingAgentEnum: string
{
    case LEAD = 'meeting_lead_agent_id';
    case ORGANIZATION = 'meeting_organization_agent_id';

    /**
     * @return class-string
     */
    public function handler(): string
    {
        return match ($this) {
            self::LEAD => SalesManagerAgent::class,
            self::ORGANIZATION => ProjectManagerAgent::class,
        };
    }

    public static function forTarget(RoutingTargetTypeEnum $type): ?self
    {
        return match ($type) {
            RoutingTargetTypeEnum::LEAD => self::LEAD,
            RoutingTargetTypeEnum::ORGANIZATION => self::ORGANIZATION,
            RoutingTargetTypeEnum::PROJECT => null,
        };
    }

    public static function forHandler(?string $handler): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->handler() === $handler) {
                return $case;
            }
        }

        return null;
    }
}
