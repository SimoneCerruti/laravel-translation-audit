<?php

declare(strict_types=1);

namespace TranslationAudit\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Laravel\AgentDetector\AgentDetector;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClassConstant;
use TranslationAudit\TranslationAuditServiceProvider;

abstract class TestCase extends Orchestra {
    /** Throwaway application base path, isolated per test so parallel runs never share fixtures. */
    protected string $temporary_base_path;

    /**
     * The agent environment variables of the process running the tests, unset while each test runs
     * so the audit does not detect an agent unless the test simulates one.
     *
     * @var array<string, string>
     */
    private array $agent_environment = [];

    protected function setUp(): void {
        parent::setUp();

        $this->clearAgentEnvironment();

        $this->temporary_base_path = sys_get_temp_dir().'/translation-audit-'.bin2hex(random_bytes(8));

        File::ensureDirectoryExists($this->temporary_base_path);

        $this->app->setBasePath($this->temporary_base_path);
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
        Lang::clearResolvedInstance('translator');
    }

    protected function tearDown(): void {
        File::deleteDirectory($this->temporary_base_path);

        $this->restoreAgentEnvironment();

        parent::tearDown();
    }

    /**
     * The constant listing the known agent variables is read through reflection
     * because it is protected in the versions before laravel/agent-detector v2.0.2.
     * This caused CI test with prefer-lowest to fail.
     *
     * @return list<string>
     */
    private function agentEnvironmentVariables(): array {
        /** @var array<string, mixed> $known_agent_variables */
        $known_agent_variables = new ReflectionClassConstant(AgentDetector::class, 'AGENT_ENV_VARS')->getValue();

        return ['AI_AGENT', 'CLAUDE_CODE_IS_COWORK', ...array_keys($known_agent_variables)];
    }

    private function clearAgentEnvironment(): void {
        foreach ($this->agentEnvironmentVariables() as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $this->agent_environment[$name] = $value;
            }

            putenv($name);
        }
    }

    private function restoreAgentEnvironment(): void {
        foreach ($this->agentEnvironmentVariables() as $name) {
            putenv(isset($this->agent_environment[$name]) ? "{$name}={$this->agent_environment[$name]}" : $name);
        }

        $this->agent_environment = [];
    }

    protected function getPackageProviders($app): array {
        return [
            TranslationAuditServiceProvider::class,
        ];
    }
}
