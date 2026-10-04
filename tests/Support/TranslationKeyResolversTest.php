<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use TranslationAudit\Contracts\TranslationKeyResolver;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Data\UsedTranslationKey;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\TranslationKeyResolvers;

final class TranslationKeyResolversTestResolver implements TranslationKeyResolver {
    /** @var iterable<mixed> */
    public static iterable $keys = [];

    /** @var array<array-key, mixed> */
    public static array $covers = [];

    public function resolve(): iterable {
        return self::$keys;
    }

    public function covers(): array {
        return self::$covers;
    }
}

final readonly class TranslationKeyResolversTestInjectedResolver implements TranslationKeyResolver {
    public function __construct(private Repository $config) {}

    public function resolve(): iterable {
        yield $this->config->string('app.name');
    }

    public function covers(): array {
        return [];
    }
}

final class TranslationKeyResolversTestFailingResolver implements TranslationKeyResolver {
    public function resolve(): iterable {
        throw new LogicException('The enum is missing.');
    }

    public function covers(): array {
        return [];
    }
}

beforeEach(function (): void {
    TranslationKeyResolversTestResolver::$keys = [];
    TranslationKeyResolversTestResolver::$covers = [];
});

/**
 * Apply the resolvers in the config to the used keys.
 *
 * @param  array<array-key, mixed>  $config
 * @param  list<UsedTranslationKey>  $keys
 * @return list<UsedTranslationKey>
 */
function applyResolvers(array $config, array $keys = []): array {
    return TranslationKeyResolvers::fromConfig($config)->apply(collect($keys))->all();
}

it('adds the resolved keys, used in the file named after the resolver class', function (): void {
    TranslationKeyResolversTestResolver::$keys = ['status.active', 'status.suspended'];

    expect(applyResolvers([TranslationKeyResolversTestResolver::class], [new UsedTranslationKey('app/Example.php', 'Hello')]))->toEqual([
        new UsedTranslationKey('app/Example.php', 'Hello'),
        new UsedTranslationKey(TranslationKeyResolversTestResolver::class, 'status.active'),
        new UsedTranslationKey(TranslationKeyResolversTestResolver::class, 'status.suspended'),
    ]);
});

it('accepts the keys yielded by a generator', function (): void {
    TranslationKeyResolversTestResolver::$keys = (function (): Generator {
        yield 'status.active';
    })();

    expect(applyResolvers([TranslationKeyResolversTestResolver::class]))->toEqual([new UsedTranslationKey(TranslationKeyResolversTestResolver::class, 'status.active')]);
});

it('resolves the resolvers from the container', function (): void {
    config(['app.name' => 'Workbench']);

    expect(applyResolvers([TranslationKeyResolversTestInjectedResolver::class]))->toEqual([new UsedTranslationKey(TranslationKeyResolversTestInjectedResolver::class, 'Workbench')]);
});

it('leaves the keys untouched without resolvers', function (): void {
    $keys = [new UsedTranslationKey('app/Example.php', 'Hello'), new UsedTranslationKey('app/Example.php', new DynamicTranslationKey(['status.', '']))];

    expect(applyResolvers([], $keys))->toBe($keys);
});

it('replaces only the covered dynamic keys of the covered files', function (): void {
    TranslationKeyResolversTestResolver::$keys = ['status.active'];
    TranslationKeyResolversTestResolver::$covers = ['app/Enums/*.php' => 'status.*', 'app/Models/User.php' => ['role.*', 'level.*.label']];

    $kept = [
        new UsedTranslationKey('app/Enums/Status.php', 'status.title'),
        new UsedTranslationKey('app/Enums/Status.php', new DynamicTranslationKey(['payments.', ''])),
        new UsedTranslationKey('app/Example.php', new DynamicTranslationKey(['status.', ''])),
        new UsedTranslationKey('app/Models/User.php', new DynamicTranslationKey(['status.', ''])),
    ];

    expect(applyResolvers([TranslationKeyResolversTestResolver::class], [
        ...$kept,
        new UsedTranslationKey('app/Enums/Status.php', new DynamicTranslationKey(['status.', ''])),
        new UsedTranslationKey('app/Enums/Level.php', new DynamicTranslationKey(['status.', ''])),
        new UsedTranslationKey('app/Models/User.php', new DynamicTranslationKey(['role.', ''])),
        new UsedTranslationKey('app/Models/User.php', new DynamicTranslationKey(['level.', '.label'])),
    ]))->toEqual([...$kept, new UsedTranslationKey(TranslationKeyResolversTestResolver::class, 'status.active')]);
});

it('fails when the config contains something other than a resolver class', function (mixed $resolver): void {
    applyResolvers([$resolver]);
})->with([
    'missing class' => 'App\Translations\MissingResolver',
    'class not implementing the contract' => stdClass::class,
    'resolver instance' => fn (): TranslationKeyResolver => new TranslationKeyResolversTestResolver,
    'integer' => 1,
])->throws(InvalidConfigException::class, 'The "resolvers" config must contain only classes implementing the TranslationAudit\Contracts\TranslationKeyResolver contract.');

it('fails naming the resolver when it throws', function (): void {
    applyResolvers([TranslationKeyResolversTestFailingResolver::class]);
})->throws(RuntimeException::class, 'Unable to resolve the translation keys with TranslationKeyResolversTestFailingResolver: The enum is missing.');

it('fails when a resolved key is not a non-empty string', function (mixed $key): void {
    TranslationKeyResolversTestResolver::$keys = ['status.active', $key];

    applyResolvers([TranslationKeyResolversTestResolver::class]);
})->with([
    'empty string' => '',
    'zero' => '0',
    'integer' => 1,
    'null' => null,
])->throws(RuntimeException::class, 'Unable to resolve the translation keys with TranslationKeyResolversTestResolver: the resolved keys must be non-empty strings.');

it('fails when the covers are invalid', function (array $covers): void {
    TranslationKeyResolversTestResolver::$covers = $covers;

    applyResolvers([TranslationKeyResolversTestResolver::class]);
})->with([
    'list of patterns' => [['status.*']],
    'empty glob' => [['' => 'status.*']],
    'empty pattern' => [['app/*.php' => '']],
    'empty list of patterns' => [['app/*.php' => []]],
    'map of patterns' => [['app/*.php' => ['status' => 'status.*']]],
    'invalid pattern in the list' => [['app/*.php' => ['status.*', 1]]],
])->throws(RuntimeException::class, 'Unable to resolve the translation keys with TranslationKeyResolversTestResolver: the covers must map each glob pattern of the files to the pattern, or the list of patterns, of the dynamic keys.');
