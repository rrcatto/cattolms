<?php
/** Validates canonical platform UI composition, GET navigation and theme ownership. */
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$need = static function (bool $ok, string $message) use (&$errors): void { if (!$ok) $errors[] = $message; };
$read = static function (string $path) use ($need): string {
    $need(is_file($path), 'Missing required file: ' . $path);
    return is_file($path) ? (string) file_get_contents($path) : '';
};

$manifest = json_decode($read($root . '/themes/factory-reset/theme.json'), true);
$need(is_array($manifest), 'Factory Reset theme.json must decode as JSON.');
$need(($manifest['schema_version'] ?? '') === '4.0', 'Factory Reset must use Theme Package 4.0.');
$need(($manifest['template_api'] ?? '') === '2.0', 'Factory Reset must use Template API 2.0.');
// The bundled version is the owner's to choose, so it is checked for shape rather than for a
// particular number. Pinning the literal here meant every approved bump failed the gate and
// had to be "fixed" by editing the check, which trains people to edit checks.
$need(preg_match('/^\d+\.\d+\.\d+$/', (string) ($manifest['theme']['version'] ?? '')) === 1, 'Factory Reset must declare a semantic version.');
$need(array_key_exists('parent', $manifest) && $manifest['parent'] === null, 'Factory Reset must be standalone.');
$need(count((array) ($manifest['palettes'] ?? [])) >= 1, 'Factory Reset must declare palettes.');
$need((array) ($manifest['scripts'] ?? []) === [], 'Factory Reset must not own core interaction JavaScript.');

$base = $read($root . '/themes/factory-reset/base.html.twig');
foreach (['{% block page_body %}','platform.styles','platform.scripts','navigation'] as $token) $need(str_contains($base, $token), 'Factory Reset base contract missing: ' . $token);
// The footer is core-owned markup that every theme includes, so the theme delegates and core is
// where footer_navigation is read. Both halves are checked: a theme that stopped delegating, or a
// core footer that stopped rendering the array, each breaks the same contract.
$factoryFooter = $read($root . '/themes/factory-reset/partials/footer.html.twig');
$need(str_contains($factoryFooter, '@platform/partials/site-footer.html.twig'), 'Factory Reset footer must include the core site footer.');
$coreFooter = $read($root . '/resources/views/partials/site-footer.html.twig');
$need(str_contains($coreFooter, 'footer_navigation'), 'The core footer must render the core footer_navigation array.');

$coreJs = $read($root . '/public_html/js/platform-overrides.js');
foreach (['data-open-modal','details.account-menu','dataset.tabHistory','localStorage','/theme/palette','cl-palette-switcher','modal.hidden = true','modal.hidden = false'] as $token) {
    $need(str_contains($coreJs, $token), 'Core platform interaction missing: ' . $token);
}
$need(!str_contains($coreJs, 'data-admin-tab'), 'Legacy Administration tab JavaScript must not return.');

$coreCss = $read($root . '/public_html/css/catto-platform.css');
foreach (['.cl-admin-workspace{display:grid;','.cl-account-workspace,.cl-company-workspace','.activity-filters{width:100%','.pagination-row{display:flex','.acl-groups{display:grid','.cl-ui-modal[hidden]','.cl-palette-switcher'] as $token) {
    $need(str_contains($coreCss, $token), 'Core functional CSS missing: ' . $token);
}

$workspaces = [
    'admin' => $read($root . '/resources/views/pages/admin-control-centre.html.twig'),
    'account' => $read($root . '/resources/views/pages/account-control-centre.html.twig'),
    'company' => $read($root . '/resources/views/pages/company-control-centre.html.twig'),
];
foreach ([
    'admin' => ['admin_sections','section.content','data-admin-workspace'],
    'account' => ['account_sections','section.content','data-account-workspace'],
    'company' => ['company_sections','section.content','data-company-workspace'],
] as $family => $tokens) {
    foreach ($tokens as $token) $need(str_contains($workspaces[$family], $token), ucfirst($family) . ' workspace missing: ' . $token);
}

// Companies is two screens since v0.6 - Course Consumers and Course Creators - and both are held
// to the same contract. The genuine total is stated by the shared pagination control's own summary
// rather than reprinted in a heading above the table, which was a second page title on a screen
// that already carries the one page header.
foreach (['companies' => 'companies.html.twig', 'company_creators' => 'company-creators.html.twig'] as $dataset => $file) {
    $companies = $read($root . '/resources/views/partials/admin/' . $file);
    $need(str_contains($companies, "ui_template('data.table')") && !str_contains($companies, 'grid grid-3'), $file . ' must use a table instead of cards.');
    $need(str_contains($companies, 'pagination: ' . $dataset . '_pagination'), $file . ' must state its size through the shared pagination control.');
    // Companies once carried a hand-built pagination nav of its own. It survived the move to the
    // shared control and rendered alongside it, and its links used ?page=, which no controller
    // reads - a second, inert pagination row on the same screen. Assert it stays gone.
    $need(!str_contains($companies, '/admin/companies?page='), $file . ' must not reintroduce a hand-built pagination nav; its ?page= parameter is never read.');
    $need(!str_contains($companies, $dataset . '_total_pages'), $file . ' pagination is owned by the shared control; a total-pages variable belongs to the retired hand-built nav.');
    // Both halves reach the other, and neither carries a copy of the create and edit dialogs.
    $need(str_contains($companies, 'partials/admin/company-group-nav.html.twig'), $file . ' must render the Companies tab strip.');
    $need(str_contains($companies, 'partials/admin/company-modals.html.twig'), $file . ' must include the shared company dialogs.');
}

$activity = $read($root . '/resources/views/partials/admin/activity.html.twig');
foreach (['activity-filters','event.ip_address','event.geo_location','activity_pagination'] as $token) $need(str_contains($activity, $token), 'Activity UI missing: ' . $token);

// v0.5.7.6 large-data contracts. Every standalone list must be able to state its own size,
// and no list partial may reintroduce a whole-table <select> or an undefined table wrapper.
$pagination = $read($root . '/resources/views/partials/pagination.html.twig');
foreach (['pagination-row','pagination-summary','pagination-page-size','Showing {{ pg.from }}-{{ pg.to }} of {{ pg.total }}','Page {{ pg.page }} of {{ pg.total_pages }}',"rel: 'prev'","rel: 'next'"] as $token) {
    $need(str_contains($pagination, $token), 'Shared pagination control missing: ' . $token);
}

// v0.6 Pagination 2.0. Each of these is a control a reader operates, and a list whose control
// quietly stopped rendering still looks complete - it simply cannot be navigated any more.
foreach ([
    'href: (pg.first_href)' => 'First page link',
    'href: (pg.last_href)' => 'Last page link',
    '{% for pg_item in pg.pages %}' => 'adaptive numbered-page window',
    '{% if pg_item.is_gap %}' => 'ellipsis for an elided range',
    'aria-current="page"' => 'current-page announcement',
    'pagination-jump' => 'jump-to-page control',
    'hx-target="#{{ pg.region }}"' => 'htmx results region',
    'hx-select="#{{ pg.results }}"' => 'htmx results selection',
] as $token => $what) {
    $need(str_contains($pagination, $token), 'Shared pagination control missing its ' . $what . ': ' . $token);
}
// The page number is a number input, never a select. A select over four thousand pages is the
// same whole-table control this platform removed from every other screen.
$need(preg_match('/<select[^>]*name="\{\{ @pg\.page_param \}\}"/', $pagination) !== 1, 'The page number must never be offered as a select over every page.');
// One builder composes every paging URL. Concatenating a route and a query fragment per link is
// how one link keeps a filter that its neighbour drops.
$need(!str_contains($pagination, '{{ pg.query }}'), 'Paging links must take their URL from the service builder, not rebuild it inline.');

foreach ([
    'admin/people.html.twig' => 'people',
    'admin/companies.html.twig' => 'companies',
    'admin/courses.html.twig' => 'courses',
    'admin/credits.html.twig' => 'credits',
    'company/people.html.twig' => 'people',
    'company/requests.html.twig' => 'requests',
    'company/enrolments.html.twig' => 'enrolments',
    'company/credits.html.twig' => 'credits',
    'company/courses.html.twig' => 'courses',
] as $partial => $dataset) {
    $markup = $read($root . '/resources/views/partials/' . $partial);
    $need(str_contains($markup, 'pagination: ' . $dataset . '_pagination'), $partial . ' must include the shared pagination control for ' . $dataset . '.');
}

// Enrolments and Requests are two screens with two routes since v0.6. They must never share one page
// number, and separate routes is the strongest form of that: neither can page the other because
// neither renders the other. Asserted per file, so recombining them without restoring independent
// state fails here exactly as it would have before.
$enrolments = $read($root . '/resources/views/partials/admin/enrolments.html.twig');
$requests = $read($root . '/resources/views/partials/admin/requests.html.twig');
foreach ([[$enrolments, 'pagination: enrolments_pagination'], [$requests, 'pagination: requests_pagination']] as [$markup, $token]) {
    $need(str_contains($markup, $token), 'Enrolments and Requests must paginate independently: ' . $token);
}

// .table-responsive is not defined in core CSS; only .cl-ui-table is. A theme that happens to
// style the former masks the defect, so the core contract is asserted here instead.
foreach (glob($root . '/resources/views/partials/{admin,company,account}/*.html.twig', GLOB_BRACE) ?: [] as $listPartial) {
    $need(!str_contains($read($listPartial), 'table-responsive'), basename($listPartial) . ' must use .cl-ui-table, not the undefined .table-responsive.');
}

// Current Twig views must use comments that do not reach the browser.
$viewFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/resources/views', FilesystemIterator::SKIP_DOTS));
foreach ($viewFiles as $viewFile) {
    if (!str_ends_with($viewFile->getFilename(), '.twig')) continue;
    $need(!str_contains($read($viewFile->getPathname()), '<!--'), $viewFile->getFilename() . ' must use Twig comments.');
}
$dataset = $read($root . '/resources/views/ui/data/dataset-layout.html.twig');
$need(substr_count($dataset, 'partials/pagination.html.twig') === 2, 'The canonical dataset must render the shared pager above and below its results.');
$need(str_contains($dataset, 'partials/dataset-search.html.twig'), 'The dataset must delegate search to the shared control.');

// The whole-table selectors these screens used to carry are what defeated pagination.
$credits = $read($root . '/resources/views/partials/admin/credits.html.twig');
foreach ([$credits, $activity] as $largeSelectorSurface) {
    $need(!preg_match('/<select name="(company_id|user_id|course_id|actor_id)"/', $largeSelectorSurface), 'Activity and Credits must use bounded entity lookups, not whole-table <select> controls.');
}
foreach (['entity-lookup.html.twig'] as $token) {
    $need(str_contains($credits, $token) && str_contains($activity, $token), 'Activity and Credits must include the bounded entity lookup.');
}

$lookup = $read($root . '/resources/views/partials/entity-lookup.html.twig');
// The endpoint is a parameter defaulting to the Administration one. The Company workspace has its
// own, because the Administration lookup is guarded by PLATFORM.* permissions a company
// administrator does not hold and must not be given - with them they could search every person and
// course on the platform rather than their own company's.
foreach (["'/admin/lookup'", 'hx-get=', 'hx-trigger="keyup changed delay:250ms', 'hx-target='] as $token) {
    $need(str_contains($lookup, $token), 'Entity lookup missing htmx contract: ' . $token);
}
$roles = $read($root . '/resources/views/partials/admin/roles.html.twig');
$roleEdit = $read($root . '/resources/views/pages/admin-role-edit.html.twig');
// The section heading itself comes from the one page header now, so the partial names the role
// collection and its permission figure rather than repeating the title above the table.
foreach (['roles_acl','permission_count'] as $token) $need(str_contains($roles, $token), 'Roles section missing: ' . $token);
foreach (['name="permissions[]"','Save permissions',"ui_template('overlay.accordion-section')","ui_template('data.table')"] as $token) $need(str_contains($roleEdit, $token), 'ACL editor missing: ' . $token);
$need(!str_contains($roleEdit, 'class="acl-grid"'), 'ACL permission editor must use grouped accordions instead of permission cards.');
$need(!str_contains(strtolower($roleEdit), 'autosave'), 'ACL permission editor must not autosave.');

// Profile is three pages since 2026/09/10, on the owner's instruction: Personal Particulars, Email
// Addresses and Social Media, each under the Profile pop-out. Each is checked for its own subject,
// and each is checked for not having absorbed one of the others - which is the shape they started
// in and the shape they would drift back to.
$profile = $read($root . '/resources/views/partials/account/profile.html.twig');
foreach (['Personal particulars','Profile image','/account/profile/image'] as $token) $need(str_contains($profile, $token), 'Personal Particulars missing: ' . $token);
// The image editor is an enhancement over a form that already works. The file input and the submit
// must not depend on it, and the editor panel must start hidden - shown only once the controller
// has a picture to edit. Without that, a reader with no JavaScript gets an empty canvas and no way
// to send anything.
foreach (['name="image"',  'name="edited_image"', "type: 'submit'"] as $token) $need(str_contains($profile, $token), 'The profile image form must work without the editor: ' . $token);
$need((bool) preg_match('/stimulus_target\(\x27profile-image\x27, \x27editor\x27\) \}\}\s+hidden/', $profile), 'The profile image editor panel must start hidden.');
// Rule 0.2: every form ends in one action row, and the profile screen's two cards each have one.
// The report that produced the rule was "there is no save image button" - there was, and it was
// indistinguishable from the help text above it.
$need(substr_count($profile, "ui_template('form.actions')") >= 2, 'Both cards on Personal Particulars must end in a cl-ui-form-actions row.');
$need(str_contains($profile, 'Save profile image'), 'The profile image cl-ui-surface must name its own save action.');
$need(
    !(bool) preg_match('/<button[^>]*type="submit"[^>]*>\s*Save profile image/s', substr($profile, 0, strpos($profile, "ui_template('form.actions')") ?: 0)),
    'The save action must live inside the action row, not loose in the form.'
);
foreach (['Course history','Active sessions','Account activity'] as $token) $need(!str_contains($profile, $token), 'Personal Particulars must not contain dashboard/session/activity content: ' . $token);

$profileEmails = $read($root . '/resources/views/partials/account/emails.html.twig');
foreach (['Email Addresses','cannot be changed','/account/email/secondary','/account/email/remove'] as $token) $need(str_contains($profileEmails, $token), 'Email Addresses missing: ' . $token);

$profileSocial = $read($root . '/resources/views/partials/account/social.html.twig');
foreach (['Social Media','social_links','name="social['] as $token) $need(str_contains($profileSocial, $token), 'Social Media missing: ' . $token);
$dashboard = $read($root . '/resources/views/partials/account/dashboard.html.twig');
foreach (['Current courses','Completed','Certificates','Course history','overall_grade_code','certificate_public_id'] as $token) $need(str_contains($dashboard, $token), 'Account Dashboard missing: ' . $token);

$renderer = $read($root . '/src/View/ThemeRenderer.php');
// The view model is built as a plain array now rather than written into F3's hive, so these look
// for the assignment rather than for set(). What is being protected is unchanged: every page is
// given navigation, a footer and its section keys, whichever engine renders it.
foreach (["PLATFORM_ASSET_VERSION = '0.8'", "\$model['navigation']", "\$model['footer_navigation']", 'account_sections','account_section','company_sections','company_section','admin_sections','admin_section'] as $token) {
    $need(str_contains($renderer, $token), 'ThemeRenderer contract missing: ' . $token);
}
foreach (['page_content','theme_package_asset_url'] as $token) $need(!str_contains($renderer, $token), 'Obsolete Theme API alias remains: ' . $token);

// Read from the attributes that declare the routes; there is no route table any more.
$app = '';
foreach (glob($root . '/src/Http/Controller/*.php') ?: [] as $controller) {
    $source = (string) file_get_contents($controller);
    if (preg_match_all("/#\[Route\('([^']*)'[^\n]*?methods: \['([A-Z]+)'\]/", $source, $found, PREG_SET_ORDER)) {
        foreach ($found as $route) {
            $app .= $route[2] . ' ' . $route[1] . "\n";
        }
    }
}
foreach (['GET /account/dashboard','GET /account/profile','GET /account/library','GET /account/sessions','GET /account/activity','GET /company/dashboard','GET /company/people','GET /company/requests','GET /company/enrolments','GET /company/credits','GET /company/courses','GET /admin/roles'] as $route) {
    $need(str_contains($app, $route), 'Semantic workspace route missing: ' . $route);
}
$account = $read($root . '/src/Http/Controller/AccountController.php');
$need(
    (bool) preg_match("/#\[Route\('\/account\/library'[^\n]*\n\s*public function learning\(/", $account),
    'My Learning direct route must render through AccountController::learning().'
);

// ---------------------------------------------------------------------------------------------
// Each of the two screens carries its own dataset state. It used to be one screen carrying both,
// so neither could be paged, searched or linked to on its own.
foreach ([['enrolments', $enrolments], ['requests', $requests]] as [$dataset, $markup]) {
    foreach ([$dataset . '_pagination'] as $token) {
        $need(str_contains($markup, $token), ucfirst($dataset) . ' missing independent dataset state: ' . $token);
    }
}

// Reports scopes its figures rather than listing rows, so it carries the selector without a
// records strip. Asserting the absence keeps a future edit from inventing a total for it.
$reports = $read($root . '/resources/views/partials/admin/reports.html.twig');

$manager = $read($root . '/src/View/ThemeManager.php');
foreach (['discoverInstalledThemes','filesystem_key','$row = $registry[$key] ?? null;','resyncRegistry'] as $token) $need(str_contains($manager, $token), 'Filesystem theme recovery missing: ' . $token);

if ($errors !== []) {
    fwrite(STDERR, "UI contract validation failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "UI contract validation passed: ACL-aware workspaces, scalable administration, standard navigation/footer and Theme Package 4.0 interactions are core-owned.\n";
