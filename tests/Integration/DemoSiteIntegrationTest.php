<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Application\OrderService;
use CattoLearning\Commerce\Document\FinancialDocuments;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Configuration\RuntimeSettings;
use CattoLearning\Configuration\SecretCipher;
use CattoLearning\Configuration\SiteMode;
use CattoLearning\Configuration\SiteOwner;
use CattoLearning\Infrastructure\Persistence\{Database,OptionRepository,TransactionManager};
use CattoLearning\Kernel;
use CattoLearning\Support\{AccountReference,Uuid};
use CattoLearning\Tests\Support\{BillingFixture,DevelopmentFixture,IntegrationContainer,PromotionFixture,WalletFixture};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

/**
 * A demo site (APP_SITE_MODE=demo, owner 2026-10-18) says so on every page and keeps out of search
 * engines, shows fake bank details labelled as fake, and marks every order placed on it as a simulation,
 * on its invoice for good; a live site does none of that and refuses the simulated card.
 */
#[Group('commerce')]
final class DemoSiteIntegrationTest extends TestCase
{
    private Database $db;
    /** @var array<string,mixed> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->env = $_ENV;
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        if ($this->db->isTransactionActive()) $this->db->rollBack();
    }

    public function testTheSettingsOverrideBeatsTheEnvironmentAndResetReturnsToIt(): void
    {
        $_ENV['APP_SITE_MODE'] = 'live';
        $site = new SiteMode(new OptionRepository($this->db));
        $site->reset();
        self::assertSame(['live', 'environment'], [$site->mode(), $site->source()]);
        $site->save('demo', (new DevelopmentFixture($this->db))->createUser('Site mode fixture'));
        self::assertSame(['demo', 'database', 'live'], [$site->mode(), $site->source(), $site->environmentMode()]);
        $site->reset();
        self::assertSame('live', $site->mode());
    }

    public function testCustomersSeeFakeBankDetailsOnADemoSiteAndTheSiteOwnersWhenLive(): void
    {
        $owner = SiteOwner::fixed(['trading_name' => 'Placeholder', 'legal_name' => 'Placeholder', 'bank_name' => 'Real Bank', 'bank_account_name' => 'Placeholder', 'bank_account_number' => '62000000001', 'bank_branch_code' => '250655']);
        $cipher = IntegrationContainer::get()->get(SecretCipher::class);
        $live = new RuntimeSettings(new OptionRepository($this->db), $cipher, SiteMode::fixed('live'), $owner);
        self::assertSame(['Real Bank', false], [$live->payableBankDetails()['bank_name'], $live->payableBankDetails()['fake']]);
        $demo = (new RuntimeSettings(new OptionRepository($this->db), $cipher, SiteMode::fixed('demo'), $owner))->payableBankDetails();
        self::assertSame(SiteMode::FAKE_BANK_DETAILS + ['configured' => true, 'fake' => true], $demo, 'never the real account on a demo site');
        self::assertSame('62000000001', $live->bankDetails()['account_number'], 'the Site Owner keeps the real details');
    }

    public function testAnOrderPlacedOnADemoSiteIsASimulationOnItsInvoiceForGood(): void
    {
        [$buyer, $variants] = $this->shop();
        $demo = $this->orders(SiteMode::fixed('demo'));
        $order = $this->place($demo, $buyer, $variants[0]);
        $snapshot = CommerceRepository::decode((string) (new CommerceRepository($this->db))->order($order)['snapshot']);
        $reference = (string) $this->db->fetchOne('SELECT account_reference FROM users WHERE id=:id', ['id' => $buyer->id]);
        self::assertSame([true, $reference], [$snapshot['simulation'], $snapshot['account_reference']]);
        $documents = IntegrationContainer::get()->get(FinancialDocuments::class);
        $invoice = (new CommerceRepository($this->db))->documents($order)[0];
        $html = $documents->render($invoice)->html;
        self::assertStringContainsString(SiteMode::SIMULATION, $html);
        self::assertStringContainsString('Use your account reference ' . AccountReference::format($reference) . ' as the payment reference.', $html);
        self::assertStringContainsString('FAKE BANK (demo site, do not pay)', $html);

        $live = $this->orders(SiteMode::fixed('live'));
        $real = CommerceRepository::decode((string) (new CommerceRepository($this->db))->order($this->place($live, $buyer, $variants[1]))['snapshot']);
        self::assertArrayNotHasKey('simulation', $real, 'an order on a live site is real');
        self::assertSame($reference, $real['account_reference']);
    }

    public function testALiveSiteRefusesTheSimulatedCard(): void
    {
        $this->orders(SiteMode::fixed('demo'))->requirePaymentMethod('dummy', 'demo_success');
        $this->orders(SiteMode::fixed('live'))->requirePaymentMethod('eft', '');
        $this->expectExceptionMessage('Card payments are not available yet. Pay by EFT.');
        $this->orders(SiteMode::fixed('live'))->requirePaymentMethod('dummy', 'demo_success');
    }

    /**
     * Through the kernel, because the robots header is set after the page is drawn. The test kernel runs
     * with debugging on, when Symfony adds X-Robots-Tag: noindex to every response by itself, so a live
     * page is checked for the absence of the demo site's own rule (noindex, nofollow).
     */
    public function testEveryPageOfADemoSiteSaysSoAndKeepsOutOfSearchEngines(): void
    {
        $this->db->rollBack();
        self::assertNull((new OptionRepository($this->db))->find(SiteMode::OPTION), 'the development database has no saved site mode');
        $_ENV['APP_SITE_MODE'] = 'demo';
        foreach (['/', '/courses', '/terms'] as $path) {
            $response = $this->send($path);
            $html = (string) $response->getContent();
            self::assertStringContainsString(SiteMode::NOTICE, $html, $path . ' says it is a demo site');
            self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html, $path);
            self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $path);
        }
        $_ENV['APP_SITE_MODE'] = 'live';
        $response = $this->send('/');
        self::assertStringNotContainsString(SiteMode::NOTICE, (string) $response->getContent());
        self::assertStringNotContainsString('noindex', (string) $response->getContent());
        self::assertNotSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    private function send(string $path): \Symfony\Component\HttpFoundation\Response
    {
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        try {
            $response = $kernel->handle(Request::create($path));
            self::assertSame(200, $response->getStatusCode(), $path . ' did not render.');
            return $response;
        } finally {
            $kernel->shutdown();
        }
    }

    /** @return array{0:CurrentUser,1:list<int>} a private buyer and two priced published courses */
    private function shop(): array
    {
        $fixture = new DevelopmentFixture($this->db);
        $owner = $fixture->createUser('Demo provider', 'demo-provider-' . bin2hex(random_bytes(5)) . '@example.invalid');
        $company = $fixture->createCompany($owner, 'Demo provider', 'demo-' . bin2hex(random_bytes(5)) . '.invalid');
        $variants = [];
        foreach (['a', 'b'] as $key) {
            $course = $fixture->createCourse($owner, $company, 'demo-' . $key . '-' . bin2hex(random_bytes(4)), 'Demo course ' . $key, 'published');
            $variants[] = $fixture->createPriceVariant($course, $owner, 86400, 10000, true);
        }
        $buyer = $fixture->createUser('Demo buyer', 'demo-buyer-' . bin2hex(random_bytes(5)) . '@example.invalid');
        return [new CurrentUser($buyer, Uuid::v4(), 'demo-buyer@example.invalid', 'Demo buyer', [], ['COMMERCE.CART.VIEW', 'COMMERCE.CART.MANAGE', 'COMMERCE.CHECKOUT.START', 'COMMERCE.ORDER.VIEW'], Uuid::v4()), $variants];
    }

    private function orders(SiteMode $site): OrderService
    {
        $clock = new MockClock('2026-10-18T12:00:00+02:00');
        $container = IntegrationContainer::get();
        return new OrderService(new CommerceRepository($this->db), new TransactionManager($this->db), WalletFixture::settings($this->db), $container->get(TransitionService::class), $clock,
            PromotionFixture::service($this->db, $clock), $container->get(FinancialDocuments::class), WalletFixture::wallets($this->db, $clock), $site);
    }

    private function place(OrderService $orders, CurrentUser $buyer, int $variant): int
    {
        $orders->changeCart($buyer, $variant);
        $cart = $orders->cart($buyer);
        return $orders->place($buyer, (int) $cart['id'], (string) $cart['quote'], BillingFixture::person('Demo Buyer'), true, ['payment_method' => 'eft']);
    }
}
