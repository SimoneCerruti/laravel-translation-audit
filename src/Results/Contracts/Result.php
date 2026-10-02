<?php

declare(strict_types=1);

namespace TranslationAudit\Results\Contracts;

use Symfony\Component\Console\Output\OutputInterface;
use TranslationAudit\Enums\DisplayFormat;

/** The result of a command, living in the Results namespace as <Command>Result. */
interface Result {
    /** Whether the result holds nothing to report. */
    public function isClean(): bool;

    /** Print the result in the display format. */
    public function print(DisplayFormat $format, OutputInterface $output): void;
}
