<?php

namespace App\Support;

use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Hashing\BcryptHasher;
use RuntimeException;

/** Always write Argon2id; verify only approved Argon2id or legacy bcrypt, never arbitrary algorithms. */
class CompatiblePasswordHasher extends Argon2IdHasher
{
    private BcryptHasher $legacy;

    public function __construct(array $options, array $bcryptOptions)
    {
        if (! in_array('argon2id', password_algos(), true)) {
            throw new RuntimeException('Argon2id support is required.');
        }
        parent::__construct([...$options, 'verify' => true]);
        $this->legacy = new BcryptHasher([...$bcryptOptions, 'verify' => true]);
    }

    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $algorithm = password_get_info((string) $hashedValue)['algoName'];

        return match ($algorithm) {
            'bcrypt' => $this->legacy->check($value, $hashedValue, $options),
            'argon2id' => parent::check($value, $hashedValue, $options),
            default => false,
        };
    }

    public function verifyConfiguration($value): bool
    {
        return password_get_info((string) $value)['algoName'] === 'bcrypt'
            ? $this->legacy->verifyConfiguration($value) : parent::verifyConfiguration($value);
    }
}
