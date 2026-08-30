<?php

namespace App\Http\Requests\Admin;

use App\Models\File;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'navigation_logo_path' => $this->filePathRules(),
            'login_logo_path' => $this->filePathRules(),
            'login_background_path' => $this->filePathRules(),
            'favicon_path' => $this->filePathRules(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function filePathRules(): array
    {
        return [
            'present',
            'nullable',
            'string',
            'max:255',
            Rule::exists(File::class, 'path')->where(static function (Builder $query): void {
                $query
                    ->where('disk', 'public')
                    ->where('type', 'image')
                    ->where('status', File::STATUS_READY)
                    ->whereNull('deletion_token');
            }),
        ];
    }
}
