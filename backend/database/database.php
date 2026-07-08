<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use SkillDo\Cache\Cache;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {

    public function up(): void
    {
        if(!schema()->hasTable('system'))
        {
            Schema()->create('system', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('option_name', 255)->nullable();
                $table->longText('option_value')->collation('utf8mb4_unicode_ci')->nullable();
                $table->string('theme', 255)->nullable();
                $table->string('plugin', 255)->nullable();
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
                $table->index('option_name');
            });

            // Seed tối giản cho deployment headless (CMS admin/theme tắt trong config/cms.php):
            // chỉ 2 role gốc — administrator (siêu quản trị của app, bypass mọi cap) và
            // subscriber (mặc định). Role nghiệp vụ tạo động ở màn Phân quyền; KHÔNG seed cap
            // e-commerce/theme của CMS. Lưu ý: `root` là tài khoản MASTER của framework
            // (đăng nhập qua license server), KHÔNG phải role trong DB — nên không seed ở đây.
            DB::table('system')->insert([
                [
                    'option_name' => 'language_default',
                    'option_value' => 'vi'
                ],
                [
                    'option_name' => 'user_roles',
                    'option_value' => '{"administrator":{"name":"Administrator","capabilities":{"administrator":true}},"subscriber":{"name":"Subscriber","capabilities":{"read":true}}}',
                ],
                [
                    'option_name' => 'api_user',
                    'option_value' => ''
                ],
                [
                    'option_name' => 'api_secret_key',
                    'option_value' => ''
                ]
            ]);
        }


        if(!schema()->hasTable('metabox'))
        {
            Schema()->create('metabox', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('object_id')->default(0);
                $table->string('object_type', 100)->nullable();
                $table->string('meta_key', 100)->nullable();
                $table->text('meta_value')->collation('utf8mb4_unicode_ci')->nullable();
                $table->integer('order')->default(0);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
                $table->index(['object_id', 'object_type', 'meta_key'], 'metabox');
            });
        }

        if(!schema()->hasTable('users'))
        {
            Schema()->create('users', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('username', 255)->nullable();
                $table->string('password', 255)->nullable();
                $table->string('salt', 255)->nullable();
                $table->string('firstname')->collation('utf8mb4_unicode_ci')->nullable();
                $table->string('lastname')->collation('utf8mb4_unicode_ci')->nullable();
                $table->string('email', 100)->nullable();
                $table->string('phone', 100)->nullable();
                $table->string('status', 50)->nullable();
                $table->string('role', 255)->default('subscriber');
                $table->tinyInteger('trash')->default(0);
                $table->bigInteger('password_changed_at')->default(0);
                $table->string('remember_token', 255)->nullable();
                $table->string('activation_key', 255)->nullable();
                $table->integer('time')->default(0);
                $table->string('address', 255)->collation('utf8mb4_unicode_ci')->nullable();
                $table->integer('city')->default(0);
                $table->integer('district')->default(0);
                $table->integer('ward')->default(0);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
                $table->index('email');
                $table->index('phone');
            });

            // Chừa id 1 cho tài khoản quản trị (seed ở UtilsApi::database). Raw SQL phải tự
            // ghép prefix theo cấu hình DB_PREFIX — KHÔNG hardcode (base nhân bản đổi prefix).
            DB::statement('ALTER TABLE `' . DB::getTablePrefix() . 'users` AUTO_INCREMENT = 2');
        }

        if(!schema()->hasTable('users_metadata'))
        {
            Schema()->create('users_metadata', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('object_id')->default(0);
                $table->string('meta_key', 255)->nullable();
                $table->text('meta_value')->collation('utf8mb4_unicode_ci')->nullable();
                $table->integer('order')->default(0);
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
                $table->index(['object_id', 'meta_key'], 'object_id_meta_key_pk');
            });
        }

        //Jwt Database
        if(!schema()->hasTable('oauth_access_tokens'))
        {
            schema()->create('oauth_access_tokens', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->string('token', 255)->collation('utf8mb3_unicode_ci')->unique();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->string('platform')->nullable();
                $table->string('browser')->nullable();
                $table->string('device')->nullable();
                $table->boolean('revoked');
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable();
            });
        }

        if(!schema()->hasTable('oauth_refresh_tokens'))
        {
            schema()->create('oauth_refresh_tokens', function (Blueprint $table)
            {
                $table->char('id', 36)->primary();
                $table->string('token', 255)->collation('utf8mb3_unicode_ci')->unique();
                $table->string('access_token_id', 255)->collation('utf8mb3_unicode_ci')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->boolean('revoked');
                $table->dateTime('expires_at')->nullable();
            });
        }

        //Api key database
        if(!schema()->hasTable('api_keys'))
        {
            schema()->create('api_keys', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->string('key_hash', 255)->collation('utf8mb3_unicode_ci')->unique();
                $table->string('key_hint', 10)->collation('utf8mb3_unicode_ci')->comment('10 ký tự cuối của api key để hiển thị cho người dùng biết');
                $table->string('platform')->nullable();
                $table->string('browser')->nullable();
                $table->string('device')->nullable();
                $table->enum('status', ['active', 'revoked', 'expired'])->default('active');
                $table->timestamp('expires_at')->nullable()->comment('ngày hêt hạn của api key');
                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->dateTime('updated')->nullable();
            });
        }

        /**
         * Migration Web Push — thông báo đẩy trên máy tính/mobile qua service worker (#26 mở rộng).
         * - notifications: "đầu ra" của mọi tiến trình nền — ghi qua Services/Notification/Notifier
         *   (fire-and-forget); FE poll GET api/notifications hiển thị chuông ở sidebar.
         * - push_subscriptions: mỗi dòng = 1 thiết bị/trình duyệt user đã bật thông báo đẩy
         *   (endpoint + cặp khoá p256dh/auth do PushManager của trình duyệt cấp).
         * - push_queue: hàng đợi gửi — Notifier ghi thông báo in-app xong thì enqueue 1 dòng
         *   cho TỪNG subscription của người nhận; tick Schedule (push-tick) gửi lần lượt.
         */
        if (!schema()->hasTable('notifications'))
        {
            Schema()->create('notifications', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->default(0)->index();

                // Loại sự kiện: info | success | warning | error | ... (chuỗi tự do,
                // thêm loại mới không cần đổi schema; FE map type → icon/màu).
                $table->string('type', 40)->default('')->index();

                $table->string('title', 255)->default('');
                $table->text('message')->collation('utf8mb4_unicode_ci')->nullable();

                // Đường dẫn FE mở khi bấm vào thông báo (vd /account). Rỗng = không điều hướng.
                $table->string('link', 255)->default('');

                $table->tinyInteger('is_read')->default(0);

                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));

                $table->index(['user_id', 'is_read'], 'user_unread');
                $table->index(['user_id', 'created'], 'user_created');
            });
        }

        if (!schema()->hasTable('push_subscriptions'))
        {
            Schema()->create('push_subscriptions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->default(0)->index();

                // Endpoint push service của trình duyệt (FCM/Mozilla/APNs web...) — có thể rất dài.
                $table->text('endpoint');

                // md5(endpoint) để upsert/dedup (endpoint là text, không unique trực tiếp được).
                $table->char('endpoint_hash', 32)->unique('endpoint_hash');

                // Cặp khoá mã hoá payload do PushManager cấp (RFC 8291).
                $table->string('p256dh', 255)->default('');
                $table->string('auth', 100)->default('');

                // Nhận diện thiết bị để hiển thị/gỡ lỗi (không dùng vào logic).
                $table->string('user_agent', 255)->default('');

                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));
            });
        }

        if (!schema()->hasTable('push_queue'))
        {
            Schema()->create('push_queue', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('subscription_id')->default(0)->index();
                $table->unsignedBigInteger('user_id')->default(0)->index();

                // Nội dung hiển thị trên notification hệ điều hành (payload gửi đi là JSON
                // của 4 cột này — giữ nhỏ, giới hạn payload push ~4KB).
                $table->string('type', 40)->default('');
                $table->string('title', 255)->default('');
                $table->string('message', 500)->default('');
                $table->string('link', 255)->default('');

                // pending → sending (đã claim) → sent | failed. Job lỗi tạm thời quay về
                // pending tới MAX_ATTEMPTS; endpoint hết hạn (404/410) → failed + xoá subscription.
                $table->string('status', 20)->default('pending');
                $table->tinyInteger('attempts')->default(0);
                $table->string('last_error', 255)->default('');

                // Thời điểm claim — dòng 'sending' kẹt quá lâu (tick chết giữa chừng) được reset.
                $table->dateTime('claimed_at')->nullable();
                $table->dateTime('sent_at')->nullable();

                $table->dateTime('created')->default(DB::raw('CURRENT_TIMESTAMP'));

                $table->index(['status', 'id'], 'status_id');
            });
        }

        Cache::flush();
    }

    public function down(): void
    {
    }
};