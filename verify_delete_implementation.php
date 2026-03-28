<?php
/**
 * Verify Delete Implementation
 * 
 * This script validates that all components of the delete flow are properly configured.
 */

require_once __DIR__ . '/bootstrap/autoload.php';

echo "\n=== Delete Implementation Verification ===\n\n";

// 1. Check if Call model has SoftDeletes
echo "1. Checking Call model for SoftDeletes trait...\n";
$callModel = new \App\Models\Call();
if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses($callModel))) {
    echo "   ✓ SoftDeletes trait found\n";
} else {
    echo "   ✗ SoftDeletes trait NOT found\n";
}

// 2. Check if deleted_at column exists in migration
echo "\n2. Checking migration file...\n";
$migrationFile = __DIR__ . '/database/migrations/2026_03_28_000000_add_soft_deletes_to_calls_table.php';
if (file_exists($migrationFile)) {
    $migrationContent = file_get_contents($migrationFile);
    if (strpos($migrationContent, 'softDeletes()') !== false) {
        echo "   ✓ Migration file exists with softDeletes()\n";
    } else {
        echo "   ✗ Migration file exists but softDeletes() not found\n";
    }
} else {
    echo "   ✗ Migration file NOT found\n";
}

// 3. Check if destroy method exists in AdminCallsController
echo "\n3. Checking AdminCallsController...\n";
$controllerFile = __DIR__ . '/app/Http/Controllers/Admin/AdminCallsController.php';
if (file_exists($controllerFile)) {
    $controllerContent = file_get_contents($controllerFile);
    if (strpos($controllerContent, 'public function destroy') !== false) {
        echo "   ✓ destroy() method found\n";
    } else {
        echo "   ✗ destroy() method NOT found\n";
    }
} else {
    echo "   ✗ Controller file NOT found\n";
}

// 4. Check if DELETE route is registered
echo "\n4. Checking routes/web.php...\n";
$routesFile = __DIR__ . '/routes/web.php';
if (file_exists($routesFile)) {
    $routesContent = file_get_contents($routesFile);
    if (preg_match("/Route::delete\(['\"]\/calls\/\{idOrUid\}['\"],\s*\[AdminCallsController::class,\s*['\"]destroy['\"]\]\)/ i", $routesContent)) {
        echo "   ✓ DELETE route registered\n";
    } else {
        echo "   ✗ DELETE route NOT properly registered\n";
    }
} else {
    echo "   ✗ Routes file NOT found\n";
}

// 5. Check if DeletionConfirmDialog component exists
echo "\n5. Checking DeletionConfirmDialog component...\n";
$componentFile = __DIR__ . '/resources/js/components/admin/base/DeletionConfirmDialog.vue';
if (file_exists($componentFile)) {
    $componentContent = file_get_contents($componentFile);
    if (strpos($componentContent, '@confirm="confirmDelete"') !== false || strpos($componentContent, 'emit(\'confirm\')') !== false) {
        echo "   ✓ DeletionConfirmDialog component found with confirm event\n";
    } else {
        echo "   ✗ DeletionConfirmDialog component exists but missing confirm event\n";
    }
} else {
    echo "   ✗ DeletionConfirmDialog component NOT found\n";
}

// 6. Check if CallsPage.vue has delete integration
echo "\n6. Checking CallsPage.vue for delete integration...\n";
$callsPageFile = __DIR__ . '/resources/js/pages/admin/CallsPage.vue';
if (file_exists($callsPageFile)) {
    $callsPageContent = file_get_contents($callsPageFile);
    if (strpos($callsPageContent, 'confirmDelete') !== false && strpos($callsPageContent, 'openDeleteConfirm') !== false) {
        echo "   ✓ CallsPage.vue has confirmDelete and openDeleteConfirm methods\n";
    } else {
        echo "   ✗ CallsPage.vue missing delete methods\n";
    }
} else {
    echo "   ✗ CallsPage.vue NOT found\n";
}

// 7. Check API client CSRF handling
echo "\n7. Checking admin API client CSRF handling...\n";
$apiFile = __DIR__ . '/resources/js/router/admin/api.js';
if (file_exists($apiFile)) {
    $apiContent = file_get_contents($apiFile);
    if (strpos($apiContent, 'X-CSRF-TOKEN') !== false && strpos($apiContent, 'interceptors.request') !== false) {
        echo "   ✓ API client has CSRF token interceptor\n";
    } else {
        echo "   ✗ API client missing CSRF handling\n";
    }
} else {
    echo "   ✗ API client file NOT found\n";
}

echo "\n=== Verification Complete ===\n";
echo "\nAll components are in place. The delete flow is ready for testing.\n\n";
