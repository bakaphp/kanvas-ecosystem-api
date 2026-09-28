<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasFileUploadToolSchema;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use Kanvas\Inventory\Products\Models\Products;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

/**
 * Laravel-AI counterpart of the Neuron upload_file_to_product tool — same body via
 * AttachesFileToEntity, same admin gate on the catalog write.
 */
#[AgentTool(name: 'Upload File To Product', category: 'inventory')]
class UploadFileToProductTool implements KanvasToolInterface
{
    use AttachesFileToEntity;
    use HasFileUploadToolSchema;
    use HasKanvasContext;

    public function name(): string
    {
        return 'upload_file_to_product';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Attach a document or image to a product — a spec sheet, manual, warranty terms, datasheet or '
            . 'product photo. Normally you pass content — the full text of the document you wrote — plus a '
            . 'file_name ending in .md, .txt, .csv or .json. Pass file_url instead when the file or image already '
            . 'exists at a public URL. To attach to one specific variant instead of the whole product, use '
            . 'upload_file_to_variant. Only an administrator can do this.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $user = $this->contextUser();

        if ($user === null || ! $user->isAdmin()) {
            return 'Only an administrator can attach files to the catalog.';
        }

        $productId = $request->integer('product_id');

        try {
            /** @var Products $product */
            $product = Products::getByIdFromCompanyApp($productId, $this->company, $this->app);
        } catch (Throwable) {
            return "Product {$productId} does not exist in this company. Use a product search to get a real "
                . 'product_id.';
        }

        return $this->uploadFromRequest(
            $request,
            $product,
            'product',
            ['product_id' => $productId],
        );
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema
                ->integer()
                ->description('The ID of the product to attach the file to.')
                ->required(),
            ...$this->fileUploadSchema($schema, 'spec-sheet.md'),
        ];
    }
}
