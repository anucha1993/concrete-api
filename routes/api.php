<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\LabelTemplateController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\PackController;
use App\Http\Controllers\PdaController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\StockDeductionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes (No Auth Required)
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Public PDA Routes (Token-based, No Login Required)
|--------------------------------------------------------------------------
*/

Route::prefix('pda')->group(function () {
    Route::get('/validate', [PdaController::class, 'validateToken']);
    Route::post('/verify', [PdaController::class, 'verify']);

    // Stock Count PDA endpoints
    Route::get('/stock-counts/active', [StockCountController::class, 'pdaActiveCounts']);
    Route::get('/stock-counts/{id}/progress', [StockCountController::class, 'pdaProgress']);
    Route::get('/stock-counts/{id}/scans', [StockCountController::class, 'pdaScans']);
    Route::post('/stock-counts/scan', [StockCountController::class, 'pdaScan']);
    Route::post('/stock-counts/scans/update-status', [StockCountController::class, 'pdaUpdateStatus']);
    Route::delete('/stock-counts/scans/{scanId}', [StockCountController::class, 'pdaDeleteScan']);

    // Stock Deduction PDA endpoints
    Route::get('/stock-deductions/active', [StockDeductionController::class, 'pdaActive']);
    Route::get('/stock-deductions/{id}/progress', [StockDeductionController::class, 'pdaProgress']);
    Route::get('/stock-deductions/{id}/scans', [StockDeductionController::class, 'pdaScans']);
    Route::post('/stock-deductions/scan', [StockDeductionController::class, 'pdaScan']);
    Route::delete('/stock-deductions/scans/{scanId}', [StockDeductionController::class, 'pdaDeleteScan']);

    // Claim PDA endpoints (CRL)
    Route::get('/claims/active', [ClaimController::class, 'pdaActive']);
    Route::get('/claims/{id}/progress', [ClaimController::class, 'pdaProgress']);
    Route::get('/claims/{id}/scans', [ClaimController::class, 'pdaScans']);
    Route::post('/claims/scan', [ClaimController::class, 'pdaScan']);
    Route::put('/claims/scans/{lineId}/resolution', [ClaimController::class, 'pdaUpdateResolution']);
    Route::delete('/claims/scans/{lineId}', [ClaimController::class, 'pdaDeleteScan']);
});

/*
|--------------------------------------------------------------------------
| Protected Routes (Auth + Active User Required)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // ── Auth ─────────────────────────────────────────────
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // ── Products ─────────────────────────────────────────
    Route::middleware('permission:view_products')->group(function () {
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
    });

    Route::middleware('permission:manage_products')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    });

    // ── Categories ───────────────────────────────────────
    Route::middleware('permission:view_products')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::get('/categories/{category}', [CategoryController::class, 'show']);
    });

    Route::middleware('permission:manage_products')->group(function () {
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    });

    // ── Locations ────────────────────────────────────────
    Route::middleware('permission:view_locations')->group(function () {
        Route::get('/locations', [LocationController::class, 'index']);
        Route::get('/locations/{location}', [LocationController::class, 'show']);
    });

    Route::middleware('permission:manage_locations')->group(function () {
        Route::post('/locations', [LocationController::class, 'store']);
        Route::put('/locations/{location}', [LocationController::class, 'update']);
        Route::delete('/locations/{location}', [LocationController::class, 'destroy']);
    });

    // ── Users ────────────────────────────────────────────
    Route::middleware('permission:view_users')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
    });

    Route::middleware('permission:manage_users')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });

    // ── Roles ────────────────────────────────────────────
    Route::middleware('permission:manage_roles')->group(function () {
        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::get('/roles/{role}', [RoleController::class, 'show']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
        Route::post('/roles/{role}/permissions', [RoleController::class, 'assignPermissions']);
    });

    // ── Packs (แพสินค้า) ─────────────────────────────────
    Route::middleware('permission:view_products')->group(function () {
        Route::get('/packs', [PackController::class, 'index']);
        Route::get('/packs/{pack}', [PackController::class, 'show']);
    });

    Route::middleware('permission:manage_products')->group(function () {
        Route::post('/packs', [PackController::class, 'store']);
        Route::put('/packs/{pack}', [PackController::class, 'update']);
        Route::delete('/packs/{pack}', [PackController::class, 'destroy']);
    });

    // ── Permissions ──────────────────────────────────────
    Route::middleware('permission:manage_roles')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index']);
    });

    // ── Production Orders (ใบสั่งผลิต) ───────────────────
    Route::middleware('permission:view_production')->group(function () {
        Route::get('/production-orders', [ProductionOrderController::class, 'index']);
        Route::get('/production-orders/{productionOrder}', [ProductionOrderController::class, 'show']);
        Route::get('/production-orders/{productionOrder}/serials', [ProductionOrderController::class, 'serials']);
    });

    Route::middleware('permission:manage_production')->group(function () {
        Route::post('/production-orders', [ProductionOrderController::class, 'store']);
        Route::post('/production-orders/{productionOrder}/confirm', [ProductionOrderController::class, 'confirm']);
        Route::post('/production-orders/{productionOrder}/start', [ProductionOrderController::class, 'start']);
        Route::post('/production-orders/{productionOrder}/receive', [ProductionOrderController::class, 'receive']);
        Route::post('/production-orders/{productionOrder}/cancel', [ProductionOrderController::class, 'cancel']);
    });

    // ── Inventory (คลังสินค้า) ────────────────────────────
    Route::middleware('permission:view_inventory')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/inventory/summary', [InventoryController::class, 'summary']);
        Route::get('/inventory/alerts', [InventoryController::class, 'alerts']);
        Route::get('/inventory/{inventory}', [InventoryController::class, 'show']);
    });
    Route::middleware('permission:manage_inventory')->group(function () {
        Route::put('/inventory/{inventory}', [InventoryController::class, 'update']);
    });

    // ── Labels (ปริ้น Barcode) ────────────────────────────
    Route::middleware('permission:view_production')->group(function () {
        Route::get('/labels/production-orders', [LabelController::class, 'productionOrders']);
        Route::get('/labels/production-orders/{id}/serials', [LabelController::class, 'productionOrderSerials']);
        Route::get('/labels/printable', [LabelController::class, 'printable']);
        Route::get('/labels/history', [LabelController::class, 'history']);
        Route::get('/labels/stats', [LabelController::class, 'stats']);
        Route::get('/labels/reprint-requests', [LabelController::class, 'reprintRequests']);
        Route::get('/labels/reprint-requests/{reprintRequest}', [LabelController::class, 'showReprintRequest']);
    });

    Route::middleware('permission:manage_production')->group(function () {
        Route::post('/labels/print', [LabelController::class, 'print']);
        Route::post('/labels/print-by-po', [LabelController::class, 'printByProductionOrder']);
        Route::post('/labels/verify', [LabelController::class, 'verify']);
        Route::post('/labels/verify-batch', [LabelController::class, 'verifyBatch']);
        Route::post('/labels/reprint', [LabelController::class, 'reprint']);
        Route::post('/labels/reprint-requests', [LabelController::class, 'createReprintRequest']);
        Route::post('/labels/reprint-requests/{reprintRequest}/approve', [LabelController::class, 'approveReprint']);
        Route::post('/labels/reprint-requests/{reprintRequest}/reject', [LabelController::class, 'rejectReprint']);
    });

    // ── Label Templates (ออกแบบ Label) ────────────────────
    Route::middleware('permission:view_production')->group(function () {
        Route::get('/label-templates', [LabelTemplateController::class, 'index']);
        Route::get('/label-templates/{labelTemplate}', [LabelTemplateController::class, 'show']);
    });
    Route::middleware('permission:manage_production')->group(function () {
        Route::post('/label-templates', [LabelTemplateController::class, 'store']);
        Route::put('/label-templates/{labelTemplate}', [LabelTemplateController::class, 'update']);
        Route::delete('/label-templates/{labelTemplate}', [LabelTemplateController::class, 'destroy']);
    });

    // ── PDA Token Management ─────────────────────────────
    Route::middleware('permission:manage_operations')->group(function () {
        Route::post('/pda-tokens', [PdaController::class, 'createToken']);
        Route::get('/pda-tokens', [PdaController::class, 'listTokens']);
        Route::post('/pda-tokens/{pdaToken}/revoke', [PdaController::class, 'revokeToken']);
    });

    // ── Stock Counts (ตรวจนับสต๊อก) ──────────────────────
    Route::middleware('permission:view_operations')->group(function () {
        Route::get('/stock-counts', [StockCountController::class, 'index']);
        Route::get('/stock-counts/{stockCount}', [StockCountController::class, 'show']);
        Route::get('/stock-counts/{stockCount}/scans', [StockCountController::class, 'scans']);
        Route::get('/stock-counts/{stockCount}/missing-serials', [StockCountController::class, 'missingSerials']);
        Route::get('/stock-counts/{stockCount}/report', [StockCountController::class, 'report']);
    });

    Route::middleware('permission:manage_operations')->group(function () {
        Route::post('/stock-counts', [StockCountController::class, 'store']);
        Route::post('/stock-counts/{stockCount}/start', [StockCountController::class, 'start']);
        Route::post('/stock-counts/{stockCount}/complete', [StockCountController::class, 'complete']);
        Route::post('/stock-counts/{stockCount}/approve', [StockCountController::class, 'approve']);
        Route::post('/stock-counts/{stockCount}/cancel', [StockCountController::class, 'cancel']);
        Route::post('/stock-counts/{stockCount}/resolve-serial', [StockCountController::class, 'resolveSerial']);
        Route::post('/stock-counts/{stockCount}/resolve-scan', [StockCountController::class, 'resolveScan']);
    });

    // ── Stock Deductions (ตัดสต๊อก) ──────────────────────
    Route::middleware('permission:view_operations')->group(function () {
        Route::get('/stock-deductions', [StockDeductionController::class, 'index']);
        Route::get('/stock-deductions/{stockDeduction}', [StockDeductionController::class, 'show']);
    });

    Route::middleware('permission:manage_operations')->group(function () {
        Route::post('/stock-deductions', [StockDeductionController::class, 'store']);
        Route::put('/stock-deductions/{stockDeduction}', [StockDeductionController::class, 'update']);
        Route::delete('/stock-deductions/{stockDeduction}', [StockDeductionController::class, 'destroy']);
        Route::post('/stock-deductions/{stockDeduction}/submit', [StockDeductionController::class, 'submit']);
        Route::post('/stock-deductions/{stockDeduction}/complete', [StockDeductionController::class, 'complete']);
        Route::post('/stock-deductions/{stockDeduction}/approve', [StockDeductionController::class, 'approve']);
        Route::post('/stock-deductions/{stockDeduction}/cancel', [StockDeductionController::class, 'cancel']);
        Route::post('/stock-deductions/{stockDeduction}/scan', [StockDeductionController::class, 'adminScan']);
        Route::delete('/stock-deductions/{stockDeduction}/scans/{scanId}', [StockDeductionController::class, 'adminDeleteScan']);
        Route::post('/stock-deductions/{stockDeduction}/generate-print-token', [StockDeductionController::class, 'generatePrintToken']);
    });

    // ── Claims (เคลมสินค้า) ──────────────────────────────
    Route::middleware('permission:view_operations')->group(function () {
        Route::get('/claims', [ClaimController::class, 'index']);
        Route::get('/claims/search-items', [ClaimController::class, 'searchItems']);
        Route::get('/claims/{claim}', [ClaimController::class, 'show']);
    });

    Route::middleware('permission:manage_operations')->group(function () {
        Route::post('/claims', [ClaimController::class, 'store']);
        Route::put('/claims/{claim}', [ClaimController::class, 'update']);
        Route::delete('/claims/{claim}', [ClaimController::class, 'destroy']);
        Route::post('/claims/{claim}/submit', [ClaimController::class, 'submit']);
        Route::post('/claims/{claim}/approve', [ClaimController::class, 'approve']);
        Route::post('/claims/{claim}/reject', [ClaimController::class, 'reject']);
        Route::post('/claims/{claim}/cancel', [ClaimController::class, 'cancel']);
        Route::post('/claims/{claim}/generate-pda', [ClaimController::class, 'generatePda']);
    });

    // ── Reports (รายงาน) ─────────────────────────────────
    Route::middleware('permission:view_reports')->group(function () {
        Route::get('/reports/inventory', [ReportController::class, 'inventory']);
        Route::get('/reports/stock-deductions', [ReportController::class, 'stockDeductions']);
        Route::get('/reports/claims', [ReportController::class, 'claims']);
        Route::get('/reports/production', [ReportController::class, 'production']);
        Route::get('/reports/movements', [ReportController::class, 'movements']);

        // Excel exports
        Route::get('/reports/export/inventory', [ReportController::class, 'exportInventory']);
        Route::get('/reports/export/stock-deductions', [ReportController::class, 'exportStockDeductions']);
        Route::get('/reports/export/claims', [ReportController::class, 'exportClaims']);
        Route::get('/reports/export/production', [ReportController::class, 'exportProduction']);
        Route::get('/reports/export/movements', [ReportController::class, 'exportMovements']);
    });
});
