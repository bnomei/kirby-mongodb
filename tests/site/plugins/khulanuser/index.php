<?php

class AdminUser extends \Kirby\Cms\User
{
    use \Bnomei\ModelWithKhulan;

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
