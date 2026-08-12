# Catto Learning 0.5.2 development baseline

Version 0.5.2 is a clean development baseline. Historical pre-production migrations remain consolidated into one migration:

`database/migrations/20260811222100_create_v052_baseline.php`

The development database is intentionally reset when using the supplied installer with the `reset` argument.

0.5.2 adds full platform-administrator course-category management, including create/edit/reorder/activate/deactivate, safe course reassignment before deleting an in-use category, and inline category creation from course authoring/import screens. It also removes blanket publication requirements for assessed modules, final assessments and grade bands so content-only courses can be published. Explicitly required assessments must still contain questions, and published courses must retain one active default access/price variant.
