<?php
declare(strict_types=1);
use Phinx\Migration\AbstractMigration;

final class AddAuthenticatedEmail extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE auth_sessions ADD COLUMN IF NOT EXISTS authenticated_email VARCHAR(320) NOT NULL DEFAULT ''");
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE auth_sessions DROP COLUMN IF EXISTS authenticated_email');
    }
}
