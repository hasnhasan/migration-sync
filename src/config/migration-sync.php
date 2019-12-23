<?php

return [
    'connection'            => [
        "host"     => env('DB_HOST'),
        "database" => env('DB_DATABASE_SYNC'),
        "username" => env('DB_USERNAME'),
        "password" => env('DB_PASSWORD'),
    ],
    'outputFolder' => 'database/migrations/',

    'excludeTables'         => [
        'migrations', 'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring', 'likes',
    ],
    'excludeFields'         => [
        'created_at', 'updated_at', 'deleted_at',
    ],
    // Field Setting
    'arrayFieldsTypes'      => ['enum'],
    'nullableFieldTypes'    => ['varchar'],
    'fieldTypeNameMappings' => [
        'int'       => 'integer',
        'tinyint'   => 'tinyInteger',
        'smallint'  => 'smallInteger',
        'mediumint' => 'mediumInteger',
        'bigint'    => 'bigInteger',
        'varchar'   => 'string',
    ],
    'filterFieldTypeParams' => [
        'integer'       => function ($x) { return []; },
        'tinyInteger'   => function ($x) { return []; },
        'smallInteger'  => function ($x) { return []; },
        'mediumInteger' => function ($x) { return []; },
        'bigInteger'    => function ($x) { return []; },
        'increments'    => function ($x) { return []; },
        'bigIncrements' => function ($x) { return []; },
    ],
];
