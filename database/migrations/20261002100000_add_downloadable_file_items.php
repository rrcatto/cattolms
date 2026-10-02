<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Downloadable File Course Items. One generic item type references any Resource Library file and is
 * served only through the authenticated, placement-scoped learner download route. Resources gain two
 * classifications: `archive` (ZIP and other bundles) and `file` (any other downloadable file).
 */
final class AddDownloadableFileItems extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE resources DROP CONSTRAINT resources_resource_type_check;
ALTER TABLE resources ADD CONSTRAINT resources_resource_type_check
    CHECK (resource_type IN ('pdf','image_graphic','uploaded_video','audio','markdown','document','archive','file'));
ALTER TABLE course_items DROP CONSTRAINT course_items_item_type_check;
ALTER TABLE course_items ADD CONSTRAINT course_items_item_type_check
    CHECK (item_type IN ('html_lesson','assessment','diagnostic','pdf','image_graphic','uploaded_video','youtube','audio','markdown','document','downloadable_file'));
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE course_items DROP CONSTRAINT course_items_item_type_check;
ALTER TABLE course_items ADD CONSTRAINT course_items_item_type_check
    CHECK (item_type IN ('html_lesson','assessment','diagnostic','pdf','image_graphic','uploaded_video','youtube','audio','markdown','document'));
ALTER TABLE resources DROP CONSTRAINT resources_resource_type_check;
ALTER TABLE resources ADD CONSTRAINT resources_resource_type_check
    CHECK (resource_type IN ('pdf','image_graphic','uploaded_video','audio','markdown','document'));
SQL);
    }
}
