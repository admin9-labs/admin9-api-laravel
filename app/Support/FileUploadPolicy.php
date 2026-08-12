<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class FileUploadPolicy
{
    /**
     * @var array<string, array{max_kilobytes: int, extensions: array<string, array<int, string>>}>
     */
    private const TYPE_DEFINITIONS = [
        'image' => [
            'max_kilobytes' => 5 * 1024,
            'extensions' => [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
                'gif' => ['image/gif'],
            ],
        ],
        'document' => [
            'max_kilobytes' => 20 * 1024,
            'extensions' => [
                'pdf' => ['application/pdf'],
                'txt' => ['text/plain'],
                'csv' => ['text/plain', 'text/csv', 'application/csv'],
            ],
        ],
        'video' => [
            'max_kilobytes' => 100 * 1024,
            'extensions' => [
                'mp4' => ['video/mp4', 'application/mp4'],
            ],
        ],
        'audio' => [
            'max_kilobytes' => 20 * 1024,
            'extensions' => [
                'mp3' => ['audio/mpeg'],
                'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
            ],
        ],
        'other' => [
            'max_kilobytes' => 20 * 1024,
            'extensions' => [
                'zip' => ['application/zip', 'application/x-zip-compressed'],
            ],
        ],
    ];

    /**
     * @return array{type: string, mime_type: string, extension: string, size: int, width: ?int, height: ?int}
     */
    public function inspect(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = strtolower((string) $file->getMimeType());
        $definition = $this->definitionForExtension($extension);

        if ($definition === null || ! in_array($mimeType, $definition['mime_types'], true)) {
            $this->reject('The file extension must match an allowed detected MIME type.');
        }

        $size = (int) $file->getSize();

        if ($size < 1) {
            $this->reject('The file must not be empty.');
        }

        if ($size > $definition['max_kilobytes'] * 1024) {
            $this->reject(sprintf(
                'The %s file may not be greater than %d MiB.',
                $definition['type'],
                intdiv($definition['max_kilobytes'], 1024),
            ));
        }

        [$width, $height] = $definition['type'] === 'image'
            ? $this->imageDimensions($file)
            : [null, null];

        $this->validateStructure($file, $extension, $definition['type']);

        return [
            'type' => $definition['type'],
            'mime_type' => $mimeType,
            'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
            'size' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys(self::TYPE_DEFINITIONS);
    }

    public function openApiDescription(): string
    {
        $formats = collect(self::TYPE_DEFINITIONS)
            ->map(function (array $definition, string $type): string {
                $extensions = collect(array_keys($definition['extensions']))
                    ->map(static fn (string $extension): string => strtoupper($extension))
                    ->implode(', ');

                return sprintf(
                    '%s (%s; max %d MiB)',
                    $type,
                    $extensions,
                    intdiv($definition['max_kilobytes'], 1024),
                );
            })
            ->implode('; ');

        return 'Allowed formats: '.$formats.'. The filename extension, detected MIME type, and inspected structure must match.';
    }

    /**
     * @return array{type: string, mime_types: array<int, string>, max_kilobytes: int}|null
     */
    private function definitionForExtension(string $extension): ?array
    {
        foreach (self::TYPE_DEFINITIONS as $type => $definition) {
            $mimeTypes = $definition['extensions'][$extension] ?? null;

            if (is_array($mimeTypes)) {
                return [
                    'type' => $type,
                    'mime_types' => $mimeTypes,
                    'max_kilobytes' => $definition['max_kilobytes'],
                ];
            }
        }

        return null;
    }

    /**
     * @return array{int, int}
     */
    private function imageDimensions(UploadedFile $file): array
    {
        set_error_handler(static fn (): bool => true);

        try {
            $dimensions = getimagesize($file->getPathname());
        } finally {
            restore_error_handler();
        }

        if (! is_array($dimensions)
            || ! isset($dimensions[0], $dimensions[1])
            || $dimensions[0] < 1
            || $dimensions[1] < 1) {
            $this->reject('The file must be a valid image.');
        }

        return [(int) $dimensions[0], (int) $dimensions[1]];
    }

    private function validateStructure(UploadedFile $file, string $extension, string $type): void
    {
        if ($type === 'image') {
            return;
        }

        $stream = fopen($file->getPathname(), 'rb');

        if ($stream === false) {
            $this->reject('The file contents could not be inspected.');
        }

        try {
            $header = fread($stream, 8192);
            $fileSize = (int) $file->getSize();
            $tailOffset = max(0, $fileSize - 1024);
            fseek($stream, $tailOffset);
            $tail = fread($stream, 1024);
        } finally {
            fclose($stream);
        }

        if (! is_string($header) || $header === '' || ! is_string($tail)) {
            $this->reject('The file contents could not be inspected.');
        }

        $isValid = match ($extension) {
            'pdf' => str_starts_with($header, '%PDF-') && str_contains($tail, '%%EOF'),
            'txt', 'csv' => ! str_contains($header, "\0"),
            'mp4' => strlen($header) >= 12 && substr($header, 4, 4) === 'ftyp',
            'mp3' => str_starts_with($header, 'ID3')
                || (strlen($header) >= 2 && ord($header[0]) === 0xFF && (ord($header[1]) & 0xE0) === 0xE0),
            'wav' => strlen($header) >= 12
                && str_starts_with($header, 'RIFF')
                && substr($header, 8, 4) === 'WAVE',
            'zip' => $this->isValidZip($file),
            default => true,
        };

        if (! $isValid) {
            $this->reject('The file contents are damaged or do not match the declared format.');
        }
    }

    private function isValidZip(UploadedFile $file): bool
    {
        $archive = new \ZipArchive;
        $result = $archive->open($file->getPathname(), \ZipArchive::CHECKCONS);

        if ($result === true) {
            $archive->close();

            return true;
        }

        return false;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages([
            'file' => [$message],
        ]);
    }
}
