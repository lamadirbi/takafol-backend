<?php

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AnnouncementReactionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampController;
use App\Http\Controllers\Api\CampFilterRecordController;
use App\Http\Controllers\Api\CampFilterRecordExportController;
use App\Http\Controllers\Api\CampRegistrationRequestController;
use App\Http\Controllers\Api\CampSubscriptionNoticeController;
use App\Http\Controllers\Api\ChangeRequestController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\DistributionController;
use App\Http\Controllers\Api\ExcelImportController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\FamilyMemberController;
use App\Http\Controllers\Api\FamilyPortalController;
use App\Http\Controllers\Api\PackageTypeController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\SiteSettingController;
use App\Http\Controllers\Api\SubscriptionRenewalRequestController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/camps', [CampController::class, 'index']);
Route::get('/camps/{slug}', [CampController::class, 'show']);
Route::post('/camp-registration-requests', [CampRegistrationRequestController::class, 'store']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:login');
Route::get('/announcements', [AnnouncementController::class, 'index']);
Route::get('/site-settings', [SiteSettingController::class, 'show']);
Route::get('/push/public-key', [PushController::class, 'publicKey']);
Route::get('/push/instant-app', [PushController::class, 'instantApp']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe']);
    Route::get('/push/instant-channel', [PushController::class, 'instantChannel']);
    Route::post('/push/instant-channel/test', [PushController::class, 'instantTest']);

    Route::middleware(['family_subscription', 'role:'.(User::ROLE_FAMILY_HEAD)])->group(function () {
        Route::get('/family/dashboard', [FamilyPortalController::class, 'dashboard']);
        Route::get('/family/change-requests', [ChangeRequestController::class, 'familyIndex']);
    });

    Route::middleware(['family_subscription', 'role:'.(User::ROLE_FAMILY_HEAD), 'family_no_grace'])->group(function () {
        Route::post('/family/change-requests', [ChangeRequestController::class, 'familyStore']);
        Route::patch('/family/change-requests/{changeRequest}', [ChangeRequestController::class, 'familyUpdate']);
    });

    Route::middleware(['family_subscription', 'family_no_grace'])->group(function () {
        Route::post('/push/subscribe', [PushController::class, 'subscribe']);
        Route::post('/announcements/{announcement}/reactions/toggle', [AnnouncementReactionController::class, 'toggle']);
        Route::post('/announcements/{announcement}/comments', [CommentController::class, 'store']);
    });

    Route::middleware('role:'.(User::ROLE_ADMIN))->group(function () {
        Route::get('/admin/camps', [CampController::class, 'adminIndex']);
        Route::post('/admin/camps', [CampController::class, 'store']);
        Route::patch('/admin/camps/{camp}', [CampController::class, 'update']);
        Route::delete('/admin/camps/{camp}', [CampController::class, 'destroy']);
        Route::get('/admin/camp-registration-requests', [CampRegistrationRequestController::class, 'adminIndex']);
        Route::patch('/admin/camp-registration-requests/{campRegistrationRequest}', [CampRegistrationRequestController::class, 'adminUpdate']);
        Route::get('/admin/dashboard-stats', [FamilyController::class, 'stats']);
        Route::apiResource('admin/families', FamilyController::class);
        Route::get('/admin/import/families-excel-template', [ExcelImportController::class, 'familiesTemplate']);
        Route::post('/admin/import/families-excel', [ExcelImportController::class, 'importFamilies']);
        Route::post('admin/families/{family}/members', [FamilyMemberController::class, 'store']);
        Route::patch('admin/families/{family}/members/{member}', [FamilyMemberController::class, 'update']);
        Route::delete('admin/families/{family}/members/{member}', [FamilyMemberController::class, 'destroy']);
        Route::get('/admin/distributions', [DistributionController::class, 'index']);
        Route::post('/admin/distributions/bulk', [DistributionController::class, 'bulkStore']);
        Route::post('/admin/distributions/bulk-cancel', [DistributionController::class, 'bulkCancel']);
        Route::post('/admin/distributions/bulk-confirm-received', [DistributionController::class, 'bulkConfirmReceived']);
        Route::post('/admin/distributions/bulk-rollback-received', [DistributionController::class, 'bulkRollbackReceived']);
        Route::post('/admin/distributions/confirm-family', [DistributionController::class, 'confirmReceivedForFamily']);
        Route::post('/admin/distributions/rollback-family', [DistributionController::class, 'rollbackForFamily']);
        Route::patch('/admin/distributions/{distribution}', [DistributionController::class, 'update']);
        Route::get('/admin/package-types', [PackageTypeController::class, 'index']);
        Route::post('/admin/package-types', [PackageTypeController::class, 'store']);
        Route::post('/admin/announcements', [AnnouncementController::class, 'store']);
        Route::delete('/admin/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
        Route::put('/admin/site-settings', [SiteSettingController::class, 'update']);
        Route::post('/admin/camp/subscription-notice-image', [CampSubscriptionNoticeController::class, 'store']);
        Route::delete('/admin/camp/subscription-notice-image', [CampSubscriptionNoticeController::class, 'destroy']);
        Route::get('/admin/camp/subscription-renewal-requests', [SubscriptionRenewalRequestController::class, 'index']);
        Route::post('/admin/camp/subscription-renewal-requests', [SubscriptionRenewalRequestController::class, 'store']);
        Route::get('/admin/camp-filter-records', [CampFilterRecordController::class, 'index']);
        Route::post('/admin/camp-filter-records/preview', [CampFilterRecordController::class, 'preview']);
        Route::post('/admin/camp-filter-records', [CampFilterRecordController::class, 'store']);
        Route::get('/admin/camp-filter-records/{campFilterRecord}', [CampFilterRecordController::class, 'show']);
        Route::patch('/admin/camp-filter-records/{campFilterRecord}', [CampFilterRecordController::class, 'update']);
        Route::delete('/admin/camp-filter-records/{campFilterRecord}', [CampFilterRecordController::class, 'destroy']);
        Route::get('/admin/camp-filter-records/{campFilterRecord}/export-excel', [CampFilterRecordExportController::class, 'exportExcel']);
        Route::get('/admin/camp-filter-records/{campFilterRecord}/export-members-excel', [CampFilterRecordExportController::class, 'exportMembersExcel']);
        Route::get('/admin/change-requests', [ChangeRequestController::class, 'adminIndex']);
        Route::post('/admin/change-requests/{changeRequest}/approve', [ChangeRequestController::class, 'adminApprove']);
        Route::post('/admin/change-requests/{changeRequest}/reject', [ChangeRequestController::class, 'adminReject']);
        Route::apiResource('admin/users', AdminUserController::class)->only(['index', 'store', 'destroy']);

        // Super Admin (Global) - renewal requests review
        Route::get('/admin/subscription-renewal-requests', [SubscriptionRenewalRequestController::class, 'superIndex']);
        Route::patch('/admin/subscription-renewal-requests/{subscriptionRenewalRequest}', [SubscriptionRenewalRequestController::class, 'superUpdate']);
    });
});
