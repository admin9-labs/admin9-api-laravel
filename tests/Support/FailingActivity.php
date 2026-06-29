<?php

namespace Tests\Support;

use RuntimeException;
use Spatie\Activitylog\Models\Activity;

class FailingActivity extends Activity
{
    public function save(array $options = []): bool
    {
        throw new RuntimeException('Activity log write failed');
    }
}
