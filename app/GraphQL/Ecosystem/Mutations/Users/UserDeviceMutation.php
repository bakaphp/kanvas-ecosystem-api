<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Kanvas\Users\Models\Sources;
use Kanvas\Users\Models\UserLinkedSources;

class UserDeviceMutation
{
    /**
     * changePassword.
     */
    public function register(mixed $root, array $req): bool
    {
        $req = $req['data'];
        $source = Sources::where('title', $req['source_site'])->firstOrFail();
        $user = auth()->user();

        $result = UserLinkedSources::firstOrNew([
            'users_id' => $user->getId(),
            'source_id' => $source->getId(),
            'source_users_id_text' => $req['device_id'],
        ]);

        // Update the attributes explicitly
        $result->source_users_id = $user->getId();
        $result->source_username = $user->displayname . ' ' . $source->title;
        $result->is_deleted = 0;

        // Save the model
        $result->saveOrFail();

        return true;
    }

    public function remove(mixed $root, array $req): bool
    {
        $req = $req['data'];
        $source = Sources::where('title', $req['source_site'])->firstOrFail();
        $user = auth()->user();

        return (bool) UserLinkedSources::where('users_id', $user->getId())
            ->where('source_id', $source->getId())
            ->where('source_users_id_text', $req['device_id'])
            ->update([
                'is_deleted' => 1,
            ]);
    }
}
