<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Auth\AuthService;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Application\BillingProfileService;
use CattoLearning\Commerce\Application\CompanyCreditPurchaseService;
use CattoLearning\Commerce\Application\OrderService;
use CattoLearning\Commerce\Http\CommerceController;
use CattoLearning\Commerce\Http\CompanyCreditController;
use CattoLearning\Http\Controller\AccountController;
use CattoLearning\Http\Controller\CompanyController;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\{BillingFixture,DevelopmentFixture,InProcessPage,IntegrationContainer};
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Billing profiles as current, reusable data: one per person and one per company, kept apart from
 * the personal profile and from each other, edited only by their owner or an authorised member of
 * their company, shown on Account → Profile and the company Billing details section, and filled into
 * both checkouts. Also the schema: ownership, cascades, constraints and the removed column.
 * Every write rolls back.
 */
#[Group('commerce')]
final class BillingProfileIntegrationTest extends TestCase
{
    private Database $db;
    private DevelopmentFixture $fixture;
    private BillingProfileService $billing;
    private string $suffix;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        $this->suffix = $this->fixture->suffix();
        $this->billing = BillingFixture::service($this->db, new MockClock(new \DateTimeImmutable()));
        $_SESSION['csrf'] = str_repeat('b', 64);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['checkout'], $_SESSION['company_credit_basket'], $_SESSION['csrf'], $_SESSION['flash']);
    }

    public function testAPersonSavesStructuredBillingDetailsApartFromTheirPersonalProfile(): void
    {
        [$id, $user] = $this->person('Profile');
        self::assertNull($this->billing->forUser($id));
        $form = $this->billing->userForm($id, ['first_name' => 'Ana', 'last_name' => 'Ferreira']);
        self::assertFalse($form['saved']);
        self::assertSame('Ana Ferreira', $form['values']['billing_name'], 'A new profile starts from the person’s name.');
        self::assertSame(BillingProfileService::defaultCountry(), $form['values']['country_code'], 'and the installation’s country.');

        $saved = $this->billing->saveOwn($user, BillingFixture::personFields('Ana Ferreira', 'Rua São João 12'));
        self::assertTrue($saved->equals($this->billing->forUser($id) ?? throw new \RuntimeException('Not saved.')));
        self::assertSame('Rua São João 12', $this->billing->forUser($id)?->line1, 'Accents survive.');
        self::assertSame(1, $this->auditCount('user.billing_profile_updated', $id));
        $this->billing->saveOwn($user, BillingFixture::personFields('Ana Ferreira', 'Rua São João 12'));
        self::assertSame(1, $this->auditCount('user.billing_profile_updated', $id), 'Saving identical details changes and records nothing.');

        $before = $this->db->fetchAssociative('SELECT first_name, last_name, display_name FROM users WHERE id=:id', ['id' => $id]);
        $this->billing->saveOwn($user, BillingFixture::personFields('Completely Different', 'Elsewhere 1'));
        self::assertSame($before, $this->db->fetchAssociative('SELECT first_name, last_name, display_name FROM users WHERE id=:id', ['id' => $id]), 'Billing never changes the personal profile.');
        IntegrationContainer::get()->get(AuthService::class)->updateProfile($id, ['first_name' => 'Changed', 'billing_name' => 'Ignored', 'address_line_1' => 'Ignored']);
        self::assertSame('Completely Different', $this->billing->forUser($id)?->name, 'and the personal profile never changes billing.');
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name='users' AND column_name='billing_address'"), 'users.billing_address is gone.');
    }

    public function testAccountBillingAddressShowsAndSavesOnlyTheSignedInPersonsBillingDetails(): void
    {
        [$id, $user] = $this->person('Account');
        [$otherId] = $this->person('Other');
        $this->billing->saveOwn($user, BillingFixture::personFields('Account Holder', '7 Protea Street'));
        $page = InProcessPage::run($user, AccountController::class, 'billing');
        self::assertSame(200, $page['status']);
        foreach (['id="billing"', 'action="/account/billing"', 'Billing Address', 'value="Account Holder"', 'value="7 Protea Street"', 'value="Nkosi Consulting"', 'name="country_code"'] as $text) {
            self::assertStringContainsString($text, $page['body']);
        }
        // Billing is its own page under Profile; Personal Particulars holds the particulars and the image only.
        $particulars = InProcessPage::run($user, AccountController::class, 'profile');
        self::assertSame(200, $particulars['status']);
        self::assertStringContainsString('Personal particulars', $particulars['body']);
        self::assertStringContainsString('id="profile-image"', $particulars['body']);
        self::assertStringNotContainsString('action="/account/billing"', $particulars['body']);

        $post = BillingFixture::personFields('Corrected Holder', '8 Protea Street') + ['csrf' => $_SESSION['csrf'], 'user_id' => (string) $otherId];
        $saved = InProcessPage::run($user, AccountController::class, 'updateBilling', [], $post);
        self::assertSame('/account/billing', $saved['location']);
        self::assertSame('8 Protea Street', $this->billing->forUser($id)?->line1);
        self::assertNull($this->billing->forUser($otherId), 'A posted user id is ignored: only the signed-in person’s profile changes.');

        try {
            InProcessPage::run($user, AccountController::class, 'updateBilling', [], ['csrf' => 'wrong'] + BillingFixture::personFields('Forged', '1 Forged Road'));
            self::fail('A save without a valid token is refused.');
        } catch (\RuntimeException $error) {
            self::assertSame('Invalid request token.', $error->getMessage());
        }
        self::assertSame('8 Protea Street', $this->billing->forUser($id)?->line1, 'Without a valid token nothing is saved.');

        $invalid = InProcessPage::run($user, AccountController::class, 'updateBilling', [], ['csrf' => $_SESSION['csrf']] + array_merge(BillingFixture::personFields(), ['city' => str_repeat('x', 121)]));
        self::assertSame('/account/billing', $invalid['location']);
        self::assertSame('8 Protea Street', $this->billing->forUser($id)?->line1, 'An oversized value is refused and nothing is saved.');
        self::assertStringContainsString('may not exceed 120', json_encode($_SESSION['flash'] ?? [], JSON_THROW_ON_ERROR));
    }

    public function testCheckoutIsFilledFromTheSavedProfileAndCorrectionsAreSaved(): void
    {
        [$id, $user] = $this->person('Checkout', ['COMMERCE.CART.VIEW', 'COMMERCE.CART.MANAGE', 'COMMERCE.CHECKOUT.START', 'COMMERCE.ORDER.VIEW']);
        $this->billing->saveOwn($user, BillingFixture::personFields('Checkout Buyer', '3 Saved Street'));
        IntegrationContainer::get()->get(OrderService::class)->changeCart($user, $this->paidOffer());
        $page = InProcessPage::run($user, CommerceController::class, 'checkout', ['step' => 'profile']);
        self::assertSame(200, $page['status']);
        foreach (['value="Checkout Buyer"', 'value="3 Saved Street"', 'id="checkout-billing-address-line-1"', 'Billing details'] as $text) {
            self::assertStringContainsString($text, $page['body']);
        }
        $fields = ['first_name' => 'Checkout', 'last_name' => 'Buyer', 'mobile_number' => '0820000000', 'identification_number' => '9001015009087', 'csrf' => $_SESSION['csrf']]
            + BillingFixture::personFields('Checkout Buyer', '4 Corrected Street');
        $saved = InProcessPage::run($user, CommerceController::class, 'profile', [], $fields);
        self::assertSame('/checkout/payment', $saved['location']);
        self::assertSame('4 Corrected Street', $this->billing->forUser($id)?->line1, 'A correction at checkout is saved to the reusable profile.');
        self::assertSame('Checkout', $this->db->fetchOne('SELECT first_name FROM users WHERE id=:id', ['id' => $id]), 'Personal fields go to the personal profile.');
    }

    public function testACompanyHasItsOwnBillingProfileEditableOnlyByItsAuthorisedMembers(): void
    {
        [$adminId, $admin] = $this->person('Company admin', ['COMPANY.DASHBOARD.VIEW', 'COMPANY.BILLING.MANAGE', 'COMPANY.CREDIT.MANAGE', 'COMMERCE.CHECKOUT.START']);
        $companyId = $this->company($adminId, 'Billing Co ' . $this->suffix);
        [$learnerId, $learner] = $this->person('Learner', ['COMPANY.DASHBOARD.VIEW']);
        $this->member($companyId, $learnerId, 'member');
        [$outsiderId, $outsider] = $this->person('Outsider admin', ['COMPANY.DASHBOARD.VIEW', 'COMPANY.BILLING.MANAGE']);
        $otherCompany = $this->company($outsiderId, 'Other Co ' . $this->suffix);

        $this->billing->saveOwn($admin, BillingFixture::personFields('Admin Personally', '1 Home Road'));
        $form = $this->billing->companyForm($companyId, 'Billing Co ' . $this->suffix);
        self::assertFalse($form['saved']);
        self::assertSame('Billing Co ' . $this->suffix, $form['values']['billing_name'], 'A company starts from its own name, not its administrator’s billing.');

        self::assertTrue($this->billing->saveForCompany($admin, $companyId, BillingFixture::company('Billing Co (Pty) Ltd')));
        self::assertSame('Billing Co (Pty) Ltd', $this->billing->forCompany($companyId)?->name);
        self::assertSame('Admin Personally', $this->billing->forUser($adminId)?->name, 'The two profiles are independent.');
        self::assertNull($this->billing->forCompany($companyId)?->organisationName, 'A company has no separate organisation name.');
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM audit_log WHERE event_key='company.billing_updated' AND metadata->>'company_id'=:c", ['c' => (string) $companyId]));

        try {
            $this->billing->saveForCompany($learner, $companyId, BillingFixture::company('Learner Takeover'));
            self::fail('A member without COMPANY.BILLING.MANAGE cannot change company billing.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->billing->saveForCompany($outsider, $companyId, BillingFixture::company('Hostile Takeover'));
            self::fail('Another company’s administrator cannot change this company’s billing.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('only for the company you administer', $error->getMessage());
        }
        $platform = $this->user($outsiderId, 'platform@example.test', 'Platform ADMIN', ['COMPANY.BILLING.MANAGE', 'PLATFORM.DASHBOARD.VIEW']);
        self::assertTrue($this->billing->saveForCompany($platform, $otherCompany, BillingFixture::company('Platform Set Ltd')), 'Platform authority may act on a selected company.');
        self::assertSame('Billing Co (Pty) Ltd', $this->billing->forCompany($companyId)?->name, 'and nothing leaked across companies.');

        $section = InProcessPage::run($admin, CompanyController::class, 'billing');
        self::assertSame(200, $section['status']);
        foreach (['action="/company/billing"', 'value="Billing Co (Pty) Ltd"', 'id="company-billing-billing-name"', 'Billing details'] as $text) {
            self::assertStringContainsString($text, $section['body']);
        }
        self::assertStringNotContainsString('name="organisation_name"', $section['body']);
        foreach (['billing' => null, 'updateBilling' => ['csrf' => $_SESSION['csrf']] + BillingFixture::companyFields('Learner Takeover')] as $action => $post) {
            try {
                InProcessPage::run($learner, CompanyController::class, $action, [], $post);
                self::fail('A member without COMPANY.BILLING.MANAGE gets no billing ' . $action . '.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        $update = InProcessPage::run($admin, CompanyController::class, 'updateBilling', [], ['csrf' => $_SESSION['csrf'], 'company_id' => (string) $otherCompany] + BillingFixture::companyFields('Updated Billing Co'));
        self::assertSame('/company/billing', $update['location']);
        self::assertSame('Updated Billing Co', $this->billing->forCompany($companyId)?->name, 'The context company is updated');
        self::assertSame('Platform Set Ltd', $this->billing->forCompany($otherCompany)?->name, 'and a posted company id is ignored.');
    }

    public function testCompanyCreditCheckoutIsFilledFromTheCompanyProfile(): void
    {
        [$adminId, $admin] = $this->person('Credit admin', ['COMPANY.DASHBOARD.VIEW', 'COMPANY.BILLING.MANAGE', 'COMPANY.CREDIT.MANAGE', 'COMMERCE.CHECKOUT.START']);
        $companyId = $this->company($adminId, 'Credit Co ' . $this->suffix);
        $this->billing->saveForCompany($admin, $companyId, BillingFixture::company('Credit Co Legal Name', '9 Harbour Road'));
        IntegrationContainer::get()->get(CompanyCreditPurchaseService::class)->add($admin, $companyId, $this->paidOffer(), 2);
        $page = InProcessPage::run($admin, CompanyCreditController::class, 'checkout');
        self::assertSame(200, $page['status']);
        foreach (['value="Credit Co Legal Name"', 'value="9 Harbour Road"', 'id="company-checkout-billing-billing-name"', 'Company billing details'] as $text) {
            self::assertStringContainsString($text, $page['body']);
        }
        self::assertStringNotContainsString('name="billing_address"', $page['body'], 'The one-off textarea is gone.');
    }

    public function testTheSchemaKeepsOneValidProfilePerOwnerAndCascadesWithIt(): void
    {
        [$id] = $this->person('Schema');
        $companyId = $this->company($id, 'Schema Co ' . $this->suffix);
        $insert = "INSERT INTO user_billing_profiles (user_id, billing_name, address_line_1, city, country_code) VALUES (:id, :name, '1 Road', 'City', :country)";
        $this->db->executeStatement($insert, ['id' => $id, 'name' => 'First', 'country' => 'ZA']);
        $this->db->executeStatement("INSERT INTO company_billing_profiles (company_id, billing_name, address_line_1, city, country_code) VALUES (:id, 'Co', '1 Road', 'City', 'ZA')", ['id' => $companyId]);
        $this->refused('one profile per user', $insert, ['id' => $id, 'name' => 'Second', 'country' => 'ZA']);
        $this->refused('one profile per company', "INSERT INTO company_billing_profiles (company_id, billing_name, address_line_1, city, country_code) VALUES (:id, 'Co', '1 Road', 'City', 'ZA')", ['id' => $companyId]);
        $this->refused('an owner that exists', $insert, ['id' => 999999999, 'name' => 'Ghost', 'country' => 'ZA']);
        $this->refused('a company that exists', "INSERT INTO company_billing_profiles (company_id, billing_name, address_line_1, city, country_code) VALUES (999999999, 'Co', '1 Road', 'City', 'ZA')", []);
        [$other] = $this->person('Schema other');
        $this->refused('an upper-case alpha-2 country', $insert, ['id' => $other, 'name' => 'Lower', 'country' => 'za']);
        $this->refused('a non-blank name', $insert, ['id' => $other, 'name' => '   ', 'country' => 'ZA']);
        $this->refused('the 200-character name bound', $insert, ['id' => $other, 'name' => str_repeat('n', 201), 'country' => 'ZA']);
        $this->refused('null where required', "INSERT INTO user_billing_profiles (user_id, billing_name, address_line_1, city, country_code) VALUES (:id, 'N', NULL, 'City', 'ZA')", ['id' => $other]);

        $this->db->executeStatement('DELETE FROM user_billing_profiles WHERE user_id=:id', ['id' => $id]);
        $this->db->executeStatement('INSERT INTO user_billing_profiles (user_id, billing_name, address_line_1, city, country_code) VALUES (:id, \'Again\', \'1 Road\', \'City\', \'ZA\')', ['id' => $other]);
        $this->db->executeStatement('DELETE FROM users WHERE id=:id', ['id' => $other]);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_billing_profiles WHERE user_id=:id', ['id' => $other]), 'A person’s profile goes with them.');
        $this->db->executeStatement('DELETE FROM company_users WHERE company_id=:id', ['id' => $companyId]);
        $this->db->executeStatement('DELETE FROM companies WHERE id=:id', ['id' => $companyId]);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM company_billing_profiles WHERE company_id=:id', ['id' => $companyId]), 'A company’s profile goes with it.');

        $grants = $this->db->fetchFirstColumn("SELECT r.role_key FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key='COMPANY.BILLING.MANAGE' ORDER BY 1");
        self::assertSame(['ADMIN', 'COMPANY_ADMIN'], $grants, 'COMPANY.BILLING.MANAGE belongs to company administrators, not learners.');
    }

    /** @param array<string,mixed> $params */
    private function refused(string $rule, string $sql, array $params): void
    {
        $this->db->executeStatement('SAVEPOINT billing_rule');
        try {
            $this->db->executeStatement($sql, $params);
            self::fail('The schema must require ' . $rule . '.');
        } catch (DbalException) {
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT billing_rule');
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param list<string> $permissions
     * @return array{0:int,1:CurrentUser}
     */
    private function person(string $label, array $permissions = ['ACCOUNT.VIEW', 'ACCOUNT.PROFILE.VIEW', 'ACCOUNT.PROFILE.EDIT']): array
    {
        $email = strtolower(str_replace(' ', '-', $label)) . '-' . $this->fixture->suffix() . '@example.test';
        $id = $this->fixture->createUser($label, $email);
        return [$id, $this->user($id, $email, $label, array_merge($permissions, ['ACCOUNT.VIEW', 'ACCOUNT.PROFILE.VIEW', 'ACCOUNT.PROFILE.EDIT']))];
    }

    /** @param list<string> $permissions */
    private function user(int $id, string $email, string $name, array $permissions): CurrentUser
    {
        return new CurrentUser($id, Uuid::v4(), $email, $name, [], array_values(array_unique($permissions)), Uuid::v4());
    }

    private function company(int $ownerId, string $name): int
    {
        $id = $this->fixture->createCompany($ownerId, $name, strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'co') . '.example.test');
        $this->member($id, $ownerId, 'owner');
        return $id;
    }

    private function member(int $companyId, int $userId, string $role): void
    {
        $this->db->executeStatement("INSERT INTO company_users(company_id,user_id,company_role,status) VALUES (:c,:u,:r,'active')", ['c' => $companyId, 'u' => $userId, 'r' => $role]);
    }

    private function paidOffer(): int
    {
        $ownerId = $this->fixture->createUser('Offer owner');
        $providerCompany = $this->fixture->createCompany($ownerId, 'Offer provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.offers.example.test');
        $course = $this->fixture->createCourse($ownerId, $providerCompany, 'billing-offer-' . $this->fixture->suffix(), 'Billing offer course', 'published');
        return (int) $this->db->fetchOne(
            "INSERT INTO course_price_variants(public_id,course_id,access_period_seconds,price_minor_units,currency_code,position,is_default,created_by_user_id,updated_by_user_id) VALUES (:public,:course,86400,12345,'ZAR',1,TRUE,:user,:user) RETURNING id",
            ['public' => Uuid::v4(), 'course' => $course, 'user' => $ownerId]
        );
    }

    private function auditCount(string $event, int $userId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM audit_log WHERE event_key=:e AND user_id=:u', ['e' => $event, 'u' => $userId]);
    }
}
