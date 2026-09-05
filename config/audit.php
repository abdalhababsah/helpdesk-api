<?php

return [
    /*
     * How long action log entries are kept before model:prune removes them.
     * The log is an operational record, not permanent history.
     */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),
];
