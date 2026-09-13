<?php

declare(strict_types=1);
use Phinx\Migration\AbstractMigration;

/** Gives stored PDF bytes the same immutability guarantee as their document snapshot. */
final class ProtectInvoiceFiles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('CREATE TRIGGER commerce_document_file_immutable BEFORE UPDATE OR DELETE ON commerce_document_files FOR EACH ROW EXECUTE FUNCTION commerce_reject_history_mutation()');
    }
    public function down(): void { throw new RuntimeException('Financial documents must be retained.'); }
}
