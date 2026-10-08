<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use TranslationAudit\Support\PhpTranslationFile;

it('does not parse the sources that do not return a literal array', function (string $source): void {
    expect(PhpTranslationFile::parse($source))->toBeNull();
})->with([
    'syntax error' => ["<?php return ['title' => ;"],
    'no return' => ["<?php \$lines = ['title' => 'Title'];"],
    'function call' => ["<?php return array_merge(require __DIR__.'/base.php', ['title' => 'Title']);"],
    'variable' => ["<?php \$lines = ['title' => 'Title']; return \$lines;"],
]);

it('finds the items with a literal key path, named like Arr::dot names them', function (string $line_key, bool $has): void {
    $file = PhpTranslationFile::parse(<<<'PHP'
        <?php

        return [
            'title' => 'Title',
            'speed' => 'Default ('.config('app.speed', 450).' kt)',
            'attributes' => ['transactions.*.amount' => 'amount'],
            'list' => ['a', 'b', 5 => 'c', 'd', '10' => 'e', 'f', '07' => 'g'],
            'dynamic' => ['a', Status::Active->value => 'b', 'c', 'literal' => 'd'],
            'spread' => [...$other, 'z', 'literal' => 'y'],
            'negative' => [-5 => 'a'],
            'empty' => [],
        ];
        PHP);

    expect($file?->has($line_key))->toBe($has);
})->with([
    'string key' => ['title', true],
    'computed value' => ['speed', true],
    'nested key with dots' => ['attributes.transactions.*.amount', true],
    'nested key split on its dots' => ['attributes.transactions', false],
    'parent' => ['attributes', false],
    'first implicit index' => ['list.0', true],
    'implicit index after an integer key' => ['list.6', true],
    'integer string key' => ['list.10', true],
    'implicit index after an integer string key' => ['list.11', true],
    'non integer string key' => ['list.07', true],
    'implicit index before a key that is not literal' => ['dynamic.0', true],
    'implicit index after a key that is not literal' => ['dynamic.1', false],
    'literal key after a key that is not literal' => ['dynamic.literal', true],
    'implicit index after an unpacked array' => ['spread.0', false],
    'literal key after an unpacked array' => ['spread.literal', true],
    'negative key' => ['negative.-5', false],
    'empty array' => ['empty', false],
    'missing key' => ['missing', false],
]);

it('removes the items of the keys, leaving the rest of the source untouched', function (string $source, array $line_keys, string $expected): void {
    expect(PhpTranslationFile::parse($source)?->withoutKeys(new Collection($line_keys)))->toBe($expected);
})->with([
    'item alone on its line, with the comment above it kept' => [
        "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    // The title\n    'title' => 'Title',\n    'other' => 'Other',\n];\n",
        ['title'],
        "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    // The title\n    'other' => 'Other',\n];\n",
    ],
    'last item without a comma' => [
        "<?php return [\n    'title' => 'Title',\n    'other' => 'Other'\n];\n",
        ['other'],
        "<?php return [\n    'title' => 'Title',\n];\n",
    ],
    'item with a line comment after its comma' => [
        "<?php return [\n    'title' => 'Title', // the title\n    'other' => 'Other',\n];\n",
        ['title'],
        "<?php return [\n    'other' => 'Other',\n];\n",
    ],
    'item with a block comment after its comma' => [
        "<?php return [\n    'title' => 'Title', /* the title */\n    'other' => 'Other',\n];\n",
        ['title'],
        "<?php return [\n    'other' => 'Other',\n];\n",
    ],
    'item with a value on several lines' => [
        "<?php return [\n    'long' => 'One '\n        .'two',\n    'other' => 'Other',\n];\n",
        ['long'],
        "<?php return [\n    'other' => 'Other',\n];\n",
    ],
    'item followed by the closing bracket' => [
        "<?php return [\n    'title' => 'Title',\n    'other' => 'Other'];\n",
        ['other'],
        "<?php return [\n    'title' => 'Title'];\n",
    ],
    'first item sharing its line' => [
        "<?php return ['title' => 'Title', 'other' => 'Other'];",
        ['title'],
        "<?php return ['other' => 'Other'];",
    ],
    'last item sharing its line' => [
        "<?php return ['title' => 'Title', 'other' => 'Other'];",
        ['other'],
        "<?php return ['title' => 'Title'];",
    ],
    'only item' => [
        "<?php return ['title' => 'Title'];",
        ['title'],
        '<?php return [];',
    ],
    'every item sharing a line' => [
        "<?php return ['title' => 'Title', 'other' => 'Other'];",
        ['title', 'other'],
        '<?php return [];',
    ],
    'crlf line breaks' => [
        "<?php\r\n\r\nreturn [\r\n    'title' => 'Title',\r\n    'other' => 'Other',\r\n];\r\n",
        ['title'],
        "<?php\r\n\r\nreturn [\r\n    'other' => 'Other',\r\n];\r\n",
    ],
    'computed value' => [
        "<?php return [\n    'speed' => 'Default ('.config('app.speed', 450).' kt)',\n    'title' => 'Title',\n];\n",
        ['title'],
        "<?php return [\n    'speed' => 'Default ('.config('app.speed', 450).' kt)',\n];\n",
    ],
    'nested key with dots, along with its parent left empty' => [
        "<?php return [\n    'attributes' => [\n        'transactions.*.amount' => 'amount',\n    ],\n    'title' => 'Title',\n];\n",
        ['attributes.transactions.*.amount'],
        "<?php return [\n    'title' => 'Title',\n];\n",
    ],
    'parents left empty on several levels' => [
        "<?php return [\n    'auth' => [\n        'deep' => ['key' => 'Key'],\n        'other' => ['key' => 'Key'],\n    ],\n    'title' => 'Title',\n];\n",
        ['auth.deep.key', 'auth.other.key'],
        "<?php return [\n    'title' => 'Title',\n];\n",
    ],
    'parent kept by an item that is not removed' => [
        "<?php return [\n    'auth' => [\n        'deep' => ['key' => 'Key'],\n        'other' => ['key' => 'Key'],\n    ],\n];\n",
        ['auth.deep.key'],
        "<?php return [\n    'auth' => [\n        'other' => ['key' => 'Key'],\n    ],\n];\n",
    ],
    'parent kept by an item with a key that is not literal' => [
        "<?php return [\n    'status' => [\n        'draft' => 'Draft',\n        Status::Active->value => 'Active',\n    ],\n];\n",
        ['status.draft'],
        "<?php return [\n    'status' => [\n        Status::Active->value => 'Active',\n    ],\n];\n",
    ],
    'parent kept by an empty array' => [
        "<?php return [\n    'auth' => ['key' => 'Key', 'empty' => []],\n];\n",
        ['auth.key'],
        "<?php return [\n    'auth' => ['empty' => []],\n];\n",
    ],
    'every item sharing the dotted key' => [
        "<?php return [\n    'a' => [\n        'b.c' => 'One',\n        'b' => ['c' => 'Two'],\n    ],\n    'title' => 'Title',\n];\n",
        ['a.b.c'],
        "<?php return [\n    'title' => 'Title',\n];\n",
    ],
    'keys that cannot be removed' => [
        "<?php return [\n    'title' => 'Title',\n    'list' => [...\$other, 'z'],\n];\n",
        ['missing', 'list.0', 'title'],
        "<?php return [\n    'list' => [...\$other, 'z'],\n];\n",
    ],
]);
