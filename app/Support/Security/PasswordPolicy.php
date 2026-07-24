<?php

namespace App\Support\Security;

class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /**
     * @return array<int, mixed>
     */
    public static function rules(bool $confirmed = false): array
    {
        $rules = ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:255'];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }
}
