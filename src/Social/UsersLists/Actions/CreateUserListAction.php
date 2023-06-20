<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersLists\Actions;

use Kanvas\Social\UsersLists\DataTransferObject\UserList;
use Kanvas\Social\UsersLists\Models\UserList as ModelUserList;

class CreateUserListAction
{
    public function __construct(
        private UserList $data,
    ) {
    }

    public function execute(): ModelUserList
    {
        $userList = ModelUserList::create($this->data->toArray());
        if ($this->data->files) {
            foreach ($this->data->files as $file) {
                $userList->addFileFromUrl($file['url'], $file['name']);
            }
        }

        return $userList;
    }
}
