<?php

use SkillDo\Support\Facades\Route;

// Đăng ký các nhóm quyền bổ sung vào màn Phân quyền (filter role_capabilities_groups).
require __ROOT__ . 'app/Roles/register.php';

// Đăng ký task nền vào Laravel Schedule (cron gọi schedule-run mỗi phút).
require __ROOT__ . 'app/Console/schedule.php';

// Ghi đè alias 'jwt' của framework bằng middleware có hỗ trợ "đăng nhập tài khoản khác"
// (đọc thêm header `loginAsToken`). File routes nạp sau service provider nên override an toàn.
Route::aliasMiddleware('jwt', \App\Http\Middlewares\JwtLoginAs::class);

Route::match(['get', 'post', 'put', 'patch', 'delete'], 'schedule-run', 'App\Controllers\Api\ScheduleController@run')->name('schedule.run');

/**
|--------------------------------------------------------------------------
| Auth Routes (Public — không cần token)
|--------------------------------------------------------------------------
*/
Route::post('api/auth/login', 'App\Controllers\Api\AuthController@login')->name('api.auth.login');
Route::post('api/auth/refresh', 'App\Controllers\Api\AuthController@refresh')->name('api.auth.refresh');

/**
|--------------------------------------------------------------------------
| Auth Routes (Protected — cần JWT token)
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/auth')
    ->group(function ()
    {
        Route::get('/current', 'AuthController@current')->name('api.auth.current');
        Route::post('/logout', 'AuthController@logout')->name('api.auth.logout');
        // User tự sửa hồ sơ (field vô hại) + tự đổi mật khẩu.
        Route::post('/update', 'AuthController@updateProfile')->name('api.auth.update');
        Route::post('/password', 'AuthController@changePassword')->name('api.auth.password');

        // Đăng nhập vào tài khoản khác (impersonation). Quyền xét trên TÀI KHOẢN GỐC.
        Route::get('/login-as/candidates', 'AuthController@loginAsCandidates')->name('api.auth.loginAs.candidates');
        Route::post('/login-as', 'AuthController@loginAs')->name('api.auth.loginAs');
        Route::post('/login-as/exit', 'AuthController@loginAsExit')->name('api.auth.loginAs.exit');
    });

/**
|--------------------------------------------------------------------------
| Phân quyền (Roles) — cần JWT token
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/role')
    ->group(function ()
    {
        Route::get('', 'RoleApi@index')->name('api.role.index');
        Route::get('/permission', 'RoleApi@permission')->name('api.role.permission');
        Route::post('', 'RoleApi@add')->name('api.role.add');
        Route::put('/permission/{role}', 'RoleApi@permissionUpdate')->name('api.role.permission.update');
        Route::get('/{role}', 'RoleApi@detail')->name('api.role.detail');
        Route::delete('/{role}', 'RoleApi@destroy')->name('api.role.destroy');
    });

/**
|--------------------------------------------------------------------------
| Khách hàng (Core CRM) — cần JWT token; gate cap trong controller
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/customer')
    ->group(function ()
    {
        Route::get('', 'CustomerApi@index')->name('api.customer.index');
        Route::post('', 'CustomerApi@add')->name('api.customer.add');
        Route::get('/{id}', 'CustomerApi@detail')->name('api.customer.detail');
        Route::put('/{id}', 'CustomerApi@update')->name('api.customer.update');
        Route::delete('/{id}', 'CustomerApi@destroy')->name('api.customer.destroy');
        // Timeline tương tác của khách.
        Route::get('/{id}/interactions', 'CustomerApi@interactions')->name('api.customer.interactions');
        Route::post('/{id}/interactions', 'CustomerApi@addInteraction')->name('api.customer.interactions.add');
    });

/**
|--------------------------------------------------------------------------
| Chăm sóc chủ động (lịch chăm sóc / nhắc việc) — cần JWT token
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/care')
    ->group(function ()
    {
        Route::get('', 'CareApi@index')->name('api.care.index');            // ?customer_id=
        Route::get('/today', 'CareApi@today')->name('api.care.today');
        Route::post('', 'CareApi@add')->name('api.care.add');
        Route::put('/{id}/complete', 'CareApi@complete')->name('api.care.complete');
        Route::delete('/{id}', 'CareApi@cancel')->name('api.care.cancel');
    });

/**
|--------------------------------------------------------------------------
| Bất động sản (Kho hàng) — cần JWT token; gate cap trong controller
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/property')
    ->group(function ()
    {
        Route::get('', 'PropertyApi@index')->name('api.property.index');
        Route::post('', 'PropertyApi@add')->name('api.property.add');
        Route::get('/{id}', 'PropertyApi@detail')->name('api.property.detail');
        Route::put('/{id}', 'PropertyApi@update')->name('api.property.update');
        Route::delete('/{id}', 'PropertyApi@destroy')->name('api.property.destroy');
    });

/**
|--------------------------------------------------------------------------
| Địa giới hành chính (tỉnh → phường) — công khai, dùng cho select địa chỉ
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->prefix('api/location')
    ->group(function ()
    {
        Route::get('/provinces', 'LocationApi@provinces')->name('api.location.provinces');
        Route::get('/wards', 'LocationApi@wards')->name('api.location.wards');
    });

/**
|--------------------------------------------------------------------------
| Tiện ích hệ thống
|--------------------------------------------------------------------------
| `index` cần đăng nhập (FE lấy danh sách chức vụ).
| `database` (khởi tạo/cập nhật DB) và `run` (scratch) KHÔNG đi qua jwt và KHÔNG
| kiểm tra auth — chúng được bật/tắt bằng biến môi trường `UTILS_API_OPEN` (xem
| UtilsApi::ensureOpen). Bật ở demo/dev để chạy nhanh; TẮT trên production.
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/utils')
    ->group(function ()
    {
        Route::get('', 'UtilsApi@index')->name('api.util.index');
    });

Route::namespace('App\Controllers\Api')
    ->prefix('api/utils')
    ->group(function ()
    {
        Route::get('/run', 'UtilsApi@run')->name('api.util.run');
        Route::get('/database', 'UtilsApi@database')->name('api.util.database');
    });

/**
|--------------------------------------------------------------------------
| Thông báo in-app — cần JWT token (thông báo của CHÍNH user, không cần cap)
|--------------------------------------------------------------------------
| Ghi bởi Services\Notification\Notifier từ các tiến trình nền. FE poll hiển thị chuông.
| Web Push: đăng ký/hủy thông báo đẩy của thiết bị hiện tại qua service worker.
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/notifications')
    ->group(function ()
    {
        Route::get('', 'NotificationController@index')->name('api.notifications.index');
        Route::post('/read', 'NotificationController@markRead')->name('api.notifications.read');

        // Web Push — đăng ký/hủy thông báo đẩy của thiết bị hiện tại (service worker).
        Route::get('/push/config', 'PushController@config')->name('api.notifications.push.config');
        Route::post('/push/subscribe', 'PushController@subscribe')->name('api.notifications.push.subscribe');
        Route::post('/push/unsubscribe', 'PushController@unsubscribe')->name('api.notifications.push.unsubscribe');
    });
