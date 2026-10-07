<?php

declare(strict_types=1);

namespace TranslationAudit\Support;

use RuntimeException;
use Throwable;
use TranslationAudit\Console\Commands\AuditCommand;
use TranslationAudit\Exceptions\InvalidConfigException;
use TranslationAudit\Results\Contracts\Result;

/**
 * The hooks run before and after the audit, by calling the before and after methods of the hook classes.
 * Each hook is resolved from the container once per run, so its before and after methods share the instance.
 */
final class AuditHooks {
    private const string INVALID_HOOK = 'The "hooks" config must contain only classes defining a before or an after method.';

    private const string INVALID_COMMANDS = 'The "hooks" config must map each hook class to the command class, or the list of command classes, extending '.AuditCommand::class.' to run on.';

    /** @var array<class-string, object> */
    private array $instances = [];

    /**
     * @param  list<array{class-string, list<class-string<AuditCommand<*>>>|null}>  $hooks  Each hook class, with the command classes it runs on, or null to run on every command.
     */
    private function __construct(private readonly array $hooks) {}

    /**
     * Build the hooks from the `hooks` config, which lists the hook classes running on every command,
     * or maps them to the command class, or the list of command classes, to run on.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws InvalidConfigException
     */
    public static function fromConfig(array $config): self {
        $hooks = [];

        foreach ($config as $key => $value) {
            $hooks[] = \is_int($key)
                ? [self::getHookClass($value), null]
                : [self::getHookClass($key), self::getCommandClasses($value)];
        }

        return new self($hooks);
    }

    /**
     * Run the before method of the hooks running on the command, called with the command.
     *
     * @param  AuditCommand<*>  $command
     *
     * @throws RuntimeException Naming the failing hook.
     */
    public function before(AuditCommand $command): void {
        $this->run('before', $command, ['command' => $command, AuditCommand::class => $command, $command::class => $command]);
    }

    /**
     * Run the after method of the hooks running on the command, called with the command and its result.
     *
     * @param  AuditCommand<*>  $command
     *
     * @throws RuntimeException Naming the failing hook.
     */
    public function after(AuditCommand $command, Result $result): void {
        $this->run('after', $command, ['command' => $command, AuditCommand::class => $command, $command::class => $command, 'result' => $result, Result::class => $result, $result::class => $result]);
    }

    /**
     * Call the method of the hooks running on the command and defining it, in the order of the config,
     * passing the parameters by name or by class and resolving the others from the container.
     *
     * @param  AuditCommand<*>  $command
     * @param  array<string, object>  $parameters
     *
     * @throws RuntimeException
     */
    private function run(string $method, AuditCommand $command, array $parameters): void {
        foreach ($this->hooks as [$class, $commands]) {
            if (! method_exists($class, $method) || ! $this->runsOn($command, $commands)) {
                continue;
            }

            try {
                $hook = $this->instances[$class] ??= app($class);

                app()->call($hook->{$method}(...), $parameters);
            } catch (Throwable $e) {
                throw new RuntimeException("Unable to run the {$method} hook {$class}: {$e->getMessage()}", (int) $e->getCode(), previous: $e);
            }
        }
    }

    /**
     * @param  AuditCommand<*>  $command
     * @param  list<class-string<AuditCommand<*>>>|null  $commands
     */
    private function runsOn(AuditCommand $command, ?array $commands): bool {
        if ($commands === null) {
            return true;
        }

        return array_any($commands, fn (string $class): bool => $command instanceof $class);
    }

    /**
     * @return class-string
     *
     * @throws InvalidConfigException
     */
    private static function getHookClass(mixed $class): string {
        if (! \is_string($class) || ! class_exists($class) || (! method_exists($class, 'before') && ! method_exists($class, 'after'))) {
            throw new InvalidConfigException(self::INVALID_HOOK);
        }

        return $class;
    }

    /**
     * @return non-empty-list<class-string<AuditCommand<*>>>
     *
     * @throws InvalidConfigException
     */
    private static function getCommandClasses(mixed $commands): array {
        if (\is_string($commands)) {
            $commands = [$commands];
        }

        if (! \is_array($commands) || $commands === [] || ! array_is_list($commands)) {
            throw new InvalidConfigException(self::INVALID_COMMANDS);
        }

        $classes = [];

        foreach ($commands as $command) {
            if (! \is_string($command) || ! is_subclass_of($command, AuditCommand::class)) {
                throw new InvalidConfigException(self::INVALID_COMMANDS);
            }

            $classes[] = $command;
        }

        return $classes;
    }
}
