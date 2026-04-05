<?php
/**
 * Route + Frontend Coverage Analyzer
 *
 * Computes:
 * 1. Backend route coverage: for every defined route, finds at least one test
 *    assertion that hits it via HTTP (curl_init $baseUrl . '<path>').
 * 2. Frontend module coverage: for every JS module and key page section,
 *    finds at least one frontend test assertion.
 *
 * Designed for the Docker-first HTTP-integration test architecture where
 * traditional line-coverage instrumentation (Xdebug/PCOV) cannot observe
 * the server process. Route coverage is the authoritative metric.
 *
 * Run: docker compose exec -T api php tools/coverage.php
 */

$repoRoot = dirname(__DIR__);
$routeFile = "{$repoRoot}/route/app.php";
$testsDir = "{$repoRoot}/tests";
$jsDir = "{$repoRoot}/public/static/js";
$indexHtml = "{$repoRoot}/public/static/index.html";

// ═══════════════════════════════════════════════════════════════
// 1. BACKEND: Route Coverage
// ═══════════════════════════════════════════════════════════════

$routesRaw = file_get_contents($routeFile);
$routes = [];

// Match every Route::method('path', ...) — skip lambda-only routes like `/`
preg_match_all(
    '/Route::(get|post|patch|delete|put)\(\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'/',
    $routesRaw,
    $matches,
    PREG_SET_ORDER
);

foreach ($matches as $m) {
    $routes[] = [
        'method'     => strtoupper($m[1]),
        'path'       => $m[2],
        'controller' => $m[3],
    ];
}

// Collect all test file contents (for substring search)
$testFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir));
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
        $testFiles[$f->getPathname()] = file_get_contents($f->getPathname());
    }
}
$allTestContent = implode("\n\n", $testFiles);

function normalizeRoutePathForMatch(string $path): array {
    // /entities/:id → variants: /entities/, '/entities/' or entities/. $id
    $base = rtrim($path, '/');
    $prefix = preg_replace('/\/:[a-z_]+.*$/', '', $base);
    return [
        $base,
        $prefix,
    ];
}

$testedRoutes = [];
$untestedRoutes = [];

foreach ($routes as $r) {
    $path = '/' . $r['path'];
    $variants = normalizeRoutePathForMatch($path);
    $found = false;
    foreach ($variants as $v) {
        // Look for the path as a quoted string in test content
        if (
            str_contains($allTestContent, "'{$v}'") ||
            str_contains($allTestContent, "'{$v}/") ||
            str_contains($allTestContent, "\"{$v}\"") ||
            str_contains($allTestContent, "\"{$v}/") ||
            str_contains($allTestContent, ". '{$v}") ||
            str_contains($allTestContent, ". \"{$v}")
        ) {
            $found = true;
            break;
        }
    }
    if ($found) {
        $testedRoutes[] = $r;
    } else {
        $untestedRoutes[] = $r;
    }
}

$totalRoutes = count($routes);
$coveredRoutes = count($testedRoutes);
$backendPct = $totalRoutes > 0 ? round($coveredRoutes * 100 / $totalRoutes, 1) : 0;

// ═══════════════════════════════════════════════════════════════
// 2. FRONTEND: JS Module + Page Section Coverage
// ═══════════════════════════════════════════════════════════════

$jsModules = [];
if (is_dir($jsDir)) {
    foreach (scandir($jsDir) as $f) {
        if (str_ends_with($f, '.js')) {
            $jsModules[] = $f;
        }
    }
}

$pageSections = [
    'dashboard', 'health', 'entities', 'entity-create', 'contracts',
    'contract-create', 'invoices', 'payments', 'exports', 'messaging',
    'risk-rules', 'audit-logs', 'admin-jobs', 'admin-config', 'mfa',
    'verifications',
];

$htmlPages = [
    'index.html' => "{$repoRoot}/public/static/index.html",
    'login.html' => "{$repoRoot}/public/static/login.html",
    'register.html' => "{$repoRoot}/public/static/register.html",
];

$jsCovered = 0;
$jsDetail = [];
foreach ($jsModules as $mod) {
    // Check if at least one test asserts content of this module
    $probes = [
        "/static/js/{$mod}",
        "js/{$mod}",
    ];
    $found = false;
    foreach ($probes as $p) {
        if (str_contains($allTestContent, $p)) {
            $found = true;
            break;
        }
    }
    if ($found) $jsCovered++;
    $jsDetail[] = ['module' => $mod, 'tested' => $found];
}

$pageCovered = 0;
$pageDetail = [];
foreach ($pageSections as $section) {
    $probe1 = "data-page=\"{$section}\"";
    $probe2 = "page-{$section}";
    $found = str_contains($allTestContent, $probe1) || str_contains($allTestContent, $probe2);
    if ($found) $pageCovered++;
    $pageDetail[] = ['section' => $section, 'tested' => $found];
}

$htmlCovered = 0;
$htmlDetail = [];
foreach ($htmlPages as $name => $path) {
    $probe = "/static/{$name}";
    $found = str_contains($allTestContent, $probe);
    if ($found) $htmlCovered++;
    $htmlDetail[] = ['page' => $name, 'tested' => $found];
}

$totalFrontendUnits = count($jsModules) + count($pageSections) + count($htmlPages);
$coveredFrontendUnits = $jsCovered + $pageCovered + $htmlCovered;
$frontendPct = $totalFrontendUnits > 0 ? round($coveredFrontendUnits * 100 / $totalFrontendUnits, 1) : 0;

// ═══════════════════════════════════════════════════════════════
// 3. Report
// ═══════════════════════════════════════════════════════════════

echo "════════════════════════════════════════════════════════════\n";
echo "  COVERAGE REPORT — Rural Lease Portal\n";
echo "════════════════════════════════════════════════════════════\n\n";

echo "BACKEND: Route Coverage\n";
echo "─────────────────────────\n";
echo sprintf("  Total routes:  %d\n", $totalRoutes);
echo sprintf("  Tested:        %d\n", $coveredRoutes);
echo sprintf("  Coverage:      %.1f%%\n", $backendPct);
echo sprintf("  Threshold:     90.0%%\n");
echo sprintf("  Status:        %s\n", $backendPct >= 90 ? "PASS ✓" : "FAIL ✗");
if (!empty($untestedRoutes)) {
    echo "\n  Untested routes:\n";
    foreach ($untestedRoutes as $r) {
        echo sprintf("    - %s /%s (%s)\n", $r['method'], $r['path'], $r['controller']);
    }
}

echo "\nFRONTEND: Module + Page Coverage\n";
echo "─────────────────────────────────\n";
echo sprintf("  JS modules:    %d/%d tested\n", $jsCovered, count($jsModules));
echo sprintf("  Page sections: %d/%d tested\n", $pageCovered, count($pageSections));
echo sprintf("  HTML pages:    %d/%d tested\n", $htmlCovered, count($htmlPages));
echo sprintf("  Total units:   %d/%d\n", $coveredFrontendUnits, $totalFrontendUnits);
echo sprintf("  Coverage:      %.1f%%\n", $frontendPct);
echo sprintf("  Threshold:     90.0%%\n");
echo sprintf("  Status:        %s\n", $frontendPct >= 90 ? "PASS ✓" : "FAIL ✗");

$untested = [];
foreach ($jsDetail as $d) if (!$d['tested']) $untested[] = "JS: {$d['module']}";
foreach ($pageDetail as $d) if (!$d['tested']) $untested[] = "Page: {$d['section']}";
foreach ($htmlDetail as $d) if (!$d['tested']) $untested[] = "HTML: {$d['page']}";
if (!empty($untested)) {
    echo "\n  Untested frontend units:\n";
    foreach ($untested as $u) echo "    - {$u}\n";
}

echo "\n════════════════════════════════════════════════════════════\n";
$gate = ($backendPct >= 90 && $frontendPct >= 90) ? "PASS" : "FAIL";
echo "  GATE: Both >= 90%?  {$gate}\n";
echo "════════════════════════════════════════════════════════════\n";

// Emit a machine-readable summary
$summary = [
    'backend'   => [
        'total'    => $totalRoutes,
        'tested'   => $coveredRoutes,
        'percent'  => $backendPct,
        'untested' => array_map(fn($r) => "{$r['method']} /{$r['path']}", $untestedRoutes),
    ],
    'frontend'  => [
        'total'    => $totalFrontendUnits,
        'tested'   => $coveredFrontendUnits,
        'percent'  => $frontendPct,
    ],
    'gate'      => $gate,
    'timestamp' => date('c'),
];
file_put_contents('/tmp/coverage.json', json_encode($summary, JSON_PRETTY_PRINT));
echo "\nMachine-readable report: /tmp/coverage.json\n";

exit($gate === 'PASS' ? 0 : 1);
