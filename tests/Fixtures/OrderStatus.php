<?php

declare(strict_types=1);

namespace TranslationAudit\Tests\Fixtures;

enum OrderStatus: string {
    case PendingPayment = 'pending_payment';
    case Shipped = 'shipped';
}
