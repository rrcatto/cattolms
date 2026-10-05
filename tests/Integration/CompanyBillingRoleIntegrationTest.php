<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Auth\AclService;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Auth\RoleCatalog;
use CattoLearning\Commerce\Application\BillingProfileService;
use CattoLearning\Commerce\Application\CompanyCreditPurchaseService;
use CattoLearning\Commerce\Http\CompanyCreditController;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Http\Controller\CompanyController;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\{BillingFixture,DevelopmentFixture,InProcessPage,IntegrationContainer};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Company billing and company credit purchasing by the company roles as they are actually granted:
 * the Company Administrator manages the company's billing details and buys its credits, correcting
 * the billing at checkout; a Course Creator (COURSE_EDITOR) and a Course Owner do neither. Each
 * identity's permissions are resolved by AclService from its assigned roles, as at sign-in, so these
 * tests follow the real grants rather than a hand-written permission list. Every write rolls back.
 */
#[Group('commerce')]
final class CompanyBillingRoleIntegrationTest extends TestCase
{
    private Database $db;
    private DevelopmentFixture $fixture;
    private int $companyId;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        $owner = $this->fixture->createUser('Role company founder');
        $this->companyId = $this->fixture->createCompany($owner, 'Role Co ' . $this->fixture->suffix(), $this->fixture->suffix() . '.roles.example.test');
        $_SESSION['csrf'] = str_repeat('r', 64);
        unset($_SESSION['acl_permissions']);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['company_credit_basket'], $_SESSION['csrf'], $_SESSION['flash'], $_SESSION['acl_permissions']);
    }

    public function testOnlyTheCompanyAdministratorRoleHoldsBillingAndCreditPurchasing(): void
    {
        $grants = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT r.role_key, p.permission_key FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id
              WHERE p.permission_key IN ('COMPANY.BILLING.MANAGE','COMPANY.CREDIT.MANAGE') ORDER BY 1, 2"
        ) as $row) {
            $grants[(string) $row['role_key']][] = (string) $row['permission_key'];
        }
        self::assertSame([
            RoleCatalog::ADMIN => ['COMPANY.BILLING.MANAGE', 'COMPANY.CREDIT.MANAGE'],
            RoleCatalog::COMPANY_ADMIN => ['COMPANY.BILLING.MANAGE', 'COMPANY.CREDIT.MANAGE'],
        ], $grants, 'Course Creator (COURSE_EDITOR), Course Owner and Student hold neither.');
    }

    public function testTheCompanyAdministratorManagesBillingAndCorrectsItWhenBuyingCredits(): void
    {
        $admin = $this->member(RoleCatalog::COMPANY_ADMIN, 'administrator');
        self::assertTrue($admin->hasPermission('COMPANY.BILLING.MANAGE') && $admin->hasPermission('COMPANY.CREDIT.MANAGE'));

        $page = InProcessPage::run($admin, CompanyController::class, 'billing');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('action="/company/billing"', $page['body']);
        $saved = InProcessPage::run($admin, CompanyController::class, 'updateBilling', [], ['csrf' => $_SESSION['csrf']] + BillingFixture::companyFields('Role Co Billing Ltd', '1 Saved Road'));
        self::assertSame('/company/billing', $saved['location']);
        self::assertSame('1 Saved Road', $this->billing()->forCompany($this->companyId)?->line1);

        $purchases = IntegrationContainer::get()->get(CompanyCreditPurchaseService::class);
        $purchases->add($admin, $this->companyId, $this->paidOffer(), 2);
        $checkout = InProcessPage::run($admin, CompanyCreditController::class, 'checkout');
        self::assertSame(200, $checkout['status']);
        self::assertStringContainsString('value="1 Saved Road"', $checkout['body'], 'The checkout is filled from the company profile.');
        self::assertStringContainsString('a correction here also updates them', $checkout['body']);

        $review = $purchases->review($admin, $this->companyId);
        $corrected = BillingFixture::company('Role Co Billing Ltd', '2 Corrected Road');
        $orderId = $purchases->place($admin, $this->companyId, $review['purchase_key'], $review['quote'], $corrected, 'eft', '', false, true);
        self::assertTrue($corrected->equals($this->billing()->forCompany($this->companyId) ?? BillingFixture::company()), 'The checkout correction updated the company profile.');
        $snapshot = CommerceRepository::decode((string) $this->db->fetchOne('SELECT snapshot FROM commerce_orders WHERE id=:id', ['id' => $orderId]));
        self::assertSame('2 Corrected Road', $snapshot['billing']['address']['line_1'], 'and was frozen into the order.');
        self::assertSame($admin->id, $snapshot['purchaser_user_id'], 'The administrator is the purchaser; the company is billed.');
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM audit_log WHERE event_key='company.billing_updated' AND metadata->>'company_id'=:c", ['c' => (string) $this->companyId]));
    }

    public function testCourseCreatorsAndCourseOwnersNeitherManageBillingNorBuyCredits(): void
    {
        $this->billing()->saveForCompany($this->member(RoleCatalog::COMPANY_ADMIN, 'administrator'), $this->companyId, BillingFixture::company('Untouched Ltd'));
        $offer = $this->paidOffer();
        foreach ([RoleCatalog::COURSE_EDITOR => 'course_editor', RoleCatalog::COURSE_OWNER => 'course_owner'] as $role => $companyRole) {
            $user = $this->member($role, $companyRole);
            self::assertFalse($user->hasPermission('COMPANY.BILLING.MANAGE'), $role);
            self::assertFalse($user->hasPermission('COMPANY.CREDIT.MANAGE'), $role);
            $refusals = [
                'billing page' => fn() => InProcessPage::run($user, CompanyController::class, 'billing'),
                'billing save' => fn() => InProcessPage::run($user, CompanyController::class, 'updateBilling', [], ['csrf' => $_SESSION['csrf']] + BillingFixture::companyFields('Takeover Ltd')),
                'billing service' => fn() => $this->billing()->saveForCompany($user, $this->companyId, BillingFixture::company('Takeover Ltd')),
                'credit buying page' => fn() => InProcessPage::run($user, CompanyCreditController::class, 'buy'),
                'credit checkout page' => fn() => InProcessPage::run($user, CompanyCreditController::class, 'checkout'),
                'adding credits' => fn() => IntegrationContainer::get()->get(CompanyCreditPurchaseService::class)->add($user, $this->companyId, $offer, 1),
                'placing a credit order' => fn() => IntegrationContainer::get()->get(CompanyCreditPurchaseService::class)->place($user, $this->companyId, Uuid::v4(), 'quote', BillingFixture::company(), 'eft', '', false, true),
            ];
            foreach ($refusals as $what => $attempt) {
                try {
                    $attempt();
                    self::fail($role . ' must be refused the ' . $what . '.');
                } catch (AccessDeniedHttpException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        self::assertSame('Untouched Ltd', $this->billing()->forCompany($this->companyId)?->name);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM commerce_orders WHERE company_id=:c', ['c' => $this->companyId]));
    }

    /** A member of the test company holding STUDENT plus the given role, with permissions as sign-in resolves them. */
    private function member(string $role, string $companyRole): CurrentUser
    {
        $email = strtolower($role) . '-' . $this->fixture->suffix() . '@example.test';
        $id = $this->fixture->createUser($role . ' member', $email);
        $roles = IntegrationContainer::get()->get(RoleRepository::class);
        $roles->assign($id, RoleCatalog::STUDENT);
        $roles->assign($id, $role);
        $this->db->executeStatement("INSERT INTO company_users(company_id,user_id,company_role,status) VALUES (:c,:u,:r,'active')", ['c' => $this->companyId, 'u' => $id, 'r' => $companyRole]);
        $roleKeys = [RoleCatalog::STUDENT, $role];
        return new CurrentUser($id, Uuid::v4(), $email, $role . ' member', $roleKeys, IntegrationContainer::get()->get(AclService::class)->permissionsForUser($id, $roleKeys), Uuid::v4());
    }

    private function billing(): BillingProfileService
    {
        return IntegrationContainer::get()->get(BillingProfileService::class);
    }

    private function paidOffer(): int
    {
        $ownerId = $this->fixture->createUser('Role offer owner');
        $provider = $this->fixture->createCompany($ownerId, 'Role provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.provider.example.test');
        $course = $this->fixture->createCourse($ownerId, $provider, 'role-offer-' . $this->fixture->suffix(), 'Role offer course', 'published');
        return $this->fixture->createPriceVariant($course, $ownerId, 2592000, 12345, true);
    }
}
