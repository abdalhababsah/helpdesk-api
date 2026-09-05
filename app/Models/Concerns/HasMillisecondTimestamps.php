<?php

namespace App\Models\Concerns;

/**
 * Datetime columns are declared with millisecond precision. Eloquent's default
 * format for MySQL is second-granular, so without this every write silently
 * truncates the fraction the column was widened to hold.
 */
trait HasMillisecondTimestamps
{
    public function initializeHasMillisecondTimestamps(): void
    {
        $this->dateFormat = 'Y-m-d H:i:s.v';
    }
}
