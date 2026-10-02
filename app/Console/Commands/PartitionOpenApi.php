<?php

namespace App\Console\Commands;

use App\Support\OpenApi\DocumentPartitioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PartitionOpenApi extends Command
{
    protected $signature = 'docs:api:partition';

    protected $description = 'Export admin and client partitions of the combined OpenAPI document';

    public function handle(DocumentPartitioner $partitioner): int
    {
        $source = base_path(config('scramble.export_path'));
        $document = json_decode(File::get($source), flags: JSON_THROW_ON_ERROR);

        foreach ($partitioner->partition($document) as $audience => $partition) {
            File::put(dirname($source).'/'.$audience.'-api.json', json_encode($partition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }

        $this->components->info('Admin and client OpenAPI partitions generated; the combined document is preserved.');

        return self::SUCCESS;
    }
}
