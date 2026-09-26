<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

// GD 检查必须放在 issueCaptcha() 之前：否则验证码已写入会话，用户却因 500 看不到答案
if (!function_exists('imagecreatetruecolor')) {
    http_response_code(500);
    exit('服务器未启用 GD 扩展，无法生成验证码。');
}

$code = issueCaptcha();

// 图片输出前释放会话锁，避免同一浏览器的其它请求被阻塞
session_write_close();

$width = 120;
$height = 40;
$image = imagecreatetruecolor($width, $height);
imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 243, 244, 248));

$palette = [
    imagecolorallocate($image, 79, 70, 229),
    imagecolorallocate($image, 15, 118, 110),
    imagecolorallocate($image, 180, 83, 9),
];

for ($index = 0; $index < 4; $index++) {
    imageline(
        $image,
        random_int(0, $width),
        random_int(0, $height),
        random_int(0, $width),
        random_int(0, $height),
        $palette[$index % count($palette)]
    );
}
for ($index = 0; $index < 80; $index++) {
    imagesetpixel(
        $image,
        random_int(0, $width - 1),
        random_int(0, $height - 1),
        $palette[random_int(0, count($palette) - 1)]
    );
}

$font = 5;
$characterHeight = imagefontheight($font);
$step = (int) (($width - 20) / max(1, strlen($code)));
foreach (str_split($code) as $index => $character) {
    imagestring(
        $image,
        $font,
        10 + $index * $step + random_int(-2, 2),
        (int) (($height - $characterHeight) / 2) + random_int(-4, 4),
        $character,
        $palette[$index % count($palette)]
    );
}

header_remove('Cache-Control');
header('Content-Type: image/png');
header('Cache-Control: no-store, max-age=0');
imagepng($image);