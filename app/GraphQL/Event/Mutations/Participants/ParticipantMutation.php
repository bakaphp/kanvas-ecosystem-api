<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Participants;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\People;

class ParticipantMutation
{
    public function create(mixed $root, array $req): Participant
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var People $people */
        $people = People::getByIdFromCompanyApp((int) $input['people_id'], $company, $app);

        if (! empty($input['theme_area_id'])) {
            $themeArea = ThemeArea::getByIdFromCompanyApp((int) $input['theme_area_id'], $company, $app);
        } else {
            $themeArea = ThemeArea::fromApp($app)
                ->fromCompany($company)
                ->where('is_default', 1)
                ->first()
                ?? ThemeArea::fromApp($app)->fromCompany($company)->firstOrFail();
        }

        $participant = new Participant();
        $participant->apps_id = $app->getId();
        $participant->companies_id = $company->getId();
        $participant->users_id = $user->getId();
        $participant->people_id = $people->getId();
        $participant->theme_area_id = $themeArea->getId();
        $participant->participant_status_id = 1;
        $participant->is_prospect = array_key_exists('is_prospect', $input) ? (string) (int) (bool) $input['is_prospect'] : '0';
        $participant->general_representative = $input['general_representative'] ?? null;
        $participant->saveOrFail();

        self::syncCustomFields($participant, $input);
        self::syncTags($participant, $input);
        self::syncFiles($participant, $input);

        return $participant->fresh();
    }

    public function update(mixed $root, array $req): Participant
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var Participant $participant */
        $participant = Participant::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        if (array_key_exists('people_id', $input) && $input['people_id']) {
            $people = People::getByIdFromCompanyApp((int) $input['people_id'], $company, $app);
            $participant->people_id = $people->getId();
        }

        if (array_key_exists('theme_area_id', $input)) {
            $participant->theme_area_id = $input['theme_area_id']
                ? ThemeArea::getByIdFromCompanyApp((int) $input['theme_area_id'], $company, $app)->getId()
                : null;
        }

        if (array_key_exists('is_prospect', $input)) {
            $participant->is_prospect = (string) (int) (bool) $input['is_prospect'];
        }

        if (array_key_exists('general_representative', $input)) {
            $participant->general_representative = $input['general_representative'];
        }

        $participant->saveOrFail();

        self::syncCustomFields($participant, $input);
        self::syncTags($participant, $input);
        self::syncFiles($participant, $input);

        return $participant->fresh();
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var Participant $participant */
        $participant = Participant::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        return (bool) $participant->delete();
    }

    protected static function syncCustomFields(Participant $participant, array $input): void
    {
        if (! empty($input['custom_fields']) && is_array($input['custom_fields'])) {
            $participant->setAllCustomFields($input['custom_fields']);
        }
    }

    protected static function syncTags(Participant $participant, array $input): void
    {
        if (array_key_exists('tags', $input) && is_array($input['tags'])) {
            $tagNames = [];
            foreach ($input['tags'] as $tag) {
                if (is_array($tag) && isset($tag['name'])) {
                    $tagNames[] = (string) $tag['name'];
                } elseif (is_string($tag)) {
                    $tagNames[] = $tag;
                }
            }
            if (! empty($tagNames)) {
                $participant->syncTags($tagNames);
            }
        }
    }

    protected static function syncFiles(Participant $participant, array $input): void
    {
        if (! empty($input['files']) && is_array($input['files'])) {
            $participant->addMultipleFilesFromUrl($input['files']);
        }
    }
}
