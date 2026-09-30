<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use TranslationAudit\Exceptions\InvalidConfigException;

final readonly class CommandOptionHelper {
    public function __construct(
        private InputInterface $input,
        private Command $command,
    ) {}

    /**
     * @param  non-falsy-string  $name
     */
    public function boolean(string $name, bool $default): bool {
        if (! $this->input->hasParameterOption("--{$name}", onlyParams: true)) {
            return $default;
        }

        return match ($this->command->option($name)) {
            null, true, 'true' => true,
            false, 'false' => false,
            default => throw new InvalidConfigException("The --{$name} option accepts only true or false."),
        };
    }

    /**
     * @param  non-falsy-string  $name
     * @param  non-falsy-string  $config
     */
    public function booleanOrConfig(string $name, string $config, bool $default): bool {
        return $this->boolean($name, config()->boolean($config, $default));
    }

    /**
     * @param  non-falsy-string  $name
     * @return ($allow_empty is false ? non-empty-string : string)
     */
    public function string(string $name, ?string $default = null, bool $allow_empty = true): string {
        if (! $this->input->hasParameterOption("--{$name}", onlyParams: true) && $default !== null) {
            return $default;
        }

        $value = $this->command->option($name);

        throw_unless(\is_string($value), InvalidConfigException::class, "The --{$name} option only accepts strings.");
        throw_if(! $allow_empty && $value === '', InvalidConfigException::class, "The --{$name} option only accepts non-empty strings.");

        return $value;
    }

    /**
     * @param  non-falsy-string  $name
     * @param  non-falsy-string  $config
     */
    public function stringOrConfig(string $name, string $config, ?string $default = null): string {
        return $this->string($name, config()->string($config, $default));
    }

    /**
     * @param  non-falsy-string  $name
     * @return non-empty-string
     */
    public function nonEmptyString(string $name, ?string $default = null): string {
        return $this->string($name, $default, allow_empty: false);
    }

    /**
     * @param  non-falsy-string  $name
     * @param  non-falsy-string  $config
     * @return non-empty-string
     */
    public function nonEmptyStringOrConfig(string $name, string $config, ?string $default = null): string {
        return $this->nonEmptyString($name, config()->string($config, $default));
    }
}
