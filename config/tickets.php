<?php

return [
    'pagination' => [
        'default_limit' => 20,
        /*
         * Enforced, not advisory. Without a ceiling a client can ask for the
         * whole table and turn a paginated endpoint into an unbounded one.
         */
        'max_limit' => 100,
    ],

    'search' => [
        /*
         * Must match the server's innodb_ft_min_token_size. Terms shorter than
         * this match nothing in a fulltext index, so the query falls back to
         * LIKE rather than silently returning no results.
         */
        'min_token_size' => (int) env('SEARCH_MIN_TOKEN_SIZE', 3),
    ],
];
