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
        // Danh sách nhân viên nhận bàn giao (đặt TRƯỚC /{id} để không bị nuốt bởi route param).
        Route::get('/users', 'CustomerApi@assignableUsers')->name('api.customer.users');
        // Xuất Excel (theo filter) + nhập Excel/CSV — đặt TRƯỚC /{id} để không bị nuốt.
        Route::get('/export', 'CustomerApi@export')->name('api.customer.export');
        Route::get('/import-template', 'CustomerApi@importTemplate')->name('api.customer.import.template');
        Route::post('/import', 'CustomerApi@import')->name('api.customer.import');
        Route::get('/{id}', 'CustomerApi@detail')->name('api.customer.detail');
        Route::put('/{id}', 'CustomerApi@update')->name('api.customer.update');
        Route::delete('/{id}', 'CustomerApi@destroy')->name('api.customer.destroy');
        // Bàn giao / thu hồi khách (cap customer_transfer).
        Route::post('/{id}/transfer', 'CustomerApi@transfer')->name('api.customer.transfer');
        // Timeline tương tác của khách.
        Route::get('/{id}/interactions', 'CustomerApi@interactions')->name('api.customer.interactions');
        Route::post('/{id}/interactions', 'CustomerApi@addInteraction')->name('api.customer.interactions.add');
        // Nhu cầu / tiêu chí của khách (cho Matching GĐ2).
        Route::get('/{id}/demands', 'CustomerApi@demands')->name('api.customer.demands');
        Route::post('/{id}/demands', 'CustomerApi@addDemand')->name('api.customer.demands.add');
        Route::put('/{id}/demands/{demandId}', 'CustomerApi@updateDemand')->name('api.customer.demands.update');
        Route::delete('/{id}/demands/{demandId}', 'CustomerApi@destroyDemand')->name('api.customer.demands.destroy');

        // Áp chuỗi kịch bản chăm sóc mặc định (auto_apply) cho khách hiện có.
        Route::post('/{id}/apply-care-sequence', 'CustomerApi@applyCareSequence')->name('api.customer.applyCareSequence');
        // Matching: gợi ý BĐS cho khách + gửi SP (ghi lịch sử + timeline). Cap matching_view/matching_send.
        Route::get('/{id}/match-properties', 'CustomerApi@matchProperties')->name('api.customer.matchProperties');
        Route::get('/{id}/matches', 'CustomerApi@matches')->name('api.customer.matches');
        Route::post('/{id}/matches', 'CustomerApi@sendMatch')->name('api.customer.matches.send');
        Route::put('/{id}/matches/{matchId}', 'CustomerApi@updateMatchStatus')->name('api.customer.matches.update');
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
| Lịch hẹn dẫn khách (GĐ2) — cần JWT token; gate cap appointment_* trong controller
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/appointment')
    ->group(function ()
    {
        Route::get('', 'AppointmentApi@index')->name('api.appointment.index');       // ?status=&customer_id=&from=&to=
        Route::post('', 'AppointmentApi@add')->name('api.appointment.add');
        Route::get('/{id}', 'AppointmentApi@detail')->name('api.appointment.detail');
        Route::put('/{id}', 'AppointmentApi@update')->name('api.appointment.update');
        Route::put('/{id}/complete', 'AppointmentApi@complete')->name('api.appointment.complete');
        Route::delete('/{id}', 'AppointmentApi@cancel')->name('api.appointment.cancel');
    });

/**
|--------------------------------------------------------------------------
| Giao dịch (GĐ2) — cần JWT token; gate cap deal_* trong controller
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/deal')
    ->group(function ()
    {
        Route::get('', 'DealApi@index')->name('api.deal.index');                          // ?keyword=&status=&customer_id=&property_id=
        Route::post('', 'DealApi@add')->name('api.deal.add');
        Route::get('/{id}', 'DealApi@detail')->name('api.deal.detail');
        Route::put('/{id}', 'DealApi@update')->name('api.deal.update');
        Route::put('/{id}/status', 'DealApi@changeStatus')->name('api.deal.status');
        Route::put('/{id}/commission', 'DealApi@updateCommission')->name('api.deal.commission');
        Route::delete('/{id}', 'DealApi@destroy')->name('api.deal.destroy');
        // Đợt thanh toán (sub-resource)
        Route::post('/{id}/payments', 'DealApi@addPayment')->name('api.deal.payments.add');
        Route::delete('/{id}/payments/{paymentId}', 'DealApi@deletePayment')->name('api.deal.payments.delete');
    });

/**
|--------------------------------------------------------------------------
| Báo cáo (GĐ2) — cần JWT token; gate cap report_* trong controller
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/report')
    ->group(function ()
    {
        Route::get('/export', 'ReportApi@export')->name('api.report.export');   // đặt TRƯỚC '' không cần, nhưng rõ ràng
        Route::get('', 'ReportApi@index')->name('api.report.index');            // ?from=&to=&assigned_user_id=
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
        // Media (ảnh/video) của BĐS.
        Route::get('/{id}/media', 'PropertyApi@mediaIndex')->name('api.property.media.index');
        Route::post('/{id}/media', 'PropertyApi@mediaUpload')->name('api.property.media.upload');
        Route::put('/{id}/media/reorder', 'PropertyApi@mediaReorder')->name('api.property.media.reorder');
        Route::delete('/{id}/media/{mediaId}', 'PropertyApi@mediaDelete')->name('api.property.media.delete');
        Route::put('/{id}/cover', 'PropertyApi@setCover')->name('api.property.cover');
        // Matching: gợi ý khách phù hợp BĐS này. Cap matching_view.
        Route::get('/{id}/match-customers', 'PropertyApi@matchCustomers')->name('api.property.matchCustomers');
    });

/**
|--------------------------------------------------------------------------
| Dashboard tổng hợp (trang chủ) — cần JWT token; gate cap customer_view
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->prefix('api/dashboard')
    ->group(function ()
    {
        Route::get('', 'DashboardApi@index')->name('api.dashboard.index');
    });

/**
|--------------------------------------------------------------------------
| Danh mục phụ (cấu hình) — cần JWT; đọc mở cho view cap (nạp dropdown ở form),
| ghi (thêm/sửa/xóa) gate cap `permission` (admin) trong controller.
|--------------------------------------------------------------------------
*/
Route::namespace('App\Controllers\Api')
    ->middleware('jwt')
    ->group(function ()
    {
        // Nguồn khách (form Khách hàng)
        Route::get('api/lead-source', 'LeadSourceApi@index')->name('api.leadSource.index');
        Route::post('api/lead-source', 'LeadSourceApi@add')->name('api.leadSource.add');
        Route::put('api/lead-source/{id}', 'LeadSourceApi@update')->name('api.leadSource.update');
        Route::delete('api/lead-source/{id}', 'LeadSourceApi@destroy')->name('api.leadSource.destroy');

        // Dự án (form Bất động sản)
        Route::get('api/project', 'ProjectApi@index')->name('api.project.index');
        Route::post('api/project', 'ProjectApi@add')->name('api.project.add');
        Route::put('api/project/{id}', 'ProjectApi@update')->name('api.project.update');
        Route::delete('api/project/{id}', 'ProjectApi@destroy')->name('api.project.destroy');

        // Chủ nhà (form Bất động sản)
        Route::get('api/property-owner', 'PropertyOwnerApi@index')->name('api.propertyOwner.index');
        Route::post('api/property-owner', 'PropertyOwnerApi@add')->name('api.propertyOwner.add');
        Route::put('api/property-owner/{id}', 'PropertyOwnerApi@update')->name('api.propertyOwner.update');
        Route::delete('api/property-owner/{id}', 'PropertyOwnerApi@destroy')->name('api.propertyOwner.destroy');

        // Kịch bản chăm sóc (form Chăm sóc)
        Route::get('api/care-template', 'CareTemplateApi@index')->name('api.careTemplate.index');
        Route::post('api/care-template', 'CareTemplateApi@add')->name('api.careTemplate.add');
        Route::put('api/care-template/{id}', 'CareTemplateApi@update')->name('api.careTemplate.update');
        Route::delete('api/care-template/{id}', 'CareTemplateApi@destroy')->name('api.careTemplate.destroy');

        // Dung lượng lưu trữ của user hiện tại (gói theo dung lượng).
        Route::get('api/storage', 'StorageApi@index')->name('api.storage.index');
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
| Trang công khai BĐS — link gửi khách xem, KHÔNG cần đăng nhập (ngoài jwt)
|--------------------------------------------------------------------------
| Chỉ trả field tiếp thị + liên hệ NV phụ trách; không lộ dữ liệu nội bộ.
| Xem docs/features/public-listing.md.
*/
Route::namespace('App\Controllers\Api')
    ->prefix('api/public')
    ->group(function ()
    {
        Route::get('/property/{code}', 'PublicPropertyApi@detail')->name('api.public.property');
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
