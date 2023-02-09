<?php
declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Interactions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Enums\StateEnums;
use Kanvas\Social\Interactions\Actions\CreateEntityInteraction;
use Kanvas\Social\Interactions\DataTransferObject\LikeEntityInput;
use Kanvas\Social\Interactions\Models\EntityInteractions as ModelsEntityInteractions;

class EntityInteractions
{
    /**
     * Like a entity.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function likeEntity(mixed $root, array $req) : bool
    {
        $likeEntityInput = LikeEntityInput::from($req['input']);
        $createEntityInteraction = new CreateEntityInteraction(
            $likeEntityInput,
            app(Apps::class)
        );

        return $createEntityInteraction->execute(
            (string) StateEnums::LIKE->getValue()
        ) instanceof ModelsEntityInteractions;
    }

    /**
     * Like a entity.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function unLikeEntity(mixed $root, array $req) : bool
    {
        $likeEntityInput = LikeEntityInput::from($req['input']);
        $createEntityInteraction = new CreateEntityInteraction(
            $likeEntityInput,
            app(Apps::class)
        );

        return $createEntityInteraction->execute(
            (string) StateEnums::LIKE->getValue()
        )->softDelete();
    }

    /**
     * Like a entity.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function disLikeEntity(mixed $root, array $req) : bool
    {
        $likeEntityInput = LikeEntityInput::from($req['input']);
        $createEntityInteraction = new CreateEntityInteraction(
            $likeEntityInput,
            app(Apps::class)
        );

        return $createEntityInteraction->execute(
            (string)  StateEnums::DISLIKE->getValue()
        ) instanceof ModelsEntityInteractions;
    }
}
