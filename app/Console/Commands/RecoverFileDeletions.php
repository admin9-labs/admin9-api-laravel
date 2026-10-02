<?php

namespace App\Console\Commands;

use App\Actions\Admin\DeleteFile;
use App\Models\File;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('files:recover-deletions {--limit=100 : Maximum expired deletion requests to attempt}')]
#[Description('Resume expired, previously authorized file deletions')]
class RecoverFileDeletions extends Command
{
    public function handle(DeleteFile $deleteFile): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->components->error('The limit must be between 1 and 1000.');

            return self::FAILURE;
        }

        $unattributed = File::query()->pendingDeletionRecovery()->whereNull('deletion_requested_by')->count();
        if ($unattributed > 0) {
            Log::warning('File deletions require an authorized manual retry.', ['unattributed_count' => $unattributed]);
        }

        $files = File::query()->pendingDeletionRecovery()->whereNotNull('deletion_requested_by')
            ->orderBy('deletion_started_at')->orderBy('id')->limit($limit)->get();
        $recovered = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $file) {
            try {
                if ($deleteFile->recover($file)) {
                    $recovered++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        $this->components->info("File deletions: {$recovered} recovered, {$skipped} skipped, {$failed} failed, {$unattributed} require manual retry.");

        return $failed === 0 && $unattributed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
