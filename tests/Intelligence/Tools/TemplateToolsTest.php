<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\CreateTemplateTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\DeleteTemplateTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\GenerateTemplatePdfTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\GetTemplateTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\ListTemplatesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\UpdateTemplateTool;
use Kanvas\Templates\Models\Templates;
use Tests\TestCase;

class TemplateToolsTest extends TestCase
{
    public function testCreateTemplate(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $name = 'tpl-' . uniqid();

        $result = new CreateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(name: $name, html: '<h1>{{ $entity->id }}</h1>');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['success']);

        $template = Templates::getByIdFromCompanyApp((int) $result['template_id'], $company, $app);
        $this->assertSame($name, $template->name);
        $this->assertSame($user->getId(), (int) $template->users_id);
    }

    public function testCreateDuplicateNameReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $name = 'tpl-' . uniqid();

        $tool = new CreateTemplateTool()->withContext($app, $company, $user);
        $tool->__invoke(name: $name, html: '<p>first</p>');
        $second = $tool->__invoke(name: $name, html: '<p>second</p>');

        $this->assertArrayHasKey('error', $second);
    }

    public function testUpdateOwnTemplateChangesHtml(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(name: 'tpl-' . uniqid(), html: '<p>ugly</p>');

        $result = new UpdateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: (int) $created['template_id'], html: '<p>pretty</p>');

        $this->assertArrayNotHasKey('error', $result);
        $template = Templates::getByIdFromCompanyApp((int) $created['template_id'], $company, $app);
        $this->assertSame('<p>pretty</p>', $template->template);
    }

    public function testUpdateNotOwnedTemplateReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $foreign = $this->makeForeignTemplate($app, $company, $user->getId() + 1);

        $result = new UpdateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: $foreign->getId(), html: '<p>hijack</p>');

        $this->assertArrayHasKey('error', $result);
        $foreign->refresh();
        $this->assertSame('<p>foreign</p>', $foreign->template);
    }

    /**
     * The refusal has to read as a refusal. An agent was told it could not edit template it did not
     * own and reported back to the user that it had updated it — the payload carried an `error` and
     * nothing else, so the model narrated the happy path.
     */
    public function testUpdateNotOwnedTemplateIsFlaggedAsFailedNotSilent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $foreign = $this->makeForeignTemplate($app, $company, $user->getId() + 1);

        $result = new UpdateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: $foreign->getId(), html: '<p>hijack</p>');

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $result['outcome']);
        $this->assertStringContainsString('NOT updated', $result['note']);
    }

    public function testUpdateOwnTemplateReportsOkOutcome(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(name: 'tpl-' . uniqid(), html: '<p>before</p>');

        $result = new UpdateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: (int) $created['template_id'], html: '<p>after</p>');

        $this->assertTrue($result['success']);
        $this->assertSame(ToolOutcomeEnum::OK->value, $result['outcome']);
    }

    public function testDuplicateCreateIsFlaggedAsFailedNoop(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $name = 'tpl-' . uniqid();

        $tool = new CreateTemplateTool()->withContext($app, $company, $user);
        $tool->__invoke(name: $name, html: '<p>first</p>');
        $second = $tool->__invoke(name: $name, html: '<p>second</p>');

        $this->assertFalse($second['success']);
        $this->assertSame(ToolOutcomeEnum::NOOP->value, $second['outcome']);
    }

    public function testDeleteSystemTemplateIsFlaggedAsDenied(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $system = $this->makeForeignTemplate($app, $company, $user->getId(), isSystem: true);

        $result = new DeleteTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: $system->getId());

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $result['outcome']);
        $system->refresh();
        $this->assertFalse((bool) $system->is_deleted);
    }

    public function testDeleteOwnTemplateSoftDeletes(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(name: 'tpl-' . uniqid(), html: '<p>bye</p>');

        $result = new DeleteTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: (int) $created['template_id']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue((bool) Templates::find($created['template_id'])->is_deleted);
    }

    public function testDeleteNotOwnedTemplateReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $foreign = $this->makeForeignTemplate($app, $company, $user->getId() + 1);

        $result = new DeleteTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: $foreign->getId());

        $this->assertArrayHasKey('error', $result);
        $foreign->refresh();
        $this->assertFalse((bool) $foreign->is_deleted);
    }

    public function testDeleteSystemTemplateReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $system = $this->makeForeignTemplate($app, $company, $user->getId(), isSystem: true);

        $result = new DeleteTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: $system->getId());

        $this->assertArrayHasKey('error', $result);
    }

    public function testListTemplatesShowsCompanyVisibleWithOwnedFlag(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $prefix = 'tlist' . uniqid();

        new CreateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(name: $prefix . 'mine', html: '<p>mine</p>');
        $this->makeForeignTemplate($app, $company, $user->getId() + 1, name: $prefix . 'foreign');

        $result = new ListTemplatesTool()
            ->withContext($app, $company, $user)
            ->__invoke(search: $prefix);

        $this->assertTrue($result['success']);
        $byName = collect($result['templates'])->keyBy('name');
        $this->assertTrue($byName[$prefix . 'mine']['owned']);
        $this->assertFalse($byName[$prefix . 'foreign']['owned']);
    }

    public function testGetTemplateReturnsHtmlBody(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(name: 'tget-' . uniqid(), html: '<p>body-xyz</p>');

        $result = new GetTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: (int) $created['template_id']);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('<p>body-xyz</p>', $result['html']);
        $this->assertTrue($result['owned']);
    }

    /**
     * The refusal is loud, but it still arrives after the agent has committed to the edit in front of
     * the user. The read that surfaces `owned: false` has to say what that flag costs.
     */
    public function testGetNotOwnedTemplateWarnsItCannotBeEdited(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $foreign = $this->makeForeignTemplate($app, $company, $user->getId() + 1);

        $result = new GetTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: $foreign->getId());

        $this->assertFalse($result['owned']);
        $this->assertStringContainsString('WILL be refused', $result['note']);
        $this->assertStringContainsString('Never promise the user an edit', $result['note']);
    }

    public function testGetOwnedTemplateCarriesNoRefusalWarning(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(name: 'tpl-' . uniqid(), html: '<p>mine</p>');

        $result = new GetTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: (int) $created['template_id']);

        $this->assertTrue($result['owned']);
        $this->assertStringNotContainsString('WILL be refused', $result['note']);
    }

    public function testListWarnsWhenAnyTemplateIsNotEditable(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $prefix = 'twarn' . uniqid();

        $this->makeForeignTemplate($app, $company, $user->getId() + 1, name: $prefix . 'foreign');

        $result = new ListTemplatesTool()
            ->withContext($app, $company, $user)
            ->__invoke(search: $prefix);

        $this->assertStringContainsString('WILL be refused', $result['note']);
    }

    public function testGetUnknownTemplateReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $result = new GetTemplateTool()
            ->withContext($app, $company, $user)
            ->__invoke(template_id: 999999999);

        $this->assertArrayHasKey('error', $result);
    }

    public function testGeneratePdfWithoutEntityReturnsError(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $result = new GenerateTemplatePdfTool()
            ->withContext($app, $company, $user)
            ->withEntity(null)
            ->__invoke(template_name: 'whatever');

        $this->assertArrayHasKey('error', $result);
    }

    private function makeForeignTemplate(
        Apps $app,
        Companies $company,
        int $usersId,
        bool $isSystem = false,
        ?string $name = null
    ): Templates {
        return Templates::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $usersId,
            'name' => $name ?? 'tpl-' . uniqid(),
            'template' => '<p>foreign</p>',
            'parent_template_id' => 0,
            'is_system' => $isSystem,
        ]);
    }
}
