<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MongoDB Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for MongoDB connection used to read users
    | (shared with Node.js backend)
    |
    */

    'uri' => env('MONGODB_URI', 'mongodb://localhost:27017'),
    'database' => env('MONGODB_DATABASE', 'test'),
    'collection' => env('MONGODB_COLLECTION', 'users'),

    'options' => [
        'connectTimeoutMS' => 5000,
        'serverSelectionTimeoutMS' => 5000,
    ],
];
