<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Templates;

use Kanvas\Apps\Models\Apps;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Tests\TestCase;

final class RenderTest extends TestCase
{
    public function testParentEmailTemplate()
    {
        $createParentTemplate = new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => 'parent',
                'template' => '<html><body>Body Here [body]</body></html>',
                ])
        );
        $parentTemplate = $createParentTemplate->execute();

        $childTemplate = new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => 'child',
                'template' => 'Im the kid',
                ])
        );
        $childTemplate = $childTemplate->execute();
        $childTemplate->addParentTemplate($parentTemplate);

        $renderTemplate = new RenderTemplateAction(app(Apps::class));
        $renderedTemplate = $renderTemplate->execute($childTemplate->name, []);

        $this->assertStringContainsString('Body Here Im the kid', $renderedTemplate);
    }
}
