<?php
include_once( __DIR__ . '/bootstrap/autoload.php' );

/*
|--------------------------------------------------------------------------
| Restore Authorization header bị Apache strip khỏi $_SERVER
|--------------------------------------------------------------------------
| Apache (mod_php / CGI) không tự chuyển Authorization header vào $_SERVER.
| Symfony Request xây HeaderBag từ $_SERVER['HTTP_*'] nên bị mất header này.
| Fix: bơm lại từ getallheaders() hoặc REDIRECT_HTTP_AUTHORIZATION.
*/
if (!isset($_SERVER['HTTP_AUTHORIZATION']))
{
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
    {
        // PHP-FPM / CGI qua mod_rewrite đặt vào REDIRECT_*
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    elseif (function_exists('getallheaders'))
    {
        $allHeaders = getallheaders();

        // getallheaders() trả về key case-insensitive tuỳ server
        foreach ($allHeaders as $name => $value)
        {
            if (strtolower($name) === 'authorization')
            {
                $_SERVER['HTTP_AUTHORIZATION'] = $value;
                break;
            }
        }
    }
}
/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
 */
(require_once __DIR__ . '/bootstrap/app.php')->handleRequest(new \SkillDo\Http\Request($_GET, $_POST, [], $_COOKIE, $_FILES, $_SERVER));