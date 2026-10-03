<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A section can be shown in the public course preview, opted in per section exactly as a Course
 * Item placement is. Off by default, so existing courses preview as before.
 */
final class AddPublicPreviewToCourseSections extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE course_sections ADD COLUMN public_preview BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE course_sections DROP COLUMN public_preview');
    }
}
