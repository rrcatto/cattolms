<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Configuration\{RuntimeSettings, SiteMode, SiteOwner};
use CattoLearning\Infrastructure\Persistence\{Database, OptionRepository};
use CattoLearning\Tests\Support\{DevelopmentFixture, IntegrationContainer};
use CattoLearning\View\ThemeRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Twig\Environment;
use Twig\Loader\{ArrayLoader, ChainLoader, FilesystemLoader};

/**
 * The Site Owner (owner, 2026-10-18): the business hosting the site, defined in .env and overridden in
 * Settings. Overrides only where a value differs from .env; nothing saved when a value is wrong; every
 * change in the Activity log with its old and new value; its own Settings section; its bank details on
 * an order's EFT instructions on a live site.
 */
#[Group('commerce')]
final class SiteOwnerSettingsIntegrationTest extends TestCase
{
    private Database $db;
    private OptionRepository $options;
    private int $actor;
    /** @var array<string,mixed> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->options = new OptionRepository($this->db);
        $this->env = $_ENV;
        foreach (array_keys(SiteOwner::FIELDS) as $field) $this->options->delete('site_owner.' . $field);
        $_ENV = array_merge($_ENV, ['SITE_OWNER_TRADING_NAME' => 'Placeholder Learning', 'SITE_OWNER_LEGAL_NAME' => 'Placeholder Learning (Pty) Ltd', 'SITE_OWNER_LEGAL_FORM' => 'company',
            'SITE_OWNER_REGISTRATION_NUMBER' => '2026/111111/07', 'SITE_OWNER_ADDRESS' => '1 Placeholder Road, Placeholder Town, 0001', 'SITE_OWNER_PHONE' => '+27 11 000 0000',
            'SITE_OWNER_BANK_NAME' => '', 'SITE_OWNER_BANK_ACCOUNT_NAME' => '', 'SITE_OWNER_BANK_ACCOUNT_NUMBER' => '', 'SITE_OWNER_BANK_BRANCH_CODE' => '']);
        $this->actor = (new DevelopmentFixture($this->db))->createUser('Site Owner fixture');
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        $this->db->rollBack();
    }

    private function owner(): SiteOwner
    {
        return new SiteOwner($this->options, new MockClock('2026-10-18T12:00:00+02:00'));
    }

    /**
     * @param array<string,string> $changes
     * @return array<string,string>
     */
    private function input(array $changes = []): array
    {
        $values = [];
        foreach (array_keys(SiteOwner::FIELDS) as $field) $values[$field] = $this->owner()->value($field);
        return $changes + $values;
    }

    public function testDetailsComeFromEnvAndOnlyAChangedValueIsSavedAsAnOverride(): void
    {
        $owner = $this->owner();
        self::assertSame(['Placeholder Learning', 'environment'], [$owner->value('trading_name'), $owner->source('trading_name')]);
        $owner->save($this->input(['phone' => '+27 21 999 9999']), $this->actor);
        self::assertSame(['+27 21 999 9999', 'database'], [$owner->value('phone'), $owner->source('phone')]);
        self::assertSame('environment', $owner->source('trading_name'), 'an unchanged value is not saved over .env');
        $owner->save($this->input(['phone' => '+27 11 000 0000']), $this->actor);
        self::assertSame('environment', $owner->source('phone'), 'a value set back to .env removes the override');
        $owner->save($this->input(['legal_name' => 'Changed Name']), $this->actor);
        $owner->reset();
        self::assertSame('Placeholder Learning (Pty) Ltd', $owner->value('legal_name'));
    }

    public function testAWrongDetailSavesNothing(): void
    {
        foreach ([['bank_name' => 'A Bank'], ['email' => 'not-an-email'], ['legal_form' => 'partnership'], ['bank_name' => 'A Bank', 'bank_account_name' => 'A', 'bank_account_number' => '12x', 'bank_branch_code' => '123456']] as $wrong) {
            try {
                $this->owner()->save($this->input(['trading_name' => 'Should Not Save'] + $wrong), $this->actor);
                self::fail('Saved ' . json_encode($wrong));
            } catch (\InvalidArgumentException) {
                self::assertSame('Placeholder Learning', $this->owner()->value('trading_name'));
            }
        }
    }

    public function testEveryChangeIsInTheActivityLogWithItsOldAndNewValue(): void
    {
        $service = IntegrationContainer::get()->get(PlatformAdministrationService::class);
        $service->saveSiteOwner($this->input(['phone' => '+27 21 555 0000', 'information_officer' => 'Placeholder Officer']), $this->actor);
        $row = $this->db->fetchAssociative("SELECT user_id, metadata FROM audit_log WHERE event_key = 'site_owner.updated' ORDER BY id DESC LIMIT 1");
        self::assertIsArray($row);
        self::assertSame($this->actor, (int) $row['user_id']);
        $changes = json_decode((string) $row['metadata'], true)['changes'];
        self::assertEquals(['old' => '+27 11 000 0000', 'new' => '+27 21 555 0000'], $changes['phone']);
        self::assertSame('Placeholder Officer', $changes['information_officer']['new']);
        self::assertArrayNotHasKey('trading_name', $changes, 'only what changed');
        $service->resetSiteOwner($this->actor);
        $reset = json_decode((string) $this->db->fetchOne("SELECT metadata FROM audit_log WHERE event_key = 'site_owner.updated' ORDER BY id DESC LIMIT 1"), true);
        self::assertEquals(['old' => '+27 21 555 0000', 'new' => '+27 11 000 0000'], $reset['changes']['phone']);
    }

    public function testSettingsShowTheSiteOwnerAndCommerceAsSectionsWithTheirOwnForms(): void
    {
        $settings = IntegrationContainer::get()->get(RuntimeSettings::class);
        $html = IntegrationContainer::get()->get(ThemeRenderer::class)->renderFragment('partials/admin/settings', [
            'platform_name' => 'Test LMS', 'platform_name_source' => 'environment', 'mail_settings' => $settings->mailAdminView(),
            'site_mode' => ['mode' => 'demo', 'source' => 'environment', 'environment' => 'demo'],
            'site_owner' => IntegrationContainer::get()->get(PlatformAdministrationService::class)->siteOwnerView(),
            'commerce_fields' => \CattoLearning\Commerce\Http\CommerceSettingsController::fieldsView(IntegrationContainer::get()->get(\CattoLearning\Commerce\Settings\CommerceSettings::class)),
            'can_manage_settings' => true, 'settings_open_section' => 'site-owner',
            'maintenance' => ['login_tokens' => 0, 'auth_sessions' => 0, 'web_sessions' => 0],
        ]);
        $dom = new \DOMDocument(); @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        self::assertSame(1, $xpath->query('//details[@id="settings-site-owner"][@open]')->length);
        foreach (['settings-identity' => '/admin/settings', 'settings-mail' => '/admin/settings/mail', 'settings-site-owner' => '/admin/settings/site-owner', 'settings-site' => '/admin/settings/site-mode',
            'commerce-settings-fees' => '/admin/settings/commerce/fees', 'commerce-settings-wallet' => '/admin/settings/commerce/wallet'] as $section => $action) {
            self::assertSame(1, $xpath->query('//details[@id="' . $section . '"]//form[@action="' . $action . '"]//button[@type="submit"]')->length, $section);
        }
        self::assertSame(0, $xpath->query('//input[@name="system_company_name" or @name="system_company_domain"]')->length, 'There is no System Company.');
        foreach (array_keys(SiteOwner::FIELDS) as $field) {
            self::assertSame(1, $xpath->query('//form[@action="/admin/settings/site-owner"]//*[@name="' . $field . '"]')->length, $field);
        }
    }

    /** The order page's EFT instructions show the Site Owner's bank account on a live site. */
    public function testALiveOrderShowsTheSiteOwnersBankDetails(): void
    {
        $owner = $this->owner();
        $live = new RuntimeSettings($this->options, IntegrationContainer::get()->get(\CattoLearning\Configuration\SecretCipher::class), SiteMode::fixed('live'), $owner);
        self::assertStringContainsString('check this order again for the bank details', $this->orderHtml($live));
        $owner->save($this->input(['bank_name' => 'Placeholder Bank', 'bank_account_name' => 'Placeholder Learning', 'bank_account_number' => '00123456789', 'bank_branch_code' => '001234']), $this->actor);
        $html = $this->orderHtml($live);
        self::assertStringContainsString('Placeholder Bank', $html);
        self::assertStringContainsString('00123456789', $html, 'leading zeroes kept');
        self::assertStringContainsString('Use your account reference as the payment reference: <strong>CL-20261017-0001-U</strong>', $html);
    }

    private function orderHtml(RuntimeSettings $settings): string
    {
        $files = new FilesystemLoader();
        $files->addPath(dirname(__DIR__, 2) . '/resources/views', 'platform');
        $twig = new Environment(new ChainLoader([new ArrayLoader(['test-layout' => '{% block page_body %}{% endblock %}']), $files]), ['strict_variables' => true]);
        $twig->addExtension(new \CattoLearning\View\Twig\PlatformUiExtension(new \CattoLearning\View\Ui\PlatformUi()));
        return $twig->render('@platform/pages/commerce-order.html.twig', [
            'layout' => 'test-layout', 'platform' => ['icon_sprite' => '/icons.svg'], 'app' => ['name' => 'Test LMS'], 'order' => ['id' => 123, 'state' => 'awaiting_payment', 'total_minor' => 10000, 'company_id' => null, 'payment_due_at' => '2026-09-20', 'details' => ['items' => [], 'payment_method' => 'eft'], 'documents' => [], 'payments' => []],
            'total_label' => 'R 100.00', 'totals' => [['label' => 'Total', 'value' => 'R 100.00']], 'can_pay' => true, 'payment_method' => 'eft', 'change_method' => false, 'bank_details' => $settings->payableBankDetails(),
            'billing_lines' => \CattoLearning\Tests\Support\BillingFixture::person()->documentLines(), 'site_simulation' => SiteMode::SIMULATION,
            'account_reference' => 'CL-20261017-0001-U', 'due_minor' => 10000, 'due_label' => 'R 100.00', 'payment_due_words' => '7 days',
        ]);
    }
}
