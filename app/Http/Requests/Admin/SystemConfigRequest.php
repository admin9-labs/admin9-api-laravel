<?php

namespace App\Http\Requests\Admin;

use App\Models\SystemConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class SystemConfigRequest extends FormRequest
{
    private const TYPE_VALUE_MESSAGES = [
        SystemConfig::TYPE_INTEGER => 'The value must be a valid integer for integer configs.',
        SystemConfig::TYPE_BOOLEAN => 'The value must be true or false for boolean configs.',
        SystemConfig::TYPE_JSON => 'The value must be valid JSON for JSON configs.',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<int, mixed>
     */
    protected function valueRules(?SystemConfig $systemConfig = null): array
    {
        return [
            'nullable',
            'string',
            'max:10000',
            Rule::when($this->configurationType($systemConfig) === SystemConfig::TYPE_JSON, ['json']),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('key') && $this->has('key') && SystemConfig::containsSensitiveConfiguration($this->input('key'))) {
                    $validator->errors()->add('key', 'Sensitive configuration keys are not allowed.');
                }

                if ($validator->errors()->hasAny(['type', 'value'])) {
                    return;
                }

                $systemConfig = $this->systemConfig();
                $type = $this->configurationType($systemConfig);
                $value = $this->configurationValue($systemConfig);

                if ($type === SystemConfig::TYPE_INTEGER && ! $this->hasIntegerValue($value)) {
                    $validator->errors()->add('value', self::TYPE_VALUE_MESSAGES[SystemConfig::TYPE_INTEGER]);
                }

                if ($type === SystemConfig::TYPE_BOOLEAN && ! $this->hasBooleanValue($value)) {
                    $validator->errors()->add('value', self::TYPE_VALUE_MESSAGES[SystemConfig::TYPE_BOOLEAN]);
                }

                if ($type === SystemConfig::TYPE_JSON && ! $this->hasJsonValue($value)) {
                    $validator->errors()->add('value', self::TYPE_VALUE_MESSAGES[SystemConfig::TYPE_JSON]);
                }

                if ($this->has('value') && SystemConfig::containsSensitiveConfiguration($value)) {
                    $validator->errors()->add('value', 'Sensitive configuration values are not allowed.');
                }
            },
        ];
    }

    protected function systemConfig(): ?SystemConfig
    {
        $systemConfig = $this->route('system_config');

        return $systemConfig instanceof SystemConfig ? $systemConfig : null;
    }

    private function configurationType(?SystemConfig $systemConfig): string
    {
        return (string) $this->input('type', $systemConfig?->type ?? SystemConfig::TYPE_STRING);
    }

    private function configurationValue(?SystemConfig $systemConfig): mixed
    {
        if ($this->has('value')) {
            return $this->input('value');
        }

        return $systemConfig?->value;
    }

    private function hasIntegerValue(mixed $value): bool
    {
        return $value === null || filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    private function hasBooleanValue(mixed $value): bool
    {
        return $value === null || filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null;
    }

    private function hasJsonValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        json_decode((string) $value);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
