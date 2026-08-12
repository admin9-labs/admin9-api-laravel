<?php

namespace App\Http\Requests\Admin;

use App\Support\FileUploadPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
                    app(FileUploadPolicy::class)->inspect($file);
                } catch (ValidationException $exception) {
                    foreach ($exception->errors()['file'] ?? [] as $message) {
                        $validator->errors()->add('file', $message);
                    }
                }
            },
        ];
    }
}
