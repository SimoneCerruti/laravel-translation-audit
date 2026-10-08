<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use Illuminate\Support\Collection;
use PhpParser\Error;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use PhpParser\Token;

use function Safe\preg_match;

/** The source of a PHP translation file returning a literal array, whose items can be removed leaving the rest of the source untouched. */
final readonly class PhpTranslationFile {
    /** The rest of a line after an item holding nothing else than spaces and a comment, up to its line break or the end of the source. */
    private const string LINE_REST_PATTERN = <<<'REGEX'
        /\A[ \t]*
        (?: (?:\/\/|\#).*             # a line comment
          | \/\*.*?\*\/[ \t]* )?     # or a block comment on the line
        \r?\n?\z/x
        REGEX;

    /**
     * @param  array<int, Token>  $tokens  The tokens of the source, to find the comma following an item.
     * @param  array<string, list<array{item: ArrayItem, parents: list<ArrayItem>}>>  $items  The items with a literal key path, keyed by their dotted key like Arr::dot names them, each with its parent items from the outermost one.
     */
    private function __construct(private string $source, private array $tokens, private array $items) {}

    /**
     * Parse the source of a PHP translation file.
     * Null when the source cannot be parsed or does not return a literal array, like return array_merge(...) or $lines = [...]; return $lines;
     */
    public static function parse(string $source): ?self {
        $parser = (new ParserFactory)->createForHostVersion();

        try {
            $statements = $parser->parse($source) ?? [];
        } catch (Error) {
            return null;
        }

        foreach ($statements as $statement) {
            if ($statement instanceof Return_) {
                return $statement->expr instanceof Array_ ? new self($source, $parser->getTokens(), self::indexItems($statement->expr)) : null;
            }
        }

        return null;
    }

    /** Whether the source has an item with the dotted key, like Arr::dot names it, that can be removed. */
    public function has(string $line_key): bool {
        return \array_key_exists($line_key, $this->items);
    }

    /**
     * The source without the items of the dotted keys, like Arr::dot names them, along with their parents left empty and the commas following them.
     * The keys that cannot be removed are skipped, see has().
     *
     * @param  Collection<int, string>  $line_keys
     */
    public function withoutKeys(Collection $line_keys): string {
        $items_to_remove = $line_keys->flatMap(fn (string $line_key): array => $this->items[$line_key] ?? [])->values()->all();
        $ranges = array_map($this->getRemovalRange(...), $this->collapseEmptyParents($items_to_remove));
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        // Copying the source between the ranges, which can overlap: the ranges of nested items are inside their parent's one, and two items sharing a line share the comma between them.
        $source = '';
        $offset = 0;

        foreach ($ranges as [$start, $end]) {
            if ($start > $offset) {
                $source .= substr($this->source, $offset, $start - $offset);
            }

            $offset = max($offset, $end);
        }

        return $source.substr($this->source, $offset);
    }

    /**
     * The items to remove along with their parents left empty, from the deepest one, so that no empty array is left in the source.
     * A parent is left empty only when all its items are removed: an item that cannot be removed, like one with a key that is not literal, keeps its parents.
     *
     * @param  array<int, array{item: ArrayItem, parents: list<ArrayItem>}>  $items_to_remove
     * @return list<ArrayItem>
     */
    private function collapseEmptyParents(array $items_to_remove): array {
        /** @var array<int, ArrayItem> $removed */
        $removed = [];

        foreach ($items_to_remove as ['item' => $item, 'parents' => $parents]) {
            $removed[spl_object_id($item)] = $item;

            foreach (array_reverse($parents) as $parent) {
                if (! $parent->value instanceof Array_ || ! array_all($parent->value->items, fn (ArrayItem $child): bool => isset($removed[spl_object_id($child)]))) {
                    break;
                }

                $removed[spl_object_id($parent)] = $parent;
            }
        }

        return array_values($removed);
    }

    /**
     * The byte range of the item in the source, from its start to its end excluded, along with the comma following it.
     * An item alone on its lines takes the whole lines, from the indentation to the line break, a comment following its comma included,
     * while an item sharing its line takes the spaces following its comma, or the comma before it when no comma follows it.
     * The comments before the item are left in the source.
     *
     * @return array{int, int}
     */
    private function getRemovalRange(ArrayItem $item): array {
        $start = $item->getStartFilePos();
        $end = $item->getEndFilePos() + 1;
        $next = $this->findTokenAfter($item->getEndTokenPos());

        if ($next?->text === ',') {
            $end = $next->pos + 1;
        }

        $line_start = strrpos(substr($this->source, 0, $start), "\n");
        $line_start = $line_start === false ? 0 : $line_start + 1;

        $is_alone_before = trim(substr($this->source, $line_start, $start - $line_start)) === '';
        $line_rest_length = $this->getLineRestLength($end);

        if ($is_alone_before && $line_rest_length !== null) {
            return [$line_start, $end + $line_rest_length];
        }

        if ($next?->text === ',') {
            return [$start, $end + strspn($this->source, " \t", $end)];
        }

        $previous = $this->findTokenBefore($item->getStartTokenPos());

        return [$previous?->text === ',' ? $previous->pos : $start, $end];
    }

    /** The length of the rest of the line from the offset, its line break included, when it holds only spaces and a comment, null otherwise. */
    private function getLineRestLength(int $offset): ?int {
        $line_end = strpos($this->source, "\n", $offset);
        $length = $line_end === false ? \strlen($this->source) - $offset : $line_end - $offset + 1;

        return preg_match(self::LINE_REST_PATTERN, substr($this->source, $offset, $length)) === 1 ? $length : null;
    }

    /** The first token after the position that is not a whitespace or a comment. */
    private function findTokenAfter(int $position): ?Token {
        return $this->findSignificantToken($position, 1);
    }

    /** The last token before the position that is not a whitespace or a comment. */
    private function findTokenBefore(int $position): ?Token {
        return $this->findSignificantToken($position, -1);
    }

    /** The first token that is not a whitespace or a comment, moving from the token at the position by the step, null at the edges of the source. */
    private function findSignificantToken(int $position, int $step): ?Token {
        $position += $step;

        while (isset($this->tokens[$position])) {
            if (! $this->tokens[$position]->isIgnorable()) {
                return $this->tokens[$position];
            }

            $position += $step;
        }

        return null;
    }

    /**
     * Index the items of the array whose key path is literal, keyed by their dotted key, descending into the nested arrays.
     * An item with a key that is not literal is skipped along with its nested items, since its key is known only running the code,
     * and so are the items without a key following it or an unpacked array, since PHP numbers them after the integer keys before them.
     *
     * @param  list<ArrayItem>  $parents
     * @return array<string, list<array{item: ArrayItem, parents: list<ArrayItem>}>>
     */
    private static function indexItems(Array_ $array, string $prefix = '', array $parents = []): array {
        $items = [];
        // The key PHP gives to the next item without a key, null once unknown. The literal integer keys are never negative, since -1 is a unary minus.
        $next_index = 0;

        foreach ($array->items as $item) {
            $key = $item->unpack ? null : self::getLiteralKey($item->key, $next_index);

            if ($key === null) {
                $next_index = null;

                continue;
            }

            if (\is_int($key) && $next_index !== null) {
                $next_index = max($next_index, $key + 1);
            }

            $dotted_key = "{$prefix}{$key}";

            if (! $item->value instanceof Array_) {
                $items[$dotted_key][] = ['item' => $item, 'parents' => $parents];

                continue;
            }

            // Different key paths can share the dotted key, like 'a' => ['b.c' => ...] and 'a' => ['b' => ['c' => ...]].
            foreach (self::indexItems($item->value, "{$dotted_key}.", [...$parents, $item]) as $nested_key => $nested_items) {
                $items[$nested_key] = [...$items[$nested_key] ?? [], ...$nested_items];
            }
        }

        return $items;
    }

    /**
     * The key of the item like PHP casts it, the integer strings becoming integers, or the next index for an item without a key.
     * Null when the key is not a literal string or integer, or when the next index is unknown.
     */
    private static function getLiteralKey(?Expr $key, ?int $next_index): int|string|null {
        return match (true) {
            ! $key instanceof Expr => $next_index,
            $key instanceof Int_ => $key->value,
            $key instanceof String_ => (string) (int) $key->value === $key->value ? (int) $key->value : $key->value,
            default => null,
        };
    }
}
