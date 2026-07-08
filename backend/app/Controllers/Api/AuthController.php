<?php

namespace App\Controllers\Api;

use Illuminate\Support\Str;
use SkillDo\Api\Repository\RefreshTokenRepository;
use SkillDo\Api\Repository\TokenRepository;
use SkillDo\Cache\Cache;
use SkillDo\Cms\Models\User;
use SkillDo\Cms\Support\Role;
use SkillDo\Cms\Support\UserRole;
use SkillDo\Http\Request;
use SkillDo\Routing\Controller\Controller;
use SkillDo\Support\Auth;
use SkillDo\Validate\Rule;

class AuthController extends Controller
{
    /**
     * Tập trạng thái tài khoản bị VÔ HIỆU — không được đăng nhập / cấp lại token.
     * Dùng chung cho login + refresh (và tham chiếu ở JwtLoginAs) để không lệch nhau.
     * @var string[]
     */
    const INACTIVE_STATUSES = ['block', 'suspended', 'pending', 'trash'];

    /** Chống brute-force đăng nhập: tối đa số lần sai / thời gian khoá (phút). */
    const LOGIN_MAX_ATTEMPTS = 5;

    const LOGIN_LOCK_MINUTES = 15;

    public function login(Request $request): void
    {
        try
        {
            $validate = $request->validate([
                'username' => Rule::make('username')->notEmpty(),
                'password' => Rule::make('password')->notEmpty(),
            ]);

            if ($validate->fails())
            {
                response()
                    ->setStatusCode(422)
                    ->setApiStatus(422)
                    ->error($validate->errors(), $validate->errors()->errors());
            }

            $username = Str::clear($request->input('username'));

            // KHÔNG Str::clear mật khẩu: strip_tags sẽ cắt mất ký tự '<' trong khi
            // changePassword lưu hash của mật khẩu raw → user có mật khẩu chứa '<'
            // sẽ không bao giờ đăng nhập được. Mật khẩu chỉ đem verify, không render.
            $password = (string) $request->input('password');

            // Chống brute-force: khoá tạm theo (username + IP) sau nhiều lần sai liên tiếp.
            $throttleKey = 'login_fail_' . md5(mb_strtolower($username) . '|' . $request->ip());

            if ((int) Cache::get($throttleKey, 0) >= self::LOGIN_MAX_ATTEMPTS)
            {
                response()
                    ->setStatusCode(429)
                    ->setApiStatus(429)
                    ->error('Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau ' . self::LOGIN_LOCK_MINUTES . ' phút.');
            }

            // Tìm user bằng username → email → phone
            $user = User::where('username', $username)->first();

            if (!hasItems($user))
            {
                $user = User::where('email', $username)->first();
            }

            if (!hasItems($user))
            {
                $user = User::where('phone', $username)->first();
            }

            if (!hasItems($user))
            {
                // Không tồn tại user → chạy một phép băm giả để cân bằng thời gian phản hồi,
                // tránh lộ "user tồn tại hay không" qua chênh lệch timing (user-enumeration).
                password_verify($password, '$2y$10$usesomesillystringforsalt0000000000000000000000000000000e');

                $this->recordLoginFailure($throttleKey);

                response()
                    ->setStatusCode(401)
                    ->setApiStatus(401)
                    ->error('Đăng nhập thất bại - Tên đăng nhập hoặc mật khẩu không chính xác.');
            }

            if (!Auth::passwordConfirm($password, $user))
            {
                $this->recordLoginFailure($throttleKey);

                response()
                    ->setStatusCode(401)
                    ->setApiStatus(401)
                    ->error('Đăng nhập thất bại - Tên đăng nhập hoặc mật khẩu không chính xác.');
            }

            // Tài khoản vô hiệu (kể cả 'trash' — xoá mềm) không được đăng nhập,
            // đồng nhất với refresh() và JwtLoginAs (cùng dùng INACTIVE_STATUSES).
            if (in_array($user->status, self::INACTIVE_STATUSES, true))
            {
                $messages = [
                    'suspended' => 'Tài khoản của bạn đang tạm dừng. Vui lòng liên hệ với quản trị viên.',
                    'block'     => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ với quản trị viên để biết thêm chi tiết.',
                    'pending'   => 'Tài khoản của bạn đang chờ duyệt.',
                ];

                response()
                    ->setStatusCode(403)
                    ->setApiStatus(403)
                    ->error($messages[$user->status] ?? 'Tài khoản của bạn đã bị vô hiệu hoá.');
            }

            // Đăng nhập thành công → xoá bộ đếm sai.
            Cache::delete($throttleKey);

            $this->issueLoginResponse($user, $username);
        }
        catch (\Exception $e)
        {
            error_log('[AuthController::login] ' . $e->getMessage());
            response()->error('Đăng nhập thất bại. Vui lòng thử lại.');
        }
    }

    /** Tăng bộ đếm đăng nhập sai (tự hết hạn sau LOGIN_LOCK_MINUTES phút). */
    protected function recordLoginFailure(string $throttleKey): void
    {
        Cache::save($throttleKey, (int) Cache::get($throttleKey, 0) + 1, self::LOGIN_LOCK_MINUTES);
    }

    /**
     * Phát hành access + refresh token rồi TRẢ RESPONSE đăng nhập (send-and-exit).
     * Dùng chung cho đăng nhập mật khẩu và đăng nhập Google — mọi bước kiểm tra
     * (mật khẩu/credential + trạng thái tài khoản) phải xong TRƯỚC khi gọi.
     */
    protected function issueLoginResponse(User $user, string $loginName): void
    {
        $accessToken = TokenRepository::getInstance()->create($user->id);

        if(empty($accessToken['token']))
        {
            response()
                ->setStatusCode(500)
                ->setApiStatus(500)
                ->error('Đăng nhập thất bại - Không thể tạo token');
        }

        $refreshToken = RefreshTokenRepository::getInstance()->create($user->id, $accessToken['token']);

        // Ghi mốc đăng nhập gần nhất (meta) — tiện cho quản trị / audit.
        User::updateMeta((int) $user->id, 'last_login', date('Y-m-d H:i:s'));

        // Bind user vào container
        app()->instance('user', $user);

        $permissions = Auth::getCap();

        response()->success('Login successful', [
            'tokenType'     => 'Bearer',
            'accessToken'   => $accessToken['token'],
            'expires'       => $accessToken['expires_at'],
            'refreshToken'  => $refreshToken['token'],
            'data' => [
                'user' => $this->userPayload($user),
                'permissions' => $permissions
            ],
        ]);
    }

    public function logout(Request $request): void
    {
        try
        {
            $token = $request->header('Authorization', '');

            if(empty($token))
            {
                response()
                    ->setStatusCode(401)
                    ->setApiStatus(401)
                    ->error('Unauthorized');
            }

            TokenRepository::getInstance()->revoke($token);

            RefreshTokenRepository::getInstance()->revokeByAccessToken($token);

            app()->instance('user', null);

            response()->success('Logout successful');
        }
        catch (\Exception $e)
        {
            error_log('[AuthController::logout] ' . $e->getMessage());
            response()->error('Đăng xuất thất bại. Vui lòng thử lại.');
        }
    }

    public function refresh(Request $request): void
    {
        $validate = $request->validate([
            'refresh_token' => Rule::make('refresh Token')->notEmpty(),
        ]);

        if ($validate->fails())
        {
            response()
                ->setStatusCode(422)
                ->setApiStatus(422)
                ->error($validate->errors(), $validate->errors()->errors());
        }

        $token = $request->input('refresh_token');

        try {

            $decoded = RefreshTokenRepository::getInstance()->decode($token);

            if (empty($decoded->id))
            {
                response()
                    ->setStatusCode(401)
                    ->setApiStatus(401)
                    ->error('Unauthorized');
            }

            $refreshToken = RefreshTokenRepository::getInstance()->find($token);

            if (!hasItems($refreshToken))
            {
                response()
                    ->setStatusCode(401)
                    ->setApiStatus(401)
                    ->error('Refresh token not found');
            }

            $user = User::find($decoded->id);

            if (!hasItems($user))
            {
                response()
                    ->setStatusCode(401)
                    ->setApiStatus(401)
                    ->error('You don\'t have the required permissions to access the API');
            }

            // Tài khoản bị vô hiệu (khoá/tạm dừng/chờ duyệt) không được cấp lại token —
            // nếu không admin khoá user nhưng user vẫn refresh để lấy access token mới.
            if (in_array($user->status, self::INACTIVE_STATUSES, true))
            {
                RefreshTokenRepository::getInstance()->revoke($token);

                response()
                    ->setStatusCode(403)
                    ->setApiStatus(403)
                    ->error('Tài khoản của bạn đã bị vô hiệu hoá.');
            }

            // Refresh token rotation: revoke cũ, tạo mới
            RefreshTokenRepository::getInstance()->revoke($token);

            TokenRepository::getInstance()->revoke($refreshToken->access_token_id);

            $accessToken = TokenRepository::getInstance()->create($user->id);

            $newRefreshToken = RefreshTokenRepository::getInstance()->create($user->id, $accessToken['token']);

            response()->success('Token refreshed', [
                'tokenType' => 'Bearer',
                'accessToken' => $accessToken['token'],
                'refreshToken' => $newRefreshToken['token'],
                'expires' => $accessToken['expires_at'],
                'data' => [],
            ]);
        }
        catch (\Exception $e)
        {
            response()
                ->setStatusCode(401)
                ->setApiStatus(401)
                ->error('Invalid token');
        }
    }

    public function current(Request $request): void
    {
        try
        {
            $user = Auth::user();

            if(!hasItems($user))
            {
                response()
                    ->setStatusCode(401)
                    ->setApiStatus(401)
                    ->error('Unauthorized');
            }

            // Gộp quyền theo chức vụ + quyền riêng của user (đồng nhất với login).
            // Nhờ vậy quản trị viên nhận {administrator: 1} và được FE coi là siêu quản trị.
            $permissions = Auth::getCap();

            response()->success('User', [
                'user' => $this->userPayload($user),
                'permissions' => $permissions,
                'utilitiesKey' => Cache::get('utilsDataKey')
            ]);
        }
        catch (\Exception $e)
        {
            error_log('[AuthController::current] ' . $e->getMessage());
            response()->error('Không lấy được thông tin người dùng.');
        }
    }

    /**
     * POST api/auth/update — user TỰ cập nhật hồ sơ, CHỈ các field vô hại (họ tên /
     * điện thoại / địa chỉ). Email, username, chức vụ vẫn phải qua admin (MemberController).
     * Trả về user payload mới để FE cập nhật thẳng redux (authActions.update).
     */
    public function updateProfile(Request $request): void
    {
        $user = Auth::user();

        if (!hasItems($user))
        {
            response()->setStatusCode(401)->setApiStatus(401)->error('Unauthorized');
        }

        $data = [];

        foreach (['firstname', 'lastname', 'address'] as $field)
        {
            if ($request->has($field))
            {
                $data[$field] = mb_substr(Str::clear(trim((string) $request->input($field))), 0, 255);
            }
        }

        // Tên hiển thị không được để trống (login/menu dùng firstname).
        if (isset($data['firstname']) && $data['firstname'] === '')
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Tên không được để trống.');
        }

        if ($request->has('phone'))
        {
            $phone = trim(Str::clear((string) $request->input('phone')));

            if ($phone !== '' && !preg_match('/^[0-9+][0-9 .()-]{7,14}$/', $phone))
            {
                response()->setStatusCode(422)->setApiStatus(422)->error('Số điện thoại không hợp lệ.');
            }

            $data['phone'] = $phone;
        }

        if (empty($data))
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Không có thông tin nào để cập nhật.');
        }

        User::where('id', (int) $user->id)->update($data);

        $fresh = User::find((int) $user->id);

        response()->success('Đã cập nhật hồ sơ.', ['user' => $this->userPayload($fresh)]);
    }

    /**
     * POST api/auth/password — user tự đổi mật khẩu. body: { passCurrent, passNew }
     * (khớp form FE AccountFormPassword). Xác nhận mật khẩu hiện tại trước khi đổi;
     * giữ nguyên phiên đang đăng nhập (đổi chủ động, không phải reset của admin).
     */
    public function changePassword(Request $request): void
    {
        $user = Auth::user();

        if (!hasItems($user))
        {
            response()->setStatusCode(401)->setApiStatus(401)->error('Unauthorized');
        }

        $current = (string) $request->input('passCurrent');
        $new     = (string) $request->input('passNew');

        if (mb_strlen($new) < 6)
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Mật khẩu mới tối thiểu 6 ký tự.');
        }

        if (!Auth::passwordConfirm($current, $user))
        {
            response()->setStatusCode(422)->setApiStatus(422)->error('Mật khẩu hiện tại không đúng.');
        }

        $user->changePassword($new);

        response()->success('Đổi mật khẩu thành công.');
    }

    /**
     * Các chức vụ KHÔNG được "đăng nhập vào" (impersonate). Role trong hệ thống là động
     * (tạo ở màn Phân quyền) nên dùng blocklist thay vì whitelist. `administrator` là siêu
     * quản trị của app; `root` là tài khoản master framework — cả hai đều không cho mạo danh.
     * @var string[]
     */
    const LOGIN_AS_BLOCKED_ROLES = ['administrator', 'root'];

    /**
     * Tài khoản GỐC của phiên hiện tại (người thật đăng nhập), do middleware JwtLoginAs bind.
     * Khác Auth::user() — cái đó có thể đang là tài khoản mạo danh.
     */
    protected function originalUser()
    {
        if (app()->bound('original_user'))
        {
            return app()->make('original_user');
        }

        return Auth::user();
    }

    /**
     * Chặn nếu tài khoản GỐC không có quyền đăng nhập tài khoản khác (root hoặc cap login_as).
     */
    protected function authorizeLoginAs(): void
    {
        $original = $this->originalUser();

        if (hasItems($original))
        {
            $id = (int) $original->id;

            if (UserRole::hasCap($id, 'administrator') || UserRole::hasCap($id, 'root') || UserRole::hasCap($id, 'login_as'))
            {
                return;
            }
        }

        response()
            ->setStatusCode(403)
            ->setApiStatus(403)
            ->error('Bạn không có quyền đăng nhập vào tài khoản khác.');
    }

    /** Bỏ các field nhạy cảm trước khi trả user về client. */
    protected function publicUser(User $user): array
    {
        return array_filter($user->toArray(), function ($key) {
            return !in_array($key, ['password', 'salt', 'activation_key']);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Payload user trả về client (login/current): bỏ field nhạy cảm.
     * Điểm mở rộng: thêm thông tin làm giàu cho FE tại đây nếu cần.
     */
    protected function userPayload(User $user): array
    {
        return $this->publicUser($user);
    }

    /**
     * GET /api/auth/login-as/candidates — danh sách tài khoản có thể đăng nhập vào.
     * Lọc ?keyword (username/họ tên/email/phone). Loại tài khoản gốc khỏi danh sách.
     */
    public function loginAsCandidates(Request $request): void
    {
        try
        {
            $this->authorizeLoginAs();

            $original = $this->originalUser();

            $query = User::whereNotIn('role', self::LOGIN_AS_BLOCKED_ROLES)
                ->where('status', '!=', 'trash')
                ->orderBy('role')
                ->orderByDesc('id');

            if (hasItems($original))
            {
                $query->where('id', '!=', (int) $original->id);
            }

            $keyword = Str::clear($request->input('keyword'));

            if (!empty($keyword))
            {
                $query->where(function ($q) use ($keyword) {
                    $q->where('username', 'like', "%$keyword%")
                        ->orWhere('firstname', 'like', "%$keyword%")
                        ->orWhere('lastname', 'like', "%$keyword%")
                        ->orWhere('email', 'like', "%$keyword%")
                        ->orWhere('phone', 'like', "%$keyword%");
                });
            }

            $data = [];

            // Cache tên hiển thị của role (role động, tạo ở màn Phân quyền)
            $roleNames = Role::make()->getNames();

            foreach ($query->get() as $user)
            {
                // Loại tài khoản siêu quản trị (cap từ meta) — đồng nhất với check chặn ở loginAs.
                if (UserRole::hasCap((int) $user->id, 'administrator') || UserRole::hasCap((int) $user->id, 'root'))
                {
                    continue;
                }

                $firstname = (string) $user->firstname;
                $lastname  = (string) $user->lastname;

                $data[] = [
                    'id'        => (int) $user->id,
                    'username'  => $user->username,
                    'firstname' => $firstname,
                    'lastname'  => $lastname,
                    'fullname'  => trim($lastname . ' ' . $firstname) ?: $user->username,
                    'email'     => $user->email,
                    'phone'     => $user->phone,
                    'role'      => $user->role,
                    'role_name' => $roleNames[$user->role] ?? $user->role,
                    'gender'    => $user->gender,
                    'status'    => $user->status ?: 'publish',
                ];
            }

            response()->success('success', $data);
        }
        catch (\Exception $e)
        {
            error_log('[AuthController::loginAsCandidates] ' . $e->getMessage());
            response()->error('Không lấy được danh sách tài khoản.');
        }
    }

    /**
     * POST /api/auth/login-as — phát hành token cho tài khoản đích (impersonation).
     *
     * Quyền xét trên TÀI KHOẢN GỐC (Authorization header) nên dù đang mạo danh vẫn có thể
     * chuyển tiếp sang tài khoản khác. FE lưu token này vào `access_token_as` và gửi qua
     * header `loginAsToken`; tài khoản gốc (`access_token`) không đổi.
     */
    public function loginAs(Request $request): void
    {
        try
        {
            $this->authorizeLoginAs();

            $validate = $request->validate([
                'user_id' => Rule::make('Tài khoản')->notEmpty(),
            ]);

            if ($validate->fails())
            {
                response()
                    ->setStatusCode(422)
                    ->setApiStatus(422)
                    ->error($validate->errors(), $validate->errors()->errors());
            }

            $original = $this->originalUser();

            $targetId = (int) $request->input('user_id');

            if (hasItems($original) && $targetId === (int) $original->id)
            {
                response()
                    ->setStatusCode(422)
                    ->setApiStatus(422)
                    ->error('Không thể đăng nhập vào chính tài khoản của bạn.');
            }

            $target = User::find($targetId);

            if (!hasItems($target))
            {
                response()
                    ->setStatusCode(404)
                    ->setApiStatus(404)
                    ->error('Không tìm thấy tài khoản.');
            }

            // Chặn theo role VÀ theo capability: quyền siêu quản trị có thể đến từ meta user
            // (users_metadata: capabilities => {administrator:1}) chứ không chỉ từ users.role,
            // nếu chỉ check role thì user có cap login_as leo thang được lên siêu quản trị.
            if (in_array($target->role, self::LOGIN_AS_BLOCKED_ROLES, true)
                || UserRole::hasCap((int) $target->id, 'administrator')
                || UserRole::hasCap((int) $target->id, 'root'))
            {
                response()
                    ->setStatusCode(403)
                    ->setApiStatus(403)
                    ->error('Không thể đăng nhập vào tài khoản này.');
            }

            if (in_array($target->status, self::INACTIVE_STATUSES, true))
            {
                response()
                    ->setStatusCode(403)
                    ->setApiStatus(403)
                    ->error('Tài khoản đích đang bị khóa hoặc chưa kích hoạt.');
            }

            $accessToken = TokenRepository::getInstance()->create($target->id);

            if (empty($accessToken['token']))
            {
                response()
                    ->setStatusCode(500)
                    ->setApiStatus(500)
                    ->error('Không thể tạo token đăng nhập.');
            }

            response()->success('Đăng nhập tài khoản thành công', [
                'tokenType'   => 'Bearer',
                'accessToken' => $accessToken['token'],
                'expires'     => $accessToken['expires_at'],
                'data' => [
                    'user'        => $this->publicUser($target),
                    'permissions' => UserRole::getCap((int) $target->id),
                ],
            ]);
        }
        catch (\Exception $e)
        {
            error_log('[AuthController::loginAs] ' . $e->getMessage());
            response()->error('Đăng nhập tài khoản thất bại.');
        }
    }

    /**
     * POST /api/auth/login-as/exit — thoát mạo danh: REVOKE token của tài khoản đích.
     * Nếu chỉ bỏ header loginAsToken phía FE thì token mạo danh vẫn sống tới hết TTL —
     * ai lấy được token đó vẫn dùng được. Gọi endpoint này trước khi FE xoá token.
     */
    public function loginAsExit(Request $request): void
    {
        try
        {
            $loginAsToken = (string) $request->header('loginAsToken', '');

            if ($loginAsToken !== '' && $loginAsToken !== 'null' && $loginAsToken !== 'undefined')
            {
                TokenRepository::getInstance()->revoke($loginAsToken);
            }

            response()->success('Đã thoát khỏi tài khoản.');
        }
        catch (\Exception $e)
        {
            error_log('[AuthController::loginAsExit] ' . $e->getMessage());
            response()->error('Thoát tài khoản thất bại.');
        }
    }
}
