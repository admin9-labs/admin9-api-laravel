<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreMediaRequest extends FormRequest
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
            'file' => [
                'required',
                File::image()
                    ->extensions(['jpg', 'jpeg', 'png', 'webp', 'gif'])
                    ->max(5120)
                    ->rules(['mimetypes:image/jpeg,image/png,image/webp,image/gif']),
            ],
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

                $allowedExtensions = match ($file->getMimeType()) {
                    'image/jpeg' => ['jpg', 'jpeg'],
                    'image/png' => ['png'],
                    'image/webp' => ['webp'],
                    'image/gif' => ['gif'],
                    default => [],
                };

                if (! in_array(strtolower($file->getClientOriginalExtension()), $allowedExtensions, true)) {
                    $validator->errors()->add('file', 'The file extension must match the detected image type.');
                }
            },
        ];
    }
}
