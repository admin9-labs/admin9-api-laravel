<?php

namespace Tests\Support;

use RuntimeException;
use Spatie\Activitylog\Models\Activity;

class FailingSecondActivity extends Activity
{
    public static int $saveCount = 0;

    public static function reset(): void
    {
        self::$saveCount = 0;
    }

    public function save(array $options = []): bool
    {
        self::$saveCount++;

        if (self::$saveCount === 2) {
            throw new RuntimeException('Second activity log write failed');
        }

        return parent::save($options);
    }
}
