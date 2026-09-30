<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Support\CommandOptionHelper;

/**
 * Build a helper bound to a command exposing the `--flag` and `--text` optional-value options.
 *
 * @param  array<string, mixed>  $parameters
 */
function optionHelper(array $parameters = []): CommandOptionHelper {
    $command = new Command;
    $command->setName('test');
    $command->addOption('flag', mode: InputOption::VALUE_OPTIONAL);
    $command->addOption('text', mode: InputOption::VALUE_OPTIONAL);

    $input = new ArrayInput($parameters, $command->getDefinition());
    $command->setInput($input);

    return new CommandOptionHelper($input, $command);
}

describe('boolean', function (): void {
    it('returns the default when the option is not passed', function (bool $default): void {
        expect(optionHelper()->boolean('flag', $default))->toBe($default);
    })->with([true, false]);

    it('parses the passed value', function (?string $value, bool $expected): void {
        expect(optionHelper(['--flag' => $value])->boolean('flag', ! $expected))->toBe($expected);
    })->with([
        'no value' => [null, true],
        'true' => ['true', true],
        'false' => ['false', false],
    ]);

    it('rejects values other than true or false', function (string $value): void {
        optionHelper(['--flag' => $value])->boolean('flag', false);
    })->with(['1', '0', 'yes', 'TRUE', ''])
        ->throws(InvalidConfigException::class, 'The --flag option accepts only true or false.');
});

describe('booleanOrConfig', function (): void {
    it('falls back to the config value when the option is not passed', function (bool $config): void {
        config(['testing.flag' => $config]);

        expect(optionHelper()->booleanOrConfig('flag', 'testing.flag', ! $config))->toBe($config);
    })->with([true, false]);

    it('falls back to the default when the config is missing', function (bool $default): void {
        expect(optionHelper()->booleanOrConfig('flag', 'testing.missing', $default))->toBe($default);
    })->with([true, false]);

    it('lets the option override the config value', function (): void {
        config(['testing.flag' => true]);

        expect(optionHelper(['--flag' => 'false'])->booleanOrConfig('flag', 'testing.flag', true))->toBeFalse();
    });
});

describe('string', function (): void {
    it('returns the default when the option is not passed', function (): void {
        expect(optionHelper()->string('text', 'default'))->toBe('default');
    });

    it('returns the passed value over the default', function (string $value): void {
        expect(optionHelper(['--text' => $value])->string('text', 'default'))->toBe($value);
    })->with(['value', '']);

    it('fails when neither the option nor a default are given', function (): void {
        optionHelper()->string('text');
    })->throws(InvalidConfigException::class, 'The --text option only accepts strings.');

    it('fails when the option is passed without a value', function (): void {
        optionHelper(['--text' => null])->string('text', 'default');
    })->throws(InvalidConfigException::class, 'The --text option only accepts strings.');

    it('rejects an empty value when empty strings are not allowed', function (): void {
        optionHelper(['--text' => ''])->string('text', 'default', allow_empty: false);
    })->throws(InvalidConfigException::class, 'The --text option only accepts non-empty strings.');
});

describe('stringOrConfig', function (): void {
    it('falls back to the config value when the option is not passed', function (): void {
        config(['testing.text' => 'config']);

        expect(optionHelper()->stringOrConfig('text', 'testing.text', 'default'))->toBe('config');
    });

    it('falls back to the default when the config is missing', function (): void {
        expect(optionHelper()->stringOrConfig('text', 'testing.missing', 'default'))->toBe('default');
    });

    it('lets the option override the config value', function (): void {
        config(['testing.text' => 'config']);

        expect(optionHelper(['--text' => 'option'])->stringOrConfig('text', 'testing.text'))->toBe('option');
    });

    it('fails when the config value is not a string', function (): void {
        config(['testing.text' => 1]);

        optionHelper()->stringOrConfig('text', 'testing.text');
    })->throws(InvalidArgumentException::class);
});

describe('nonEmptyString', function (): void {
    it('returns the passed value', function (): void {
        expect(optionHelper(['--text' => 'value'])->nonEmptyString('text'))->toBe('value');
    });

    it('rejects an empty value', function (): void {
        optionHelper(['--text' => ''])->nonEmptyString('text', 'default');
    })->throws(InvalidConfigException::class, 'The --text option only accepts non-empty strings.');
});

describe('nonEmptyStringOrConfig', function (): void {
    it('falls back to the config value when the option is not passed', function (): void {
        config(['testing.text' => 'config']);

        expect(optionHelper()->nonEmptyStringOrConfig('text', 'testing.text'))->toBe('config');
    });

    it('lets the option override the config value', function (): void {
        config(['testing.text' => 'config']);

        expect(optionHelper(['--text' => 'option'])->nonEmptyStringOrConfig('text', 'testing.text'))->toBe('option');
    });

    it('rejects an empty option value', function (): void {
        config(['testing.text' => 'config']);

        optionHelper(['--text' => ''])->nonEmptyStringOrConfig('text', 'testing.text');
    })->throws(InvalidConfigException::class, 'The --text option only accepts non-empty strings.');
});
