<?php

return [
    'guard' => 'web', 'passwords' => 'users', 'username' => 'email', 'email' => 'email', 'lowercase_usernames' => true,
    'home' => '/', 'prefix' => '', 'domain' => null, 'middleware' => ['web'], 'auth_middleware' => 'auth',
    'limiters' => ['login' => 'login'], 'views' => true,
    'features' => [],
];
