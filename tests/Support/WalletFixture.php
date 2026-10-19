<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Support;

use CattoLearning\Commerce\Settings\CommerceSettings;
use CattoLearning\Commerce\Wallet\WalletRepository;
use CattoLearning\Commerce\Wallet\WalletService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\OptionRepository;
use Symfony\Component\Clock\ClockInterface;

/** The commerce settings and wallet service on a test's own connection and clock. */
final class WalletFixture
{
    /**
     * The commerce settings at their defaults for this test, whatever ADMIN has saved on the site (the
     * owner's database keeps a 3% Stitch fee, for example): the saved overrides are removed inside the
     * test's own transaction, which is rolled back afterwards.
     */
    public static function defaults(Database $db): void
    {
        if (!$db->isTransactionActive()) throw new \LogicException('Clear the commerce settings only inside the test\'s own transaction.');
        $db->executeStatement("DELETE FROM app_options WHERE option_key LIKE 'commerce.%'");
    }

    public static function settings(Database $db): CommerceSettings
    {
        return new CommerceSettings(new OptionRepository($db));
    }

    public static function wallets(Database $db, ClockInterface $clock): WalletService
    {
        return new WalletService(new WalletRepository($db), self::settings($db), $clock);
    }
}
