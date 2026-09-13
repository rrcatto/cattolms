<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Policy;

use Symfony\Component\Yaml\Yaml;
use RuntimeException;

/** Deployment defaults for the initial access product; purchased values are always snapshotted. */
final class CommercePolicy
{
    public readonly string $termsVersion;
    public readonly int $paymentDueDays;
    public readonly int $activationDeadlineDays;
    public function __construct(string $codeRoot)
    {
        $config = Yaml::parseFile($codeRoot . '/config/commerce.yaml')['commerce'];
        $this->termsVersion = (string) $config['terms_version'];
        $this->paymentDueDays = (int) $config['payment_due_days'];
        $this->activationDeadlineDays = (int) $config['activation_deadline_days'];
        if ($this->paymentDueDays < 1 || $this->activationDeadlineDays < 1 || $config['tax_enabled'] !== false) {
            throw new RuntimeException('Invalid commerce defaults: tax must remain disabled in this milestone.');
        }
    }
}
