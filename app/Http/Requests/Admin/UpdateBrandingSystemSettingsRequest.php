<?php

namespace App\Http\Requests\Admin;

use App\Models\Media;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

#[FailOnUnknownFields]
class UpdateBrandingSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'navigation_logo_media_id' => ['present', 'nullable', 'integer', 'min:1'],
            'login_logo_media_id' => ['present', 'nullable', 'integer', 'min:1'],
            'login_background_media_id' => ['present', 'nullable', 'integer', 'min:1'],
            'favicon_media_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $fields = array_keys($this->rules());
                $mediaIds = collect($fields)
                    ->map(fn (string $field): mixed => $this->input($field))
                    ->filter(fn (mixed $value): bool => is_int($value) || ctype_digit((string) $value))
                    ->map(fn (mixed $value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->values();
                $media = Media::query()->whereKey($mediaIds)->get()->keyBy('id');

                foreach ($fields as $field) {
                    $mediaId = $this->input($field);

                    if ($mediaId === null || ! is_numeric($mediaId)) {
                        continue;
                    }

                    $model = $media->get((int) $mediaId);

                    if (
                        ! $model instanceof Media
                        || $model->status !== Media::STATUS_READY
                        || $model->deletion_token !== null
                        || ! str_starts_with($model->mime_type, 'image/')
                    ) {
                        $validator->errors()->add(
                            $field,
                            'The selected media must be a ready image that is not being deleted.',
                        );
                    }
                }
            },
        ];
    }
}
