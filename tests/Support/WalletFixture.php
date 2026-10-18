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
    public static function settings(Database $db): CommerceSettings
    {
        return new CommerceSettings(new OptionRepository($db));
    }

    public static function wallets(Database $db, ClockInterface $clock): WalletService
    {
        return new WalletService(new WalletRepository($db), self::settings($db), $clock);
    }
}
