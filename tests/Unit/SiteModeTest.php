<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Configuration\SiteMode;
use PHPUnit\Framework\TestCase;

/** APP_SITE_MODE=demo|live (owner, 2026-10-18): one setting, and anything but live is the safe demo. */
final class SiteModeTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->env = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
    }

    public function testOnlyLiveIsLiveAndEveryOtherValueIsADemo(): void
    {
        self::assertSame('live', SiteMode::fixed(' Live ')->mode());
        foreach (['demo', 'production', 'development', 'test', ''] as $value) {
            self::assertTrue(SiteMode::fixed($value)->isDemo(), $value . ' is a demo site');
        }
    }

    public function testTheEnvironmentSettingDecidesWhenNothingOverridesIt(): void
    {
        $_ENV['APP_SITE_MODE'] = 'live';
        $site = new SiteMode();
        self::assertSame(['live', 'environment', 'live'], [$site->mode(), $site->source(), $site->environmentMode()]);
        $_ENV['APP_SITE_MODE'] = '';
        self::assertSame(['demo', 'default'], [$site->mode(), $site->source()], 'a site without the setting is a demo site');
    }

    public function testAFixedModeCannotBeSaved(): void
    {
        $this->expectExceptionMessage('This site mode is fixed.');
        SiteMode::fixed('demo')->save('live', 1);
    }

    public function testOnlyDemoOrLiveCanBeSaved(): void
    {
        $this->expectExceptionMessage('Choose demo or live.');
        SiteMode::fixed('demo')->save('staging', 1);
    }

    /** The words follow the platform's page text rules (LegalDocumentsTest), and the fake details say they are fake. */
    public function testTheWordsAreEverydayEnglishAndTheBankDetailsSayTheyAreFake(): void
    {
        foreach ([SiteMode::NOTICE, SiteMode::SIMULATION, SiteMode::EMAIL_NOTICE] as $text) {
            self::assertStringNotContainsString(';', $text);
            self::assertStringNotContainsString('—', $text);
        }
        self::assertStringContainsString('demo site', SiteMode::NOTICE);
        self::assertStringContainsString('simulation', SiteMode::NOTICE);
        self::assertStringContainsString('nothing is being bought or sold', SiteMode::SIMULATION);
        foreach (SiteMode::FAKE_BANK_DETAILS as $field => $value) {
            self::assertMatchesRegularExpression('/fake/i', $value, $field . ' is labelled as fake');
        }
    }
}
