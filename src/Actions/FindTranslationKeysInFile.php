<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\SplFileInfo;
use TranslationAudit\Data\DynamicTranslationKey;
use TranslationAudit\Support\TranslationCalls;

use function Safe\file_get_contents;

final class FindTranslationKeysInFile {
    private const array TRANSLATION_FUNCTIONS = ['__', 'trans', 'trans_choice'];

    private const array LANG_FACADES = [Lang::class, 'Lang'];

    private const array TRANSLATOR_METHODS = ['get', 'string', 'array', 'choice', 'has', 'hasForLocale'];

    /** The parameter of the key in Laravel's translation calls. */
    private const string KEY_PARAMETER = 'key';

    /**
     * Find the translation keys passed to the translation helpers, the Lang facade, the translator and the custom translation calls, compiling the Blade views first.
     * A key built from static texts and expressions, by interpolation or concatenation, is a dynamic key, while a key without static texts is skipped.
     *
     * @return list<non-falsy-string|DynamicTranslationKey>
     */
    public function handle(SplFileInfo $file, TranslationCalls $translation_calls): array {
        $parser = (new ParserFactory)->createForHostVersion();

        $content = file_get_contents($file->getPathname());

        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $content = Blade::compileString($content);
        }

        $statements = new NodeTraverser(new NameResolver)->traverse($parser->parse($content) ?? []);
        $calls = new NodeFinder()->findInstanceOf($statements, CallLike::class);

        $keys = array_map(
            fn (CallLike $call): string|DynamicTranslationKey|null => $this->getTranslationKeyFromCall($call, $this->getKeyPosition($call, $translation_calls)),
            $calls,
        );

        return array_values(array_filter($keys));
    }

    /**
     * Where the key of a translation call is passed, or null for a call not translating.
     *
     * @return int<0, max>|self::KEY_PARAMETER|null
     */
    private function getKeyPosition(CallLike $call, TranslationCalls $translation_calls): int|string|null {
        return match (true) {
            $call instanceof FuncCall => $this->getFunctionKeyPosition($call, $translation_calls),
            $call instanceof StaticCall => $this->getStaticMethodKeyPosition($call, $translation_calls),
            $call instanceof MethodCall => $this->getMethodKeyPosition($call),
            default => null,
        };
    }

    /**
     * Where the key of a call to a translation helper, or to a custom translation function, is passed.
     *
     * @return int<0, max>|self::KEY_PARAMETER|null
     */
    private function getFunctionKeyPosition(FuncCall $call, TranslationCalls $translation_calls): int|string|null {
        if (! $call->name instanceof Name) {
            return null;
        }

        if (\in_array($call->name->toString(), self::TRANSLATION_FUNCTIONS, true)) {
            return self::KEY_PARAMETER;
        }

        // An unqualified function called in a namespace is either the namespaced function or, as a fallback, the global one.
        $namespaced_name = $call->name->getAttribute('namespacedName');

        return $translation_calls->functionKeyPosition(...($namespaced_name instanceof Name ? [$namespaced_name->toString(), $call->name->toString()] : [$call->name->toString()]));
    }

    /**
     * Where the key of a call to the Lang facade, or to a custom translation static method, is passed.
     *
     * @return int<0, max>|self::KEY_PARAMETER|null
     */
    private function getStaticMethodKeyPosition(StaticCall $call, TranslationCalls $translation_calls): int|string|null {
        if (! $call->class instanceof Name || ! $call->name instanceof Identifier) {
            return null;
        }

        if (\in_array($call->class->toString(), self::LANG_FACADES, true) && $this->isTranslatorMethod($call->name)) {
            return self::KEY_PARAMETER;
        }

        // A class without a namespace may be an alias, like the facades used in the Blade views.
        $class = $call->class->toString();
        $aliases = AliasLoader::getInstance()->getAliases();

        return $translation_calls->staticMethodKeyPosition(\is_string($aliases[$class] ?? null) ? $aliases[$class] : $class, $call->name->toString());
    }

    /**
     * Where the key of a call to the translator is passed.
     *
     * @return self::KEY_PARAMETER|null
     */
    private function getMethodKeyPosition(MethodCall $call): ?string {
        return $this->isTranslatorInstance($call->var) && $this->isTranslatorMethod($call->name) ? self::KEY_PARAMETER : null;
    }

    /**
     * @param  int<0, max>|self::KEY_PARAMETER|null  $position  The position of the key argument, the key parameter of Laravel's translation calls, passed first or by name, or null for a call not translating.
     */
    private function getTranslationKeyFromCall(CallLike $call, int|string|null $position): string|DynamicTranslationKey|null {
        if ($position === null || $call->isFirstClassCallable()) {
            return null;
        }

        $key_argument = $this->getKeyArgument(array_values($call->getArgs()), $position);

        if (! $key_argument instanceof Arg) {
            return null;
        }

        if ($key_argument->value instanceof String_) {
            return $key_argument->value->value === '' ? null : $key_argument->value->value;
        }

        $segments = $this->toSegments($this->getKeyParts($key_argument->value));

        if (implode('', $segments) === '') {
            return null;
        }

        return \count($segments) === 1 ? $segments[0] : new DynamicTranslationKey($segments);
    }

    /**
     * The argument passed at the position, or Laravel's key parameter passed first or by name.
     * A named argument is never at a position, since the positional arguments come first.
     *
     * @param  list<Arg>  $arguments
     * @param  int<0, max>|self::KEY_PARAMETER  $position
     */
    private function getKeyArgument(array $arguments, int|string $position): ?Arg {
        if (\is_string($position)) {
            return array_find($arguments, fn (Arg $argument): bool => $argument->name?->toString() === $position)
                ?? (isset($arguments[0]) && $arguments[0]->name === null ? $arguments[0] : null);
        }

        $argument = $arguments[$position] ?? null;

        return $argument !== null && $argument->name === null && ! $argument->unpack ? $argument : null;
    }

    /**
     * Split the key into its static texts and, as null, its dynamic parts.
     *
     * @return list<string|null>
     */
    private function getKeyParts(Expr $expr): array {
        return match (true) {
            $expr instanceof String_ => [$expr->value],
            $expr instanceof InterpolatedString => array_values(array_map(fn (Expr|InterpolatedStringPart $part): ?string => $part instanceof InterpolatedStringPart ? $part->value : null, $expr->parts)),
            $expr instanceof Concat => [...$this->getKeyParts($expr->left), ...$this->getKeyParts($expr->right)],
            default => [null],
        };
    }

    /**
     * Join the key parts into the static texts between its dynamic parts, so a dynamic part separates two segments.
     * Consecutive static texts join into one segment, and consecutive dynamic parts count as a single one.
     * A key starting or ending with a dynamic part has an empty first or last segment, a key without dynamic parts a single segment.
     * E.g. ['payments.', null, '.label'] gives ['payments.', '.label'], [null, '.title'] gives ['', '.title'],
     * ['messages.', null, null] gives ['messages.', ''], and ['messages.', 'welcome'] gives ['messages.welcome'].
     *
     * @param  list<string|null>  $parts  The static texts and, as null, the dynamic parts of the key.
     * @return non-empty-list<string>
     */
    private function toSegments(array $parts): array {
        $segments = [''];

        foreach ($parts as $part) {
            if ($part !== null) {
                $segments[array_key_last($segments)] .= $part;
            } elseif (\count($segments) === 1 || end($segments) !== '') {
                $segments[] = '';
            }
        }

        return $segments;
    }

    private function isTranslatorMethod(Identifier|Expr $name): bool {
        return $name instanceof Identifier && \in_array($name->toString(), self::TRANSLATOR_METHODS, true);
    }

    /** Whether the expression returns the translator: `trans()` without arguments, or `app('translator')`. */
    private function isTranslatorInstance(Expr $expr): bool {
        if (! $expr instanceof FuncCall || ! $expr->name instanceof Name || $expr->isFirstClassCallable()) {
            return false;
        }

        $arguments = $expr->getArgs();

        return match ($expr->name->toString()) {
            'trans' => $arguments === [],
            'app' => isset($arguments[0]) && $arguments[0]->value instanceof String_ && $arguments[0]->value->value === 'translator',
            default => false,
        };
    }
}
