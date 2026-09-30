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

    // Glob patterns, relative to the project root, of the symbolic links never to follow, even when the audit follows symbolic links.
    'ignore_links' => [
        'vendor',
        'node_modules',
    ],

    // Locales to leave out of the audit, e.g. ['en'] when the keys themselves are the English strings.
    'ignore_locales' => [],

    // Locales the app supports. Set ['auto'] to detect them from the json files and directories in the "lang" folder.
    'supported_locales' => ['auto'],

    // Whether to follow symbolic links while looking for the files to scan. The --follow-links option overrides it.
    'always_follow_links' => false,

    // Whether to save the audit result to a file on every run, even when no translation is missing. The --save option overrides it.
    'always_save' => false,

    // Format of the saved audit result. Supported formats: json. The --save-format option overrides it.
    'save_format' => 'json',

    // Absolute path of the directory where the audit result is saved, created if missing. The --save-path option overrides it.
    'save_path' => storage_path('app/private/translation-audits'),

    // Name of the saved file, without the extension. {now:<format>} inserts the current date in the given PHP date format, {random:<length>} inserts random alphanumeric characters. The --save-name option overrides it.
    'save_name' => 'translation-audit-{now:d_M_Y_H_i}-{random:8}',

    // Translation keys to leave out of the audit. A plain key is ignored for every locale, a key mapped to a list of locales only for those locales, e.g. ['Hello', 'Hi' => ['en']].
    'ignore_keys' => [],

    // Format in which the audit result is displayed in the console. Supported formats: json, list, table. The --display-format option overrides it.
    'display_format' => 'list',
];
