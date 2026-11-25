<?php

use Bnomei\ModelWithKhulan;
use Kirby\Cms\User;

class AdminUser extends User
{
    use ModelWithKhulan;

    public function hello(): string
    {
        return 'world';
    }
}

Kirby::plugin('myplugin/user', [
    'userModels' => [
        'admin' => AdminUser::class, // admin is default role
    ],
]);
