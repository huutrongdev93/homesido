<?php

/*
|--------------------------------------------------------------------------
| CMS Config Override
|--------------------------------------------------------------------------
|
| File này chỉ chứa các giá trị muốn OVERRIDE so với defaults đã được
| khai báo trong packages/skilldo/cms/src/config/cms.php
|
| Hệ thống sẽ merge deep theo thứ tự: framework → cms → app (file này)
|
*/

return [
    'admin' => [
        'use'  => false,
    ],
    'plugins' => [
        'use'     => false,
    ],
    'theme' => [
        'use'     => false,
    ],
];
