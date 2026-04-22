<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Exception;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;

use function Laravel\Ai\agent;

class ModelWizardModelChooserAction
{
    private const LLM_MODELS_PRODUCT_TYPE_SLUG = 'pickamodel';

    public function __construct(
        private array $modelWizardAnswers,
        protected Users $user,
        protected Apps $app
    ) {
    }

    public function execute(): array
    {
        $modelWizardAnswers = $this->modelWizardAnswers;

        if (empty($modelWizardAnswers)) {
            throw new InvalidArgumentException('Model wizard answers are required to execute the model chooser action.');
        }

        $productTypes = ProductsTypesRepository::getBySlug(self::LLM_MODELS_PRODUCT_TYPE_SLUG);

        $availableModels = Products::fromApp($this->app)
            ->where('products_types_id', $productTypes->getId())
            ->with(['variants', 'categories',])
            ->get()
            ->flatMap(fn ($product) => $product->variants->map(fn ($variant) => [
                'name'       => $variant->name,
                'slug'       => $variant->slug,
                'sku'        => $variant->sku,
                'type' => current($product->categories->pluck('slug')->toArray()),
            ]))
            ->toArray();

        $prompt = "Based on the following user data: " . json_encode($modelWizardAnswers) . " and the following available models: "
            . json_encode($availableModels)
            . ", identify the best AI model for this user. Return the sku of the model only.Take into account the type of model as well. 
            If the user data indicates that they need a text-based model, prioritize models with type 'text'. 
            If the user data indicates that they need an image-based model, prioritize models with type 'image'. 
            If the user data does not indicate a preference, choose the best overall model based on the user data. ASNWER WITH THE SKU OF THE CHOSEN MODEL ONLY.";

        try {
            $chosenModelSku = agent()->prompt(
                $prompt,
                provider: Lab::Gemini,
                model: 'gemini-2.5-flash',
            );
        } catch (Exception $e) {
            throw new AiException('Error while identifying the best model for the user: ' . $e->getMessage());
        }

        return [
            'message' => 'Model wizard completed successfully. Chosen model: ' . $chosenModelSku->text,
            'result' => true,
            'chosen_model_sku' => $chosenModelSku->text,
            'users_id' => $this->user->getId(),
        ];
    }
}
