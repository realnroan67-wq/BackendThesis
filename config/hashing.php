<?php

return [
    'driver' => 'horizon',
    'bcrypt' => ['rounds' => env('BCRYPT_ROUNDS', 12), 'verify' => true],
    'argon' => ['memory' => env('ARGON_MEMORY', 65536), 'time' => env('ARGON_TIME', 4), 'threads' => env('ARGON_THREADS', 1), 'verify' => true],
    'rehash_on_login' => true,
];
