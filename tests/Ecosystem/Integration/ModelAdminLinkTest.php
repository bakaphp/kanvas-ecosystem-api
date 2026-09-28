<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration;

use Illuminate\Database\Eloquent\Model;
use Kanvas\AdminLinks\Enums\AdminLinkIdentifierEnum;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Services\AdminLinkService;
use Kanvas\AdminLinks\Traits\HasAdminLink;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ModelAdminLinkTest extends TestCase
{
    private const HOST = 'https://admin.kanvas.dev';
    private const UUID = '9f1c2d3e-0000-4a5b-8c9d-1e2f3a4b5c6d';

    /**
     * @return array<string, array{class-string<Model>, AdminLinkSectionEnum}>
     */
    public static function linkedModels(): array
    {
        return [
            'lead' => [Lead::class, AdminLinkSectionEnum::LEAD],
            'deal' => [Deal::class, AdminLinkSectionEnum::DEAL],
            'people' => [People::class, AdminLinkSectionEnum::PEOPLE],
            'organization' => [Organization::class, AdminLinkSectionEnum::ORGANIZATION],
            'product' => [Products::class, AdminLinkSectionEnum::PRODUCT],
            'variant' => [Variants::class, AdminLinkSectionEnum::PRODUCT_VARIANT],
            'order' => [Order::class, AdminLinkSectionEnum::ORDER],
            'project' => [Project::class, AdminLinkSectionEnum::AGENT_PROJECT],
            'agent' => [Agent::class, AdminLinkSectionEnum::AGENT],
            'agent_swarm' => [AgentSwarm::class, AdminLinkSectionEnum::AGENT_SWARM],
            'workflow_receiver' => [ReceiverWebhook::class, AdminLinkSectionEnum::WORKFLOW_RECEIVER],
            'rule' => [Rule::class, AdminLinkSectionEnum::RULE],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kanvas.app.frontend_url', self::HOST);
    }

    /**
     * @param class-string<Model> $modelClass
     */
    #[DataProvider('linkedModels')]
    public function testEachLinkedModelBuildsItsOwnUrl(string $modelClass, AdminLinkSectionEnum $section): void
    {
        $app = app(Apps::class);

        $model = new $modelClass();
        $this->assertContains(
            HasAdminLink::class,
            class_uses_recursive($model),
            $modelClass . ' does not use HasAdminLink.'
        );
        $this->assertSame($section, $model->adminLinkSection());

        $model->forceFill(['id' => 42, 'uuid' => self::UUID, 'slug' => 'a-slug']);
        $model->setRelation('app', $app);

        $expected = $section->identifier() === AdminLinkIdentifierEnum::ID ? '42' : self::UUID;

        $this->assertSame(
            self::HOST . '/projects/' . rawurlencode($app->key) . '/' . $section->segment() . '/' . $expected,
            $model->adminUrl()
        );
    }

    /**
     * An app configured with ADMIN_URL used to read back as "not configured", so the agent told the
     * user the platform could not build links with the value sitting right there in apps_settings.
     */
    public function testAnUppercaseSettingKeyStillResolves(): void
    {
        config()->set('kanvas.app.frontend_url', null);

        $app = app(Apps::class);
        $key = AppSettingsEnums::ADMIN_URL->getValue();
        $app->set(strtoupper($key), 'https://legacy.example.com');

        try {
            $this->assertSame(
                'https://legacy.example.com',
                new AdminLinkService()->resolveHost($app)
            );
        } finally {
            $app->del(strtoupper($key));
        }
    }
}
