<?php

declare(strict_types=1);

use TranslationAudit\Enums\DisplayFormat;
use TranslationAudit\Enums\SaveFormat;

return [
    // Glob patterns, relative to the project root, of the files to scan for translation keys.
    'scan_paths' => [
        'app/**/*.php',
        'resources/views/**/*.blade.php',
    ],

    // Glob patterns, relative to the project root, of the files to skip
    // even when they match a scan path.
    'ignore_paths' => [],

    // Glob patterns, relative to the project root, of the symbolic links never to follow,
    // even when the audit follows symbolic links.
    'ignore_links' => [
        'vendor',
        'node_modules',
    ],

    // Locales to leave out of the audit, e.g. ['en'] when the keys
    // themselves are the English strings.
    'ignore_locales' => [],

    // Locales the app supports. Set ['auto'] to detect them from the json
    // files and directories in the "lang" folder.
    'supported_locales' => ['auto'],

    // Whether to follow symbolic links while looking for the files to
    // scan. The --follow-links option overrides it.
    'always_follow_links' => false,

    // Whether to save the audit result to a file on every run, even when no translation is
    // missing. The --save option overrides it.
    'always_save' => false,

    // Format of the saved audit result, as a SaveFormat case or its value. Supported formats:
    // json. The --save-format option overrides it.
    'save_format' => SaveFormat::Json,

    // Absolute path of the directory where the audit result is saved, created if
    // missing. The --save-path option overrides it.
    'save_path' => storage_path('app/private/translation-audits'),

    // Name of the saved file, without the extension. {now:<format>} inserts the current date
    // in the given PHP date format, {random:<length>} inserts random alphanumeric
    // characters. The --save-name option overrides it.
    'save_name' => 'translation-audit-{now:d_M_Y_H_i}-{random:8}',

    // Custom translation functions and static methods, besides Laravel's own, whose first
    // argument is audited as a translation key, e.g. ['t', 'App\Support\Translator::translate'].
    // Map one to the position of the argument holding the key, starting from 0, when the key is
    // not the first argument, e.g. ['trans_for' => 1].
    'translation_calls' => [],

    // Translation keys to leave out of the audit. A plain key is ignored for every locale, a key
    // mapped to a list of locales only for those locales, e.g. ['Hello', 'Hi' => ['en']]. A
    // dynamic key, like __("payments.{$method}"), is ignored by its pattern, with an asterisk in
    // place of each dynamic part, e.g. 'payments.*'.
    'ignore_keys' => [],

    // Translation keys to audit even though the scan cannot detect them, e.g. those used only by
    // the frontend or built from data. They are audited as used in this config file: checked as
    // missing in every locale, and never reported as unused nor purged. A pattern, with an asterisk
    // in place of each dynamic part, is audited as a dynamic key, e.g. 'payments.*'.
    'additional_keys' => [],

    // Values of the dynamic keys, like __("payments.{$method}"), so each value is audited as a key
    // of its own. Each pattern, with an asterisk in place of the dynamic part, maps to a backed
    // enum class, whose case values are the values, or to the list of the values, e.g.
    // ['payments.*' => PaymentMethod::class, 'status.*.label' => ['active', 'suspended']].
    'dynamic_keys' => [],

    // Classes implementing TranslationAudit\Contracts\TranslationKeyResolver, resolved from the
    // container, returning the keys the app builds at runtime by custom logic, e.g. from the cases
    // of an enum. The resolved keys are audited as used in the file named after the resolver class,
    // and replace the dynamic keys the resolver covers, e.g.
    // [App\Translations\StatusLabelKeys::class].
    'resolvers' => [],

    // Classes, resolved from the container, run before and after the command: their before method is called before the scan, and their
    // after method once the result is printed. Each method is called through the container, taking the command as its $command argument,
    // the result as the $result argument of the after method, and any other dependency. A listed class runs on every command, a class
    // mapped to a command class, or a list of command classes, only on those, e.g.
    // [App\Translations\SyncTranslations::class, App\Translations\NotifyTeam::class => TranslationAudit\Console\Commands\AuditTranslations::class].
    'hooks' => [],

    // Format in which the audit result is displayed in the console, as a DisplayFormat case or its
    // value. Supported formats: json, list, table. The --display-format option overrides it.
    'display_format' => DisplayFormat::List,

    // Whether to hide the progress bar while the files are scanned. The
    // --no-progress option overrides it.
    'disable_progress_bar' => false,

    // Whether to hide the result summary. The --no-summary option overrides it.
    'disable_summary' => false,

    // Whether to also audit for unused translations, defined in the "lang" folder but used in none
    // of the scanned files. The --unused option overrides it.
    'audit_unused' => false,

    // Glob patterns, relative to the project root, of the translation files to leave out of the
    // unused audit. By default the files whose keys Laravel itself uses.
    'unused_ignore_paths' => [
        'lang/*/auth.php',
        'lang/*/pagination.php',
        'lang/*/passwords.php',
        'lang/*/validation.php',
    ],
];
