<?php
/**
 * 网易云音乐API配置文件
 */

// 服务配置
define('NETEASE_HOST', '0.0.0.0');
define('NETEASE_PORT', 5000);
define('NETEASE_DEBUG', false);

// 路径配置
define('DOWNLOADS_DIR', __DIR__ . '/downloads');
define('COOKIE_FILE', __DIR__ . '/cookie.txt');
define('TEMPLATES_DIR', __DIR__ . '/templates');

// 文件大小限制 (500MB)
define('MAX_FILE_SIZE', 500 * 1024 * 1024);

// CORS 配置
define('CORS_ORIGINS', '*');

// AES 加密密钥
define('AES_KEY', 'e82ckenh8dichen8');

// HTTP User-Agent
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Safari/537.36 Chrome/91.0.4472.164 NeteaseMusicDesktop/2.10.2.200154');
define('REFERER', 'https://music.163.com/');

// API URL 定义
define('SONG_URL_V1_API', 'https://interface3.music.163.com/eapi/song/enhance/player/url/v1');
define('SONG_DETAIL_V3_API', 'https://interface3.music.163.com/api/v3/song/detail');
define('LYRIC_API', 'https://interface3.music.163.com/api/song/lyric');
define('SEARCH_API', 'https://music.163.com/api/cloudsearch/pc');
define('PLAYLIST_DETAIL_API', 'https://music.163.com/api/v6/playlist/detail');
define('ALBUM_DETAIL_API', 'https://music.163.com/api/v1/album/');
define('QR_UNIKEY_API', 'https://interface3.music.163.com/eapi/login/qrcode/unikey');
define('QR_LOGIN_API', 'https://interface3.music.163.com/eapi/login/qrcode/client/login');

// 默认配置
define('DEFAULT_CONFIG', [
    'os' => 'pc',
    'appver' => '',
    'osver' => '',
    'deviceId' => 'pyncm!'
]);

define('DEFAULT_COOKIES', [
    'os' => 'pc',
    'appver' => '',
    'osver' => '',
    'deviceId' => 'pyncm!'
]);

// 支持的音质等级
define('QUALITY_LEVELS', [
    'standard',   // 标准音质
    'exhigh',     // 极高音质
    'lossless',   // 无损音质
    'hires',      // Hi-Res音质
    'sky',        // 沉浸环绕声
    'jyeffect',   // 高清环绕声
    'jymaster',   // 超清母带
    'dolby',      // 杜比全景声
]);

// 音质显示名称
define('QUALITY_NAMES', [
    'standard' => '标准音质',
    'exhigh' => '极高音质',
    'lossless' => '无损音质',
    'hires' => 'Hi-Res音质',
    'sky' => '沉浸环绕声',
    'jyeffect' => '高清环绕声',
    'jymaster' => '超清母带',
    'dolby' => '杜比全景声',
]);

// ffmpeg 路径 (可选，用于写入元数据；不可用时自动降级到 getID3 纯 PHP 库)
define('FFMPEG_PATH', getenv('FFMPEG_PATH') ?: null);
