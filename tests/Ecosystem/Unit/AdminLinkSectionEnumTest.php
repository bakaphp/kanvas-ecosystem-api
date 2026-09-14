<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Unit;

use Kanvas\AdminLinks\Enums\AdminLinkIdentifierEnum;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Tests\TestCaseUnit;

final class AdminLinkSectionEnumTest extends TestCaseUnit
{
    public function testLeadsAcceptEitherIdentifierAndResolveToUuid(): void
    {
        $this->assertSame(
            AdminLinkIdentifierEnum::EITHER,
            AdminLinkSectionEnum::LEAD->identifier()
        );

        $this->assertTrue(
            AdminLinkSectionEnum::LEAD->identifier()->matches('9f1c2d3e-0000-4a5b-8c9d-1e2f3a4b5c6d')
        );
    }

    public function testUuidOnlyRoutesRejectNumericIdentifiers(): void
    {
        $this->assertSame(
            AdminLinkIdentifierEnum::UUID,
            AdminLinkSectionEnum::AGENT->identifier()
        );

        $this->assertFalse(AdminLinkSectionEnum::AGENT->identifier()->matches('42'));
    }

    public function testNumericRoutesRejectUuids(): void
    {
        $this->assertSame(
            AdminLinkIdentifierEnum::ID,
            AdminLinkSectionEnum::ORDER->identifier()
        );

        $this->assertTrue(AdminLinkSectionEnum::ORDER->identifier()->matches('42'));
        $this->assertFalse(
            AdminLinkSectionEnum::ORDER->identifier()->matches('9f1c2d3e-0000-4a5b-8c9d-1e2f3a4b5c6d')
        );
    }

    /**
     * Pinned against real admin URLs, not the handoff spec — the spec documented the product detail
     * route as taking `<id>` when it actually keys on the uuid, and a wrong rule here produces a
     * working link to nothing. ModelAdminLinkTest derives its expectation from this enum, so it
     * mirrors a bad mapping instead of catching it; these are the literals.
     */
    public function testIdentifierRulesArePinnedToRealAdminRoutes(): void
    {
        foreach ([
            'product' => AdminLinkIdentifierEnum::UUID,
            'product_variant' => AdminLinkIdentifierEnum::UUID,
            'product_type' => AdminLinkIdentifierEnum::UUID,
            'attribute' => AdminLinkIdentifierEnum::UUID,
            'agent' => AdminLinkIdentifierEnum::UUID,
            'agent_swarm' => AdminLinkIdentifierEnum::UUID,
            'lead' => AdminLinkIdentifierEnum::EITHER,
            'deal' => AdminLinkIdentifierEnum::EITHER,
            'people' => AdminLinkIdentifierEnum::EITHER,
            'category' => AdminLinkIdentifierEnum::SLUG,
            'status' => AdminLinkIdentifierEnum::SLUG,
            'channel' => AdminLinkIdentifierEnum::SLUG,
            'order' => AdminLinkIdentifierEnum::ID,
            'organization' => AdminLinkIdentifierEnum::ID,
            'agent_project' => AdminLinkIdentifierEnum::ID,
            'rule' => AdminLinkIdentifierEnum::ID,
            'workflow_receiver' => AdminLinkIdentifierEnum::ID,
            'message' => AdminLinkIdentifierEnum::ID,
        ] as $alias => $expected) {
            $section = AdminLinkSectionEnum::tryFromAlias($alias);

            $this->assertNotNull($section, $alias . ' is not a known section.');
            $this->assertSame($expected, $section->identifier(), $alias . ' has the wrong identifier rule.');
        }
    }

    public function testSlugRoutes(): void
    {
        $this->assertSame(
            AdminLinkIdentifierEnum::SLUG,
            AdminLinkSectionEnum::CATEGORY->identifier()
        );

        $this->assertTrue(AdminLinkSectionEnum::CATEGORY->identifier()->matches('summer-gear'));
    }

    public function testCompanyGateMatchesTheAdminMiddlewareExemptions(): void
    {
        foreach ([
            AdminLinkSectionEnum::DASHBOARD,
            AdminLinkSectionEnum::SETTINGS,
            AdminLinkSectionEnum::USER,
            AdminLinkSectionEnum::ROLE,
            AdminLinkSectionEnum::COMPANY,
            AdminLinkSectionEnum::SUBSCRIPTION_PLAN,
            AdminLinkSectionEnum::EMAIL_TEMPLATE,
        ] as $section) {
            $this->assertFalse($section->requiresCompany(), $section->value . ' should be exempt');
        }

        $this->assertTrue(AdminLinkSectionEnum::LEAD->requiresCompany());
        $this->assertTrue(AdminLinkSectionEnum::ORDER->requiresCompany());
    }

    public function testSectionPermissions(): void
    {
        $this->assertSame('CRM', AdminLinkSectionEnum::LEAD->sectionPermission());
        $this->assertSame('Commerce', AdminLinkSectionEnum::ORDER->sectionPermission());
        $this->assertSame('Inventory', AdminLinkSectionEnum::PRODUCT->sectionPermission());
        $this->assertSame('AI', AdminLinkSectionEnum::AGENT->sectionPermission());
        $this->assertSame('ActionEngine', AdminLinkSectionEnum::CHECKLIST->sectionPermission());
        $this->assertSame('Social', AdminLinkSectionEnum::MESSAGE->sectionPermission());
        $this->assertSame('Ecosystem', AdminLinkSectionEnum::USER->sectionPermission());
    }

    public function testUngatedSectionsHaveNoPermission(): void
    {
        $this->assertNull(AdminLinkSectionEnum::WORKFLOW->sectionPermission());
        $this->assertNull(AdminLinkSectionEnum::EVENT->sectionPermission());
        $this->assertNull(AdminLinkSectionEnum::MAPPER->sectionPermission());
    }

    /**
     * The mapper list hangs off a different path than a mapper detail, so dropping the identifier off
     * the detail segment lands on nothing.
     */
    public function testListScreensThatLiveOnADifferentPathThanTheirDetailRoute(): void
    {
        $this->assertSame('mapper/list', AdminLinkSectionEnum::MAPPER->listSegment());
        $this->assertSame('mapper', AdminLinkSectionEnum::MAPPER->segment());

        $this->assertSame(
            AdminLinkSectionEnum::LEAD->segment(),
            AdminLinkSectionEnum::LEAD->listSegment(),
            'Sections without a separate list path must not diverge.'
        );
    }

    public function testControlCenterDetection(): void
    {
        $this->assertTrue(AdminLinkSectionEnum::CONTROL_CENTER->isControlCenter());
        $this->assertTrue(AdminLinkSectionEnum::CONTROL_CENTER_CHAT->isControlCenter());
        $this->assertFalse(AdminLinkSectionEnum::LEAD->isControlCenter());
    }
}
