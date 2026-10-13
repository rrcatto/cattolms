<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Policy;

use Symfony\Component\Yaml\Yaml;
use RuntimeException;

/**
 * Deployment defaults for the initial access product; purchased values are always snapshotted. The
 * terms an order records are not a deployment default: their versions come from LegalDocuments.
 */
final class CommercePolicy
{
    public readonly int $paymentDueDays;
    public readonly int $activationDeadlineDays;
    public function __construct(string $codeRoot)
    {
        $config = Yaml::parseFile($codeRoot . '/config/commerce.yaml')['commerce'];
        $this->paymentDueDays = (int) $config['payment_due_days'];
        $this->activationDeadlineDays = (int) $config['activation_deadline_days'];
        if ($this->paymentDueDays < 1 || $this->activationDeadlineDays < 1 || $config['tax_enabled'] !== false) {
            throw new RuntimeException('Invalid commerce defaults: tax must remain disabled in this milestone.');
        }
    }
}
