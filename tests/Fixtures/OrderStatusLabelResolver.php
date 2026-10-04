<?php

declare(strict_types=1);

namespace TranslationAudit\Tests\Fixtures;

use TranslationAudit\Contracts\TranslationKeyResolver;

/** Resolve the label keys of the order statuses, built as `__('orders.status.'.str($this->value)->replace('_', '-'))` in the app/Enums files. */
final class OrderStatusLabelResolver implements TranslationKeyResolver {
    public function resolve(): iterable {
        foreach (OrderStatus::cases() as $case) {
            yield 'orders.status.'.str_replace('_', '-', $case->value);
        }
    }

    public function covers(): array {
        return ['app/Enums/*.php' => 'orders.status.*'];
    }
}
