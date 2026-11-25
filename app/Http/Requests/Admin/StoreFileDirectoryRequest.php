<?php

namespace App\Http\Requests\Admin;

use App\Models\FileDirectory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Mitoop\LaravelEfficientFormRequest\EfficientFormRequest;

class StoreFileDirectoryRequest extends EfficientFormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $parentId = $this->input('parent_id');

        return [
            'name' => [
                'required',
                'string',
                'max:64',
                Rule::unique(FileDirectory::class)->where(
                    fn ($query) => $parentId === null
                        ? $query->whereNull('parent_id')
                        : $query->where('parent_id', $parentId),
                ),
            ],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists(FileDirectory::class, 'id')],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('parent_id')) {
                    return;
                }

                $parent = FileDirectory::query()->find($this->integer('parent_id'));

                if ($parent instanceof FileDirectory && $parent->parent_id !== null) {
                    $validator->errors()->add('parent_id', 'File directories may contain at most two levels.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->string('name')->toString())]);
        }
    }
}
