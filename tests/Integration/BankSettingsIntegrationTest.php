<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Configuration\RuntimeSettings;
use CattoLearning\Infrastructure\Persistence\{Database, OptionRepository};
use CattoLearning\Tests\Support\{DevelopmentFixture, IntegrationContainer};
use CattoLearning\View\ThemeRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\{ArrayLoader, ChainLoader, FilesystemLoader};

/** Verifies database-only bank configuration, isolated section saves and the customer instructions. */
#[Group('commerce')]
final class BankSettingsIntegrationTest extends TestCase
{
    private Database $db;
    private OptionRepository $options;
    private RuntimeSettings $settings;
    private int $actor;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->options = new OptionRepository($this->db);
        $this->settings = IntegrationContainer::get()->get(RuntimeSettings::class);
        $this->actor = (new DevelopmentFixture($this->db))->createUser('Bank settings fixture');
        $this->options->delete('commerce_bank_details');
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    /** @return array<string,string> */
    private function details(): array
    {
        return ['bank_name'=>'Test Bank', 'account_name'=>'LMS Test Account', 'account_number'=>'00123456789', 'branch_code'=>'001234'];
    }

    public function testMissingBankDetailsDoNotUseEnvironmentInstructions(): void
    {
        $previous = getenv('COMMERCE_EFT_DETAILS');
        putenv('COMMERCE_EFT_DETAILS=This obsolete environment setting must be ignored');
        try {
            self::assertFalse($this->settings->bankDetails()['configured']);
            self::assertSame('', $this->settings->bankDetails()['account_number']);
        } finally {
            putenv($previous === false ? 'COMMERCE_EFT_DETAILS' : 'COMMERCE_EFT_DETAILS='.$previous);
        }
    }

    public function testSavingBankDetailsPreservesOtherSettingsAndLeadingZeroes(): void
    {
        $identity = $this->options->find('platform_name');
        $sender = $this->options->find('mail_from_name');
        IntegrationContainer::get()->get(PlatformAdministrationService::class)->saveBankDetails($this->details(), $this->actor);
        self::assertSame($this->details(), json_decode((string) $this->options->find('commerce_bank_details'), true));
        self::assertSame($this->details() + ['configured'=>true], $this->settings->bankDetails());
        self::assertSame($identity, $this->options->find('platform_name'));
        self::assertSame($sender, $this->options->find('mail_from_name'));
        self::assertSame($this->actor, (int) $this->db->fetchOne("SELECT updated_by_user_id FROM app_options WHERE option_key='commerce_bank_details'"));
    }

    public function testInvalidSectionDoesNotPartiallyReplaceBankDetails(): void
    {
        $this->settings->saveBankDetails($this->details(), $this->actor);
        $invalid = $this->details(); $invalid['bank_name'] = 'Changed Bank'; $invalid['branch_code'] = 'abc';
        try {
            $this->settings->saveBankDetails($invalid, $this->actor);
            self::fail('Invalid branch code was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame($this->details() + ['configured'=>true], $this->settings->bankDetails());
        }
    }

    public function testSettingsAccordionsHaveIndependentSaveForms(): void
    {
        $html = IntegrationContainer::get()->get(ThemeRenderer::class)->renderFragment('partials/admin/settings', [
            'platform_name'=>'Test LMS', 'platform_name_source'=>'environment', 'system_company_name'=>'Test Host',
            'system_company_domain'=>'example.test', 'mail_settings'=>$this->settings->mailAdminView(),
            'bank_details'=>$this->settings->bankDetails(), 'settings_open_section'=>'bank',
            'maintenance'=>['login_tokens'=>0,'auth_sessions'=>0,'web_sessions'=>0],
        ]);
        $dom = new \DOMDocument(); @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        self::assertSame(5, $xpath->query('//details[starts-with(@id,"settings-")]')->length);
        self::assertSame(1, $xpath->query('//details[@id="settings-bank"][@open]')->length);
        foreach (['identity'=>'/admin/settings','mail'=>'/admin/settings/mail','bank'=>'/admin/settings/bank'] as $section=>$action) {
            self::assertSame(1, $xpath->query('//details[@id="settings-'.$section.'"]//form[@action="'.$action.'"]//button[@type="submit"]')->length);
        }
        self::assertSame(0, $xpath->query('//form[@action="/admin/settings/bank"]//input[@name="platform_name" or @name="smtp_host"]')->length);
    }

    /** @return string Render the real order body with a minimal, presentation-only test layout. */
    private function orderHtml(): string
    {
        $files = new FilesystemLoader();
        $files->addPath(dirname(__DIR__,2).'/resources/views', 'platform');
        $twig = new Environment(new ChainLoader([new ArrayLoader(['test-layout'=>'{% block page_body %}{% endblock %}']),$files]), ['strict_variables'=>true]);
        $twig->addExtension(new \CattoLearning\View\Twig\PlatformUiExtension(new \CattoLearning\View\Ui\PlatformUi()));
        return $twig->render('@platform/pages/commerce-order.html.twig', [
            'layout'=>'test-layout', 'platform'=>['icon_sprite'=>'/icons.svg'], 'app'=>['name'=>'Test LMS'], 'order'=>['id'=>123,'state'=>'awaiting_payment','payment_due_at'=>'2026-09-20', 'details'=>['items'=>[],'payment_method'=>'eft'],'documents'=>[],'payments'=>[]],
            'total_label'=>'R 100.00','can_pay'=>true,'payment_method'=>'eft','change_method'=>false,'bank_details'=>$this->settings->bankDetails(),
        ]);
    }

    public function testExistingOrderShowsCurrentBankDetailsAndMissingDetailsMessage(): void
    {
        self::assertStringContainsString('check this order again for the bank details', $this->orderHtml());
        $this->settings->saveBankDetails($this->details(), $this->actor);
        $html = $this->orderHtml();
        self::assertStringContainsString('00123456789', $html);
        self::assertStringContainsString('CL-00000123', $html);
        $updated = $this->details(); $updated['bank_name'] = 'Replacement Bank';
        $this->settings->saveBankDetails($updated, $this->actor);
        self::assertStringContainsString('Replacement Bank', $this->orderHtml());
        self::assertStringNotContainsString('Test Bank', $this->orderHtml());
    }
}
