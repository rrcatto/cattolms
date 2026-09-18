<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpgradeCanonicalBundledThemes extends AbstractMigration
{
    public function up(): void
    {
        // Install bundled source releases before migrating. Preserve custom/older selections.
        $this->execute("UPDATE app_options SET option_value = CASE option_value
            WHEN 'factory-reset-v2.0.0' THEN 'factory-reset-v2.0.1'
            WHEN 'factory-reset-sidebar-v2.0.0' THEN 'factory-reset-sidebar-v2.0.1'
            WHEN 'gilded-noir-v2.0.1' THEN 'gilded-noir-v2.0.2'
            WHEN 'light-default-v2.0.0' THEN 'light-default-v2.0.1'
            WHEN 'radiant-learning-v4.0.0' THEN 'radiant-learning-v4.0.1'
            END, updated_at = NOW()
            WHERE option_key = 'active_theme' AND option_value IN (
                'factory-reset-v2.0.0', 'factory-reset-sidebar-v2.0.0',
                'gilded-noir-v2.0.1', 'light-default-v2.0.0', 'radiant-learning-v4.0.0')");
    }

    public function down(): void
    {
        $this->execute("UPDATE app_options SET option_value = CASE option_value
            WHEN 'factory-reset-v2.0.1' THEN 'factory-reset-v2.0.0'
            WHEN 'factory-reset-sidebar-v2.0.1' THEN 'factory-reset-sidebar-v2.0.0'
            WHEN 'gilded-noir-v2.0.2' THEN 'gilded-noir-v2.0.1'
            WHEN 'light-default-v2.0.1' THEN 'light-default-v2.0.0'
            WHEN 'radiant-learning-v4.0.1' THEN 'radiant-learning-v4.0.0'
            END, updated_at = NOW()
            WHERE option_key = 'active_theme' AND option_value IN (
                'factory-reset-v2.0.1', 'factory-reset-sidebar-v2.0.1',
                'gilded-noir-v2.0.2', 'light-default-v2.0.1', 'radiant-learning-v4.0.1')");
    }
}
