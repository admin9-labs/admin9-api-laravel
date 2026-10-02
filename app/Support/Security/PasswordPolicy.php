<?php

namespace App\Support\Security;

use Closure;

class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /**
     * @return array<int, mixed>
     */
    public static function rules(bool $confirmed = false): array
    {
        $rules = [
            'required',
            'string',
            'min:'.self::MIN_LENGTH,
            'max:255',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (config('hashing.driver') !== 'bcrypt' || ! is_string($value)) {
                    return;
                }

                $byteLimit = min(72, (int) config('hashing.bcrypt.limit') ?: 72);

                if (strlen($value) > $byteLimit) {
                    $fail('The :attribute field must not exceed '.$byteLimit.' bytes when using bcrypt.');
                }

                if (str_contains($value, "\0")) {
                    $fail('The :attribute field must not contain null bytes.');
                }
            },
        ];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }
}
