<?php

declare(strict_types=1);

return [
    // Glob patterns, relative to the project root, of the files to scan for translation keys.
    'scan_paths' => [
        'app/**/*.php',
        'resources/views/**/*.blade.php',
    ],

    // Glob patterns, relative to the project root, of the files to skip even when they match a scan path.
    'ignore_paths' => [],

    // Glob patterns, relative to the project root, of the symbolic links not to follow when the audit runs with --follow-links.
    'ignore_links' => [
        'vendor',
        'node_modules',
    ],

    // Locales to leave out of the audit, e.g. ['en'] when the keys themselves are the English strings.
    'ignore_locales' => [],

    // Locales the app supports. Set ['auto'] to detect them from the json files and directories in the "lang" folder.
    'supported_locales' => ['it', 'en'],
];
