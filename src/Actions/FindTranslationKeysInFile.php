<?php

declare(strict_types=1);

namespace TranslationAudit\Actions;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
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

use function Safe\file_get_contents;

final class FindTranslationKeysInFile {
    private const array TRANSLATION_FUNCTIONS = ['__', 'trans', 'trans_choice'];

    private const array LANG_FACADES = [Lang::class, 'Lang'];

    private const array TRANSLATOR_METHODS = ['get', 'string', 'array', 'choice', 'has', 'hasForLocale'];

    /**
     * Find the translation keys passed to the translation helpers, the Lang facade and the translator, compiling the Blade views first.
     * A key built from static texts and expressions, by interpolation or concatenation, is a dynamic key, while a key without static texts is skipped.
     *
     * @return list<non-falsy-string|DynamicTranslationKey>
     */
    public function handle(SplFileInfo $file): array {
        $parser = (new ParserFactory)->createForHostVersion();

        $content = file_get_contents($file->getPathname());

        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $content = Blade::compileString($content);
        }

        $statements = new NodeTraverser(new NameResolver)->traverse($parser->parse($content) ?? []);
        $node_finder = new NodeFinder;

        $function_calls = array_filter(
            $node_finder->findInstanceOf($statements, FuncCall::class),
            fn (FuncCall $call): bool => $call->name instanceof Name
                && $call->args !== []
                && \in_array($call->name->toString(), self::TRANSLATION_FUNCTIONS, true),
        );

        $static_calls = array_filter(
            $node_finder->findInstanceOf($statements, StaticCall::class),
            fn (StaticCall $call): bool => $call->class instanceof Name
                && \in_array($call->class->toString(), self::LANG_FACADES, true)
                && $this->isTranslatorMethod($call->name),
        );

        $method_calls = array_filter(
            $node_finder->findInstanceOf($statements, MethodCall::class),
            fn (MethodCall $call): bool => $this->isTranslatorInstance($call->var)
                && $this->isTranslatorMethod($call->name),
        );

        return array_values(
            collect([...$function_calls, ...$static_calls, ...$method_calls])
                ->values()
                ->map($this->getTranslationKeyFromCall(...))
                ->filter()
                ->all(),
        );
    }

    private function getTranslationKeyFromCall(FuncCall|StaticCall|MethodCall $call): string|DynamicTranslationKey|null {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $first_argument = $call->getArgs()[0] ?? null;

        if ($first_argument === null) {
            return null;
        }

        if ($first_argument->value instanceof String_) {
            return $first_argument->value->value === '' ? null : $first_argument->value->value;
        }

        $segments = $this->toSegments($this->getKeyParts($first_argument->value));

        if (implode('', $segments) === '') {
            return null;
        }

        return \count($segments) === 1 ? $segments[0] : new DynamicTranslationKey($segments);
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
