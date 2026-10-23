<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Templates;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Enums\TemplateEngineEnum;
use Kanvas\Templates\Models\Templates;
use Tests\TestCase;
use Tests\Traits\MakesTemplates;

final class TemplateEngineTest extends TestCase
{
    use DatabaseTransactions;
    use MakesTemplates;

    protected $connectionsToTransact = [null, 'ecosystem'];

    public function testNewTemplatesAreTwig(): void
    {
        $template = $this->makeTemplate('<p>{{ name }}</p>');

        $this->assertSame(TemplateEngineEnum::TWIG, $template->refresh()->engine);
    }

    public function testATemplateCannotBeCreatedAsBlade(): void
    {
        $this->expectException(ValidationException::class);

        Templates::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => 0,
            'users_id' => 0,
            'name' => 'blade-' . uniqid(),
            'template' => '<p>x</p>',
            'engine' => TemplateEngineEnum::BLADE,
        ]);
    }

    public function testABladeRowCannotBeChangedOrDeletedThroughTheModel(): void
    {
        $template = $this->makeHandMadeBladeTemplate('<p>{{ $name }}</p>');

        foreach ([
            fn () => $template->update(['template' => '@php echo 1; @endphp']),
            fn () => $template->update(['engine' => TemplateEngineEnum::TWIG]),
            fn () => $template->softDelete(),
            fn () => $template->delete(),
        ] as $write) {
            try {
                $write();
                $this->fail('A blade row was written through the model.');
            } catch (ValidationException) {
                $template->refresh();
            }
        }

        $this->assertSame('<p>{{ $name }}</p>', $template->template);
        $this->assertSame(0, (int) $template->is_deleted);
    }

    public function testCreatingOverABladeRowLeavesItUntouched(): void
    {
        $template = $this->makeHandMadeBladeTemplate('<p>original</p>');

        $returned = new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => $template->name,
                'template' => '<p>replaced</p>',
            ])
        )->execute();

        $this->assertSame($template->getId(), $returned->getId());
        $this->assertSame('<p>original</p>', $template->refresh()->template);
    }

    public function testATwigTemplateTheSandboxRefusesIsNotSaved(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeTemplate("{{ ['id']|map('system')|join }}");
    }

    public function testATwigTemplateCannotUseTheRangeOperator(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeTemplate('{% for i in 1..100000000 %}x{% endfor %}');
    }

    public function testBladeSyntaxInATwigTemplateIsNeverExecuted(): void
    {
        $template = $this->makeTemplate("@php echo 'PW' . 'NED'; @endphp {{ \$name }}");

        $rendered = new RenderTemplateAction(app(Apps::class))->execute($template->name, ['name' => 'Ana']);

        $this->assertStringNotContainsString('PWNED', $rendered);
        $this->assertStringContainsString('Ana', $rendered);
    }

    public function testATwigChildIsNotCompiledByItsBladeParent(): void
    {
        $parent = $this->makeHandMadeBladeTemplate('<html>{{ $greeting }} [body]</html>');
        $child = $this->makeTemplate('<p>{{ name }}</p>');
        $child->addParentTemplate($parent);

        $rendered = new RenderTemplateAction(app(Apps::class))->execute(
            $child->name,
            [
                'greeting' => 'Hello',
                'name' => "@php echo 'PW' . 'NED'; @endphp",
            ]
        );

        $this->assertStringContainsString('<html>Hello <p>', $rendered);
        $this->assertStringNotContainsString('PWNED', $rendered);
    }

    public function testAParentFromAnotherCompanyIsRejected(): void
    {
        $otherCompany = Companies::factory()->create();
        $foreign = $this->makeTemplate('<p>[body]</p>', $otherCompany);
        $own = $this->makeTemplate('<p>own</p>', auth()->user()->getCurrentCompany());

        $this->expectException(ValidationException::class);

        $own->addParentTemplate($foreign);
    }

    public function testTemplatesSeeOnlyTheUserFieldsExposedToThem(): void
    {
        $user = auth()->user();
        $user->user_recover_code = 'secret-recover-code';

        $rendered = new RenderTemplateAction(app(Apps::class))->execute(
            'unused',
            ['user' => $user],
            '{{ user.firstname }}|{{ user.user_recover_code }}|{{ user.password }}'
        );

        $this->assertSame(htmlspecialchars($user->firstname, ENT_QUOTES) . '||', $rendered);
    }
}
