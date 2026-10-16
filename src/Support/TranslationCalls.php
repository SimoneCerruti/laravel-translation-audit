<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use TranslationAudit\Exceptions\InvalidConfigException;

use function Safe\preg_match;

/**
 * The custom translation functions and static methods, besides Laravel's own, with the position of the argument holding the translation key.
 *
 * @internal
 */
final readonly class TranslationCalls {
    private const string NAME_PATTERN = '/^\\\\?[a-z_\x80-\xff][a-z0-9_\x80-\xff]*(\\\\[a-z_\x80-\xff][a-z0-9_\x80-\xff]*)*$/i';

    private const string METHOD_PATTERN = '/^[a-z_\x80-\xff][a-z0-9_\x80-\xff]*$/i';

    /**
     * @param  array<lowercase-string, int<0, max>>  $functions  The position of the key argument, by the lowercase fully qualified function name.
     * @param  array<lowercase-string, int<0, max>>  $static_methods  The position of the key argument, by the lowercase fully qualified class name and method name, joined by `::`.
     */
    private function __construct(private array $functions, private array $static_methods) {}

    /**
     * Build the calls from the `translation_calls` config, which lists the function names and the `Class::method` static methods,
     * whose first argument is the translation key, or maps them to the position of the argument holding the key, starting from 0.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws InvalidConfigException
     */
    public static function fromConfig(array $config): self {
        $functions = [];
        $static_methods = [];

        foreach ($config as $name => $position) {
            // a listed call takes the key as its first argument.
            if (\is_int($name)) {
                $name = $position;
                $position = 0;
            }

            if (! \is_string($name) || ! \is_int($position) || $position < 0) {
                throw new InvalidConfigException('The "translation_calls" config must list the function names and the "Class::method" static methods, or map them to the position of the argument holding the translation key, starting from 0.');
            }

            $parts = explode('::', $name);

            if (self::isStaticMethod($parts)) {
                $static_methods[self::staticMethodName($parts[0], $parts[1])] = $position;
            } elseif (self::isFunction($parts)) {
                $functions[self::normalize($name)] = $position;
            } else {
                throw new InvalidConfigException("The \"{$name}\" entry of the \"translation_calls\" config must be a function name, like \"t\" or \"App\\Support\\t\", or a static method, like \"App\\Support\\Translator::translate\".");
            }
        }

        return new self($functions, $static_methods);
    }

    /**
     * The position of the key argument of the function, matching the first of its candidate fully qualified names in the config.
     *
     * @return int<0, max>|null
     */
    public function functionKeyPosition(string ...$names): ?int {
        foreach ($names as $name) {
            if (isset($this->functions[self::normalize($name)])) {
                return $this->functions[self::normalize($name)];
            }
        }

        return null;
    }

    /**
     * The position of the key argument of the static method of the fully qualified class.
     *
     * @return int<0, max>|null
     */
    public function staticMethodKeyPosition(string $class, string $method): ?int {
        return $this->static_methods[self::staticMethodName($class, $method)] ?? null;
    }

    /**
     * Whether the parts of the name split by `::` are a class name and a method name.
     *
     * @param  list<string>  $parts
     *
     * @phpstan-assert-if-true array{string, string} $parts
     */
    private static function isStaticMethod(array $parts): bool {
        return \count($parts) === 2 && preg_match(self::NAME_PATTERN, $parts[0]) === 1 && preg_match(self::METHOD_PATTERN, $parts[1]) === 1;
    }

    /**
     * Whether the parts of the name split by `::` are a function name.
     *
     * @param  list<string>  $parts
     */
    private static function isFunction(array $parts): bool {
        return \count($parts) === 1 && preg_match(self::NAME_PATTERN, $parts[0]) === 1;
    }

    /**
     * The normalized class name and the lowercase method name, joined by `::`.
     *
     * @return lowercase-string
     */
    private static function staticMethodName(string $class, string $method): string {
        return self::normalize($class).'::'.strtolower($method);
    }

    /**
     * PHP function, class and method names are case-insensitive, and fully qualified with or without a leading backslash.
     *
     * @return lowercase-string
     */
    private static function normalize(string $name): string {
        return strtolower(ltrim($name, '\\'));
    }
}
