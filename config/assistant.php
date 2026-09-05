<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Opening line
    |--------------------------------------------------------------------------
    |
    | Shown before the model is called at all, so the panel has something to
    | say the moment it opens and the first greeting costs nothing.
    |
    */

    'greeting' => 'Hello. I can answer questions about IT, HR and how the helpdesk works, or help you raise a ticket. What is going on?',

    /*
    |--------------------------------------------------------------------------
    | Settlement
    |--------------------------------------------------------------------------
    |
    | How long a conversation sits before it is counted as answered or given
    | up on. Applied by the assistant:settle-sessions command.
    |
    */

    'answered_after_minutes' => 30,
    'guest_ttl_hours' => 24,
];
