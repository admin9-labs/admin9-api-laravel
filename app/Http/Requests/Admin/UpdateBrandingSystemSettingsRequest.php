<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

#[FailOnUnknownFields]
class UpdateBrandingSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'navigation_logo_url' => $this->brandingUrlRules(),
            'login_logo_url' => $this->brandingUrlRules(),
            'login_background_url' => $this->brandingUrlRules(),
            'favicon_url' => $this->brandingUrlRules(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function brandingUrlRules(): array
    {
        return [
            'present',
            'nullable',
            'string',
            'max:2048',
            'url:http,https',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                $parts = parse_url($value);

                if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
                    $fail("The {$attribute} field must not contain credentials.");
                }
            },
        ];
    }
}
