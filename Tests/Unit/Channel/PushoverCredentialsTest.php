<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Tests\Unit\Channel;

use OliverThiele\OtAlerts\Channel\PushoverCredentials;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PushoverCredentialsTest extends UnitTestCase
{
    /** @var array<string, string|false> the process environment before the test */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        // A development container may hold real credentials in its environment.
        foreach ([PushoverCredentials::APP_TOKEN_VARIABLE, PushoverCredentials::USER_KEY_VARIABLE] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
            unset($_ENV[$name]);
        }
        parent::tearDown();
    }

    #[Test]
    public function credentialsAreReadFromEnv(): void
    {
        $_ENV[PushoverCredentials::APP_TOKEN_VARIABLE] = 'token';
        $_ENV[PushoverCredentials::USER_KEY_VARIABLE] = 'user';

        $credentials = PushoverCredentials::fromEnvironment();

        self::assertSame(['token', 'user'], [$credentials->appToken, $credentials->userKey]);
        self::assertTrue($credentials->isComplete());
    }

    #[Test]
    public function processEnvironmentIsReadWhenEnvLacksTheVariables(): void
    {
        // variables_order without "E": the process environment only reaches getenv().
        putenv(PushoverCredentials::APP_TOKEN_VARIABLE . '=token');
        putenv(PushoverCredentials::USER_KEY_VARIABLE . '=user');

        self::assertTrue(PushoverCredentials::fromEnvironment()->isComplete());
    }

    #[Test]
    public function oneMissingValueMakesTheCredentialsIncomplete(): void
    {
        putenv(PushoverCredentials::APP_TOKEN_VARIABLE . '=token');

        self::assertFalse(PushoverCredentials::fromEnvironment()->isComplete());
    }
}
