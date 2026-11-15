<?php

namespace HSKN\Rest;

defined('ABSPATH') || exit;

/**
 * Đăng nhập / đăng ký ngay trên trang nghe (không chuyển sang wp-login.php).
 *   POST auth/login     {login, password, remember}
 *   POST auth/register  {name, email, password}   chỉ khi Cài đặt → Tổng quan → "Ai cũng có thể đăng ký" đang bật
 *
 * "Ghi nhớ" = cookie đăng nhập giữ 1 năm (lọc auth_cookie_expiration, áp dụng cho cả wp-login.php khi tích "Ghi nhớ").
 * wp_signon() vẫn chạy các hook authenticate / wp_login_failed nên plugin chống dò mật khẩu vẫn hoạt động.
 */
final class AuthController
{
    public static function rememberYear(): void
    {
        add_filter('auth_cookie_expiration', static fn($len, $user, $remember) => $remember ? YEAR_IN_SECONDS : $len, 10, 3);
    }

    public function register(): void
    {
        register_rest_route('hskn/v1', '/auth/login', ['methods' => 'POST', 'callback' => [$this, 'login'], 'permission_callback' => '__return_true']);
        register_rest_route('hskn/v1', '/auth/register', ['methods' => 'POST', 'callback' => [$this, 'signup'], 'permission_callback' => '__return_true']);
    }

    public function login(\WP_REST_Request $req)
    {
        $login = trim((string) $req['login']);
        $pass = (string) $req['password'];
        if ($login === '' || $pass === '') {
            return new \WP_Error('hskn_auth', 'Nhập email / tên đăng nhập và mật khẩu.', ['status' => 400]);
        }
        $user = wp_signon(['user_login' => $login, 'user_password' => $pass, 'remember' => $req['remember'] !== false], is_ssl());
        if (is_wp_error($user)) {
            $msg = in_array($user->get_error_code(), ['invalid_username', 'invalid_email', 'incorrect_password'], true)
                ? 'Sai email / tên đăng nhập hoặc mật khẩu.'
                : wp_strip_all_tags($user->get_error_message());
            return new \WP_Error('hskn_auth', $msg, ['status' => 403]);
        }
        return new \WP_REST_Response(['ok' => true, 'name' => $user->display_name]);
    }

    public function signup(\WP_REST_Request $req)
    {
        if (!get_option('users_can_register')) {
            return new \WP_Error('hskn_auth', 'Trang này chưa mở đăng ký tài khoản.', ['status' => 403]);
        }
        if ((string) $req['website'] !== '') {   // bẫy bot: ô ẩn, người thật không điền
            return new \WP_Error('hskn_auth', 'Không đăng ký được.', ['status' => 400]);
        }
        $ipKey = 'hskn_reg_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ((int) get_transient($ipKey) >= 5) {
            return new \WP_Error('hskn_auth', 'Đăng ký quá nhiều lần, thử lại sau.', ['status' => 429]);
        }
        $email = sanitize_email((string) $req['email']);
        $name = mb_substr(sanitize_text_field((string) $req['name']), 0, 60);
        $pass = (string) $req['password'];
        if (!is_email($email)) {
            return new \WP_Error('hskn_auth', 'Email không hợp lệ.', ['status' => 400]);
        }
        if (email_exists($email)) {
            return new \WP_Error('hskn_auth', 'Email này đã có tài khoản — hãy đăng nhập.', ['status' => 400]);
        }
        if (mb_strlen($pass) < 8) {
            return new \WP_Error('hskn_auth', 'Mật khẩu cần ít nhất 8 ký tự.', ['status' => 400]);
        }
        $base = sanitize_user(strstr($email, '@', true), true) ?: 'hocvien';
        $login = $base;
        for ($i = 2; username_exists($login); $i++) {
            $login = $base . $i;
        }
        $id = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $pass,
            'display_name' => $name ?: $base,
            'nickname'     => $name ?: $base,
            'role'         => get_option('default_role', 'subscriber'),
        ]);
        if (is_wp_error($id)) {
            return new \WP_Error('hskn_auth', wp_strip_all_tags($id->get_error_message()), ['status' => 400]);
        }
        set_transient($ipKey, (int) get_transient($ipKey) + 1, HOUR_IN_SECONDS);
        do_action('register_new_user', $id);   // email thông báo cho admin như đăng ký thường
        wp_signon(['user_login' => $login, 'user_password' => $pass, 'remember' => true], is_ssl());
        return new \WP_REST_Response(['ok' => true]);
    }
}
