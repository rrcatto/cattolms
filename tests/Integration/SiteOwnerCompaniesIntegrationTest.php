<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Company\CompanyScope;
use CattoLearning\Company\CompanyService;
use CattoLearning\Configuration\SiteOwner;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Infrastructure\Persistence\{AdministrationRepository, AuthSessionRepository, CompanyRepository, Database};
use CattoLearning\Kernel;
use CattoLearning\Support\{Csrf, Env, Token};
use CattoLearning\Tests\Support\{IntegrationContainer, IntegrationDataFixture};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Site Owner replaces the System Company (owner, 2026-10-18): private accounts belong to no company
 * and may register their own; a Company Administrator needs a company; saving settings moves nobody; a
 * course no company owns belongs to the Site Owner and never matches a private learner as the same
 * company; Administration > Create company works through its page.
 */
final class SiteOwnerCompaniesIntegrationTest extends TestCase
{
    private Database $db;
    private IntegrationDataFixture $fixture;
    private string $suffix;
    /** @var array<string,mixed> */
    private array $cookies = [];
    /** @var list<string> */
    private array $domains = [];

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->fixture = new IntegrationDataFixture($this->db);
        $this->suffix = $this->fixture->suffix();
        $this->cookies = $_COOKIE;
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookies;
        foreach ($this->domains as $domain) {
            $this->db->executeStatement('DELETE FROM audit_log WHERE event_key = :e AND metadata->>\'company_id\' IN (SELECT id::text FROM companies WHERE domain = :d)', ['e' => 'company.created', 'd' => $domain]);
            // The company with its people: a company never stands without its administrator, even for a moment.
            $this->db->beginTransaction();
            $this->db->executeStatement('DELETE FROM users WHERE id IN (SELECT user_id FROM user_emails WHERE email LIKE :e)', ['e' => '%@' . $domain]);
            $this->db->executeStatement('DELETE FROM companies WHERE domain = :d', ['d' => $domain]);
            $this->db->commit();
        }
        $this->fixture->cleanup();
    }

    public function testAPrivateLearnerBelongsToNoCompanyAndCanRegisterTheirOwn(): void
    {
        $admin = $this->fixture->createUser('Owner admin ' . $this->suffix, 'owner-admin-' . $this->suffix . '@seed.test');
        $this->fixture->grantRole($admin, 'ADMIN');
        $learner = $this->fixture->createUser('Private learner ' . $this->suffix, 'private-' . $this->suffix . '@learner-' . $this->suffix . '.example.test');
        $companies = IntegrationContainer::get()->get(CompanyRepository::class);
        // Administration pages used to sweep every company-less person into the System Company.
        IntegrationContainer::get()->get(PlatformAdministrationService::class)->sectionData('companies', $admin, []);
        self::assertNull($companies->findForUser($learner), 'opening Administration puts nobody into a company');
        self::assertNull($companies->findForUser($admin), 'not even ADMIN belongs to a company');
        self::assertFalse(IntegrationContainer::get()->get(CommerceRepository::class)->isCompanyAccount($learner));
        $domain = 'learner-' . $this->suffix . '.example.test';
        $this->domains[] = $domain;
        $company = IntegrationContainer::get()->get(CompanyService::class)->register($learner, 'private-' . $this->suffix . '@' . $domain, 'Learner Company ' . $this->suffix, $domain);
        $member = (int) $this->db->fetchOne("SELECT company_id FROM company_users WHERE user_id = :u AND status = 'active'", ['u' => $learner]);
        self::assertSame((int) $company['id'], $member, 'a private learner can register their own company');
    }

    public function testACourseNoCompanyOwnsIsTheSiteOwnersAndNeverAPrivateLearnersCompany(): void
    {
        $owner = $this->fixture->createUser('Course owner ' . $this->suffix, 'course-owner-' . $this->suffix . '@seed.test');
        $company = $this->fixture->createCompany($owner, 'Provider ' . $this->suffix, 'provider-' . $this->suffix . '.seed.test');
        $course = $this->fixture->createCourse($owner, $company, 'site-owner-' . $this->suffix, 'Site Owner course ' . $this->suffix);
        $this->db->executeStatement('UPDATE courses SET owner_company_id = NULL WHERE id = :id', ['id' => $course]);
        $learner = $this->fixture->createUser('Private learner ' . $this->suffix, 'learner-' . $this->suffix . '@seed.test');
        $learnerCompany = IntegrationContainer::get()->get(CompanyRepository::class)->findForUser($learner);
        $courseCompany = $this->db->fetchOne('SELECT owner_company_id FROM courses WHERE id = :id', ['id' => $course]);
        self::assertSame([null, null], [$learnerCompany, $courseCompany], 'both have no company');
        self::assertFalse(CompanyScope::same($learnerCompany['id'] ?? null, $courseCompany), 'and they are not the same company');
        self::assertFalse(IntegrationContainer::get()->get(AdministrationRepository::class)->companyOwnsCourse(0, $course), 'no company owns a Site Owner course');
        $siteOwner = IntegrationContainer::get()->get(SiteOwner::class)->value('trading_name');
        self::assertSame($siteOwner, IntegrationContainer::get()->get(CourseRepository::class)->certificateProviderName($course), 'its provider is the Site Owner');
    }

    public function testACompanyAdministratorNeedsACompanyAndSavingSettingsMovesNobody(): void
    {
        $admin = $this->fixture->createUser('Settings admin ' . $this->suffix, 'settings-admin-' . $this->suffix . '@seed.test');
        $company = $this->fixture->createCompany($admin, 'Member ' . $this->suffix, 'member-' . $this->suffix . '.seed.test');
        $this->fixture->addCompanyMember($company, $admin, 'administrator');
        $service = IntegrationContainer::get()->get(PlatformAdministrationService::class);
        try {
            $service->createPerson(['email' => 'no-company-admin-' . $this->suffix . '@seed.test', 'first_name' => 'No', 'last_name' => 'Company', 'role' => 'COMPANY_ADMIN'], $admin);
            self::fail('A Company Administrator was made without a company.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame(PlatformAdministrationService::COMPANY_ADMIN_NEEDS_COMPANY, $refused->getMessage());
        }
        $service->saveSettings(['platform_name' => (string) IntegrationContainer::get()->get(\CattoLearning\Configuration\RuntimeSettings::class)->platformName()], $admin);
        $after = IntegrationContainer::get()->get(CompanyRepository::class)->findForUser($admin);
        self::assertSame($company, (int) ($after['id'] ?? 0), 'saving settings changes no membership');
    }

    /** Administration > Create company, through the page: it failed before 2026-10-18 (eight columns, nine values). */
    public function testAdministrationCreatesACompanyThroughThePage(): void
    {
        $admin = $this->fixture->createUser('Create company admin ' . $this->suffix, 'create-company-' . $this->suffix . '@seed.test');
        $this->fixture->grantRole($admin, 'ADMIN');
        $token = Token::generate();
        IntegrationContainer::get()->get(AuthSessionRepository::class)->create($admin, Token::hash($token), 3600, 'qa', 'PHPUnit');
        $_COOKIE[Env::string('AUTH_SESSION_COOKIE', 'catto_learning_session')] = $token;
        $domain = 'page-created-' . $this->suffix . '.example.test';
        $this->domains[] = $domain;
        // A company is added with its Company Administrator (owner, 2026-10-27).
        $_POST = ['csrf' => Csrf::token(), 'name' => 'Page Created ' . $this->suffix, 'domain' => $domain,
            'admin_first_name' => 'Nandi', 'admin_last_name' => 'Mahlangu', 'admin_email' => 'nandi.mahlangu@' . $domain];
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        try {
            $response = $kernel->handle(Request::create('/admin/companies', 'POST', $_POST));
        } finally {
            $kernel->shutdown();
            $_POST = [];
        }
        self::assertSame(303, $response->getStatusCode(), 'back to Companies after the POST');
        self::assertSame('/admin/companies', $response->headers->get('Location'));
        $row = $this->db->fetchAssociative('SELECT name, status, created_by_user_id FROM companies WHERE domain = :d', ['d' => $domain]);
        self::assertSame(['Page Created ' . $this->suffix, 'active', $admin], [$row['name'] ?? null, $row['status'] ?? null, (int) ($row['created_by_user_id'] ?? 0)]);
        self::assertContains('The company was created.', array_column((array) ($_SESSION['flash'] ?? []), 'message'));
        $founder = $this->db->fetchAssociative(
            "SELECT cu.company_role, u.first_name, u.last_name FROM company_users cu JOIN users u ON u.id = cu.user_id JOIN companies c ON c.id = cu.company_id
             JOIN user_emails ue ON ue.user_id = u.id AND ue.is_primary WHERE c.domain = :d AND ue.email = :e",
            ['d' => $domain, 'e' => 'nandi.mahlangu@' . $domain]
        );
        self::assertSame(['administrator', 'Nandi', 'Mahlangu'], [$founder['company_role'] ?? null, $founder['first_name'] ?? null, $founder['last_name'] ?? null], 'made with its Company Administrator');
    }

    /** A company is never added without its Company Administrator (owner, 2026-10-27). */
    public function testAddCompanyRefusesACompanyWithoutItsAdministrator(): void
    {
        $admin = $this->fixture->createUser('Create company admin ' . $this->suffix, 'create-company-' . $this->suffix . '@seed.test');
        $this->fixture->grantRole($admin, 'ADMIN');
        $domain = 'no-founder-' . $this->suffix . '.example.test';
        $this->domains[] = $domain;
        try {
            IntegrationContainer::get()->get(PlatformAdministrationService::class)->createCompany(['name' => 'No Founder ' . $this->suffix, 'domain' => $domain], $admin);
            self::fail('a company without its Company Administrator was added');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('Company Administrator', $refused->getMessage());
        }
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM companies WHERE domain = :d', ['d' => $domain]));
    }
}
