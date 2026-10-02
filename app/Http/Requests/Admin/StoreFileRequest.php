<?php

namespace App\Http\Requests\Admin;

use App\Models\FileDirectory;
use App\Support\FileUploadPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'type' => ['prohibited'],
            'allowed_types' => ['sometimes', 'array', 'min:1', 'max:5'],
            'allowed_types.*' => ['required', Rule::in(app(FileUploadPolicy::class)->types())],
            'directory_id' => ['sometimes', 'nullable', 'integer', Rule::exists(FileDirectory::class, 'id')],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    return;
                }

                try {
                    $metadata = app(FileUploadPolicy::class)->inspect($file);
                    $allowedTypes = $this->input('allowed_types');
                    if (is_array($allowedTypes) && ! in_array($metadata['type'], $allowedTypes, true)) {
                        $validator->errors()->add('file', 'The file type is not allowed for this field.');
                    }
                } catch (ValidationException $exception) {
                    foreach ($exception->errors()['file'] ?? [] as $message) {
                        $validator->errors()->add('file', $message);
                    }
                }
            },
        ];
    }
}
