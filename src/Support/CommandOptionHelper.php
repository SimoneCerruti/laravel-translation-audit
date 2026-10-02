<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use BackedEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
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
        if (! $this->hasOptionParam($name)) {
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
        if (! $this->hasOptionParam($name) && $default !== null) {
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

    /**
     * The case of the string backed enum given by the option or, when it is not passed, by the config, which accepts a case or its value.
     * The error for a value of no case names it after the option, and the supported values after its last word: e.g. `Invalid display format '…'. Supported formats: …`.
     *
     * @template TEnum of BackedEnum
     *
     * @param  non-falsy-string  $name
     * @param  non-falsy-string  $config
     * @param  class-string<TEnum>  $enum
     * @return TEnum
     */
    public function enumOrConfig(string $name, string $config, string $enum): BackedEnum {
        if ($this->hasOptionParam($name)) {
            $value = $this->nonEmptyString($name);
        } else {
            $config_value = config($config);

            if ($config_value instanceof $enum) {
                return $config_value;
            }

            $value = config()->string($config);
        }

        $case = $enum::tryFrom($value);

        if (! $case instanceof $enum) {
            $label = str_replace('-', ' ', $name);

            throw new InvalidConfigException(\sprintf(
                "Invalid %s '%s'. Supported %s: %s",
                $label,
                $value,
                Str::plural(Str::afterLast($label, ' ')),
                implode(', ', array_column($enum::cases(), 'value')),
            ));
        }

        return $case;
    }

    /** Whether the option is passed to the command, with or without a value. */
    private function hasOptionParam(string $name): bool {
        return $this->input->hasParameterOption("--{$name}", onlyParams: true);
    }
}
