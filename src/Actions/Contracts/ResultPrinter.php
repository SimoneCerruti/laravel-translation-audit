<?php

declare(strict_types=1);

namespace TranslationAudit\Actions\Contracts;

use Symfony\Component\Console\Output\OutputInterface;
use TranslationAudit\Data\AuditResult;

interface ResultPrinter {
    /**
     * Print the audit result in the display format of the printer.
     */
    public function handle(AuditResult $result, OutputInterface $output): void;
}
