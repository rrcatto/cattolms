<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The `document` Course Item type only offered a download link, which Downloadable File now does
 * through the protected, placement-scoped route. Existing document items become Downloadable Files;
 * the `document` Resource classification is unchanged.
 */
final class FoldDocumentItemsIntoDownloadableFile extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
UPDATE course_items SET item_type = 'downloadable_file' WHERE item_type = 'document';
ALTER TABLE course_items DROP CONSTRAINT course_items_item_type_check;
ALTER TABLE course_items ADD CONSTRAINT course_items_item_type_check
    CHECK (item_type IN ('html_lesson','assessment','diagnostic','pdf','image_graphic','uploaded_video','youtube','audio','markdown','downloadable_file'));
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE course_items DROP CONSTRAINT course_items_item_type_check;
ALTER TABLE course_items ADD CONSTRAINT course_items_item_type_check
    CHECK (item_type IN ('html_lesson','assessment','diagnostic','pdf','image_graphic','uploaded_video','youtube','audio','markdown','document','downloadable_file'));
SQL);
    }
}
