<?php
/**
 * 网易云音乐API服务主程序 (PHP版)
 *
 * 提供网易云音乐相关API服务:
 * - 健康检查
 * - 歌曲信息获取 (URL/详情/歌词/完整JSON)
 * - 音乐搜索
 * - 歌单和专辑详情
 * - 音乐下载 (文件/JSON)
 * - 二维码登录
 *
 * 用法:
 *   php -S 0.0.0.0:5000 index.php    # PHP内置服务器
 *   或配置Apache/Nginx将请求转发到 index.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/music_api.php';
require_once __DIR__ . '/music_downloader.php';

// ==================== 工具函数 ====================

/**
 * API响应工具类
 */
class APIResponse
{
    /**
     * 成功响应
     */
    public static function success($data = null, string $message = 'success', int $statusCode = 200): void
    {
        $response = [
            'status' => $statusCode,
            'success' => true,
            'message' => $message,
        ];
        if ($data !== null) {
            $response['data'] = $data;
        }
        self::sendJson($response, $statusCode);
    }

    /**
     * 错误响应
     */
    public static function error(string $message, int $statusCode = 400, ?string $errorCode = null): void
    {
        $response = [
            'status' => $statusCode,
            'success' => false,
            'message' => $message,
        ];
        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }
        self::sendJson($response, $statusCode);
    }

    /**
     * 发送JSON响应
     */
    public static function sendJson(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/**
 * 服务工具类
 */
class MusicAPIService
{
    /** @var MusicAPI */
    private $api;

    /** @var MusicDownloader */
    private $downloader;

    public function __construct()
    {
        $this->api = new MusicAPI();
        $this->downloader = new MusicDownloader();
    }

    /**
     * 获取cookies
     */
    public function getCookies(): array
    {
        $cookies = MusicAPI::loadCookies();
        if (empty($cookies)) {
            error_log("cookie.txt 为空或不存在，部分接口(VIP音质)将不可用");
        }
        return $cookies;
    }

    /**
     * 提取音乐ID (支持短链接和网易云链接)
     */
    public function extractMusicId(string $idOrUrl): string
    {
        // 处理短链接 163cn.tv
        if (strpos($idOrUrl, '163cn.tv') !== false) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $idOrUrl,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HEADER => true,
            ]);
            curl_exec($ch);
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            if ($redirectUrl) {
                $idOrUrl = $redirectUrl;
            }
        }

        // 处理网易云链接
        if (strpos($idOrUrl, 'music.163.com') !== false) {
            $index = strpos($idOrUrl, 'id=');
            if ($index !== false) {
                $rest = substr($idOrUrl, $index + 3);
                $endPos = strpos($rest, '&');
                return $endPos !== false ? substr($rest, 0, $endPos) : $rest;
            }
        }

        // 直接返回ID
        return trim($idOrUrl);
    }

    /**
     * 格式化文件大小
     */
    public function formatFileSize(int $sizeBytes): string
    {
        if ($sizeBytes == 0) return '0B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float)$sizeBytes;
        $unitIndex = 0;

        while ($size >= 1024.0 && $unitIndex < count($units) - 1) {
            $size /= 1024.0;
            $unitIndex++;
        }

        return sprintf('%.2f%s', $size, $units[$unitIndex]);
    }

    /**
     * 获取音质显示名称
     */
    public function getQualityDisplayName(string $quality): string
    {
        return QUALITY_NAMES[$quality] ?? "未知音质({$quality})";
    }

    /**
     * 安全获取请求数据 (GET/POST/JSON合并)
     */
    public function getRequestData(): array
    {
        $data = [];

        // GET参数
        if ($_GET) {
            $data = array_merge($data, $_GET);
        }

        // POST表单数据
        if ($_POST) {
            $data = array_merge($data, $_POST);
        }

        // JSON数据
        $rawInput = file_get_contents('php://input');
        if ($rawInput) {
            $jsonData = json_decode($rawInput, true);
            if (is_array($jsonData)) {
                $data = array_merge($data, $jsonData);
            }
        }

        return $data;
    }

    /**
     * 验证请求参数
     *
     * @return string|null 错误消息，null表示验证通过
     */
    public function validateParams(array $params): ?string
    {
        foreach ($params as $paramName => $paramValue) {
            if (empty($paramValue)) {
                return "参数 '{$paramName}' 不能为空";
            }
        }
        return null;
    }

    public function getApi(): MusicAPI
    {
        return $this->api;
    }

    public function getDownloader(): MusicDownloader
    {
        return $this->downloader;
    }
}

// ==================== 路由处理 ====================

/**
 * 设置CORS头
 */
function setCorsHeaders(): void
{
    header('Access-Control-Allow-Origin: ' . CORS_ORIGINS);
    header('Access-Control-Allow-Headers: Content-Type,Authorization');
    header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
    header('Access-Control-Max-Age: 3600');
}

// 处理OPTIONS预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    setCorsHeaders();
    http_response_code(204);
    exit;
}

// 设置CORS头
setCorsHeaders();

// 记录请求日志
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestPath = trim($requestPath, '/');
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
error_log("{$requestMethod} /{$requestPath} - IP: {$clientIp} - User-Agent: {$userAgent}");

// 禁止访问敏感文件 (config.php / cookie.txt / .env / .htaccess 等)
// 无论 Apache 还是 PHP 内置服务器都生效
$forbiddenPatterns = [
    'cookie\.txt',
    'config\.php',
    '\.env',
    '\.htaccess',
    '\.htpasswd',
    'music_api\.php',
    'music_downloader\.php',
    'qr_login\.php',
];
$forbiddenRegex = '#^(?:' . implode('|', $forbiddenPatterns) . ')$#i';
if (preg_match($forbiddenRegex, $requestPath)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 403,
        'success' => false,
        'message' => '禁止访问敏感文件',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// PHP内置服务器: 静态文件直接返回
if (PHP_SAPI === 'cli-server') {
    $filePath = __DIR__ . '/' . $requestPath;
    if ($requestPath !== '' && is_file($filePath)) {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        if ($ext !== 'php') {
            return false; // 让内置服务器处理静态文件
        }
    }
}

// 创建服务实例
$service = new MusicAPIService();

// 路由 (不区分大小写)
$route = strtolower($requestPath);

try {
    switch ($route) {
        // ==================== 首页 ====================
        case '':
        case 'index':
        case 'index.php':
            serveIndexPage();
            break;

        // ==================== 健康检查 ====================
        case 'health':
            $cookies = MusicAPI::loadCookies();
            $cookieStatus = !empty($cookies) ? 'valid' : 'invalid';
            APIResponse::success([
                'service' => 'running',
                'timestamp' => time(),
                'cookie_status' => $cookieStatus,
                'cookie_count' => count($cookies),
                'downloads_dir' => realpath(DOWNLOADS_DIR) ?: DOWNLOADS_DIR,
                'version' => '2.0.0-php',
            ], 'API服务运行正常');
            break;

        // ==================== API信息 ====================
        case 'api/info':
        case 'api-info':
            APIResponse::success([
                'name' => '网易云音乐API服务 (PHP)',
                'version' => '2.0.0-php',
                'description' => '提供网易云音乐相关API服务',
                'endpoints' => [
                    '/health' => 'GET - 健康检查',
                    '/song' => 'GET/POST - 获取歌曲信息',
                    '/search' => 'GET/POST - 搜索音乐',
                    '/playlist' => 'GET/POST - 获取歌单详情',
                    '/album' => 'GET/POST - 获取专辑详情',
                    '/download' => 'GET/POST - 下载音乐',
                    '/qr/key' => 'GET - 生成二维码key',
                    '/qr/status' => 'GET - 检查二维码登录状态',
                    '/api/info' => 'GET - API信息',
                ],
                'supported_qualities' => QUALITY_LEVELS,
                'config' => [
                    'downloads_dir' => realpath(DOWNLOADS_DIR) ?: DOWNLOADS_DIR,
                    'max_file_size' => (MAX_FILE_SIZE / (1024 * 1024)) . 'MB',
                ],
            ], 'API信息获取成功');
            break;

        // ==================== 歌曲信息 ====================
        case 'song':
        case 'song_v1':
            handleGetSongInfo($service);
            break;

        // ==================== 搜索音乐 ====================
        case 'search':
        case 'searchapi':
            handleSearchMusic($service);
            break;

        // ==================== 歌单详情 ====================
        case 'playlist':
            handleGetPlaylist($service);
            break;

        // ==================== 专辑详情 ====================
        case 'album':
            handleGetAlbum($service);
            break;

        // ==================== 下载音乐 ====================
        case 'download':
            handleDownloadMusic($service);
            break;

        // ==================== 二维码登录 ====================
        case 'qr/key':
        case 'qr-key':
            handleQrKey($service);
            break;

        case 'qr/status':
        case 'qr-status':
            handleQrStatus($service);
            break;

        // ==================== 404 ====================
        default:
            // 尝试作为静态文件
            if ($requestPath !== '' && is_file(__DIR__ . '/' . $requestPath)) {
                return false;
            }
            APIResponse::error("请求的资源不存在: /{$requestPath}", 404);
            break;
    }

} catch (APIException $e) {
    error_log("API调用失败: " . $e->getMessage());
    APIResponse::error("API调用失败: " . $e->getMessage(), 500);

} catch (DownloadException $e) {
    error_log("下载失败: " . $e->getMessage());
    APIResponse::error("下载失败: " . $e->getMessage(), 500);

} catch (Exception $e) {
    error_log("服务器错误: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    APIResponse::error("服务器错误: " . $e->getMessage(), 500);
}


// ==================== 路由处理函数 ====================

/**
 * 提供首页HTML
 */
function serveIndexPage(): void
{
    $templateFile = TEMPLATES_DIR . '/index.html';
    if (file_exists($templateFile)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($templateFile);
    } else {
        echo '<h1>网易云音乐API服务</h1><p>模板文件 templates/index.html 不存在</p>';
    }
    exit;
}

/**
 * 处理获取歌曲信息
 * 支持 type: url / name / lyric / json
 */
function handleGetSongInfo(MusicAPIService $service): void
{
    $data = $service->getRequestData();
    $songIds = $data['ids'] ?? $data['id'] ?? null;
    $url = $data['url'] ?? null;
    $level = $data['level'] ?? 'lossless';
    $infoType = $data['type'] ?? 'url';

    // 参数验证
    if (!$songIds && !$url) {
        APIResponse::error("必须提供 'ids'、'id' 或 'url' 参数");
    }

    // 提取音乐ID
    $musicId = $service->extractMusicId($songIds ?: $url);

    // 验证音质参数
    if (!in_array($level, QUALITY_LEVELS)) {
        APIResponse::error("无效的音质参数，支持: " . implode(', ', QUALITY_LEVELS));
    }

    // 验证类型参数
    $validTypes = ['url', 'name', 'lyric', 'json'];
    if (!in_array($infoType, $validTypes)) {
        APIResponse::error("无效的类型参数，支持: " . implode(', ', $validTypes));
    }

    $cookies = $service->getCookies();
    $api = $service->getApi();

    switch ($infoType) {
        case 'url':
            $result = $api->getSongUrl($musicId, $level, $cookies);
            if (!empty($result['data'])) {
                $songData = $result['data'][0];
                APIResponse::success([
                    'id' => $songData['id'] ?? null,
                    'url' => $songData['url'] ?? null,
                    'level' => $songData['level'] ?? $level,
                    'quality_name' => $service->getQualityDisplayName($songData['level'] ?? $level),
                    'size' => $songData['size'] ?? 0,
                    'size_formatted' => $service->formatFileSize($songData['size'] ?? 0),
                    'type' => $songData['type'] ?? null,
                    'bitrate' => $songData['br'] ?? null,
                ], '获取歌曲URL成功');
            } else {
                APIResponse::error("获取音乐URL失败，可能是版权限制或音质不支持", 404);
            }
            break;

        case 'name':
            $result = $api->getSongDetail($musicId);
            APIResponse::success($result, '获取歌曲信息成功');
            break;

        case 'lyric':
            $result = $api->getLyric($musicId, $cookies);
            APIResponse::success($result, '获取歌词成功');
            break;

        case 'json':
            // 获取完整的歌曲信息
            $songInfo = $api->getSongDetail($musicId);
            $urlInfo = $api->getSongUrl($musicId, $level, $cookies);
            $lyricInfo = null;
            try {
                $lyricInfo = $api->getLyric($musicId, $cookies);
            } catch (Exception $e) {
                // 歌词获取失败不阻断
            }

            if (empty($songInfo['songs'])) {
                APIResponse::error('未找到歌曲信息', 404);
            }

            $songData = $songInfo['songs'][0];

            // 构建响应
            $responseData = [
                'id' => $musicId,
                'name' => $songData['name'] ?? '',
                'ar_name' => implode(', ', array_map(function($a) {
                    return $a['name'];
                }, $songData['ar'] ?? [])),
                'al_name' => $songData['al']['name'] ?? '',
                'pic' => $songData['al']['picUrl'] ?? '',
                'level' => $level,
                'lyric' => $lyricInfo['lrc']['lyric'] ?? '',
                'tlyric' => $lyricInfo['tlyric']['lyric'] ?? '',
            ];

            // 添加URL和大小信息
            if (!empty($urlInfo['data'])) {
                $urlData = $urlInfo['data'][0];
                $responseData['url'] = $urlData['url'] ?? '';
                $responseData['size'] = $service->formatFileSize($urlData['size'] ?? 0);
                $responseData['level'] = $urlData['level'] ?? $level;
                $responseData['type'] = $urlData['type'] ?? null;
            } else {
                $responseData['url'] = '';
                $responseData['size'] = '获取失败';
            }

            APIResponse::success($responseData, '获取歌曲信息成功');
            break;
    }
}

/**
 * 处理搜索音乐
 */
function handleSearchMusic(MusicAPIService $service): void
{
    $data = $service->getRequestData();
    $keyword = $data['keyword'] ?? $data['keywords'] ?? $data['q'] ?? null;
    $limit = isset($data['limit']) ? (int)$data['limit'] : 30;

    // 参数验证
    $error = $service->validateParams(['keyword' => $keyword]);
    if ($error) {
        APIResponse::error($error);
    }

    // 限制搜索数量
    if ($limit > 100) {
        $limit = 100;
    }

    $cookies = $service->getCookies();
    $result = $service->getApi()->searchMusic($keyword, $cookies, $limit);

    APIResponse::success($result, '搜索完成');
}

/**
 * 处理获取歌单详情
 */
function handleGetPlaylist(MusicAPIService $service): void
{
    $data = $service->getRequestData();
    $playlistId = $data['id'] ?? null;

    // 参数验证
    $error = $service->validateParams(['playlist_id' => $playlistId]);
    if ($error) {
        APIResponse::error($error);
    }

    $cookies = $service->getCookies();
    $result = $service->getApi()->getPlaylistDetail($playlistId, $cookies);

    APIResponse::success([
        'status' => 'success',
        'playlist' => $result,
    ], '获取歌单详情成功');
}

/**
 * 处理获取专辑详情
 */
function handleGetAlbum(MusicAPIService $service): void
{
    $data = $service->getRequestData();
    $albumId = $data['id'] ?? null;

    // 参数验证
    $error = $service->validateParams(['album_id' => $albumId]);
    if ($error) {
        APIResponse::error($error);
    }

    $cookies = $service->getCookies();
    $result = $service->getApi()->getAlbumDetail($albumId, $cookies);

    APIResponse::success([
        'status' => 200,
        'album' => $result,
    ], '获取专辑详情成功');
}

/**
 * 处理下载音乐
 */
function handleDownloadMusic(MusicAPIService $service): void
{
    $data = $service->getRequestData();
    $musicId = $data['id'] ?? null;
    $quality = $data['quality'] ?? 'lossless';
    $returnFormat = $data['format'] ?? 'file';

    // 参数验证
    $error = $service->validateParams(['music_id' => $musicId]);
    if ($error) {
        APIResponse::error($error);
    }

    // 验证音质参数
    if (!in_array($quality, QUALITY_LEVELS)) {
        APIResponse::error("无效的音质参数，支持: " . implode(', ', QUALITY_LEVELS));
    }

    // 验证返回格式
    if (!in_array($returnFormat, ['file', 'json'])) {
        APIResponse::error("返回格式只支持 'file' 或 'json'");
    }

    // 提取音乐ID
    $musicId = $service->extractMusicId($musicId);
    $cookies = $service->getCookies();
    $api = $service->getApi();

    // 获取音乐基本信息
    $songInfo = $api->getSongDetail($musicId);
    if (empty($songInfo['songs'])) {
        APIResponse::error('未找到音乐信息', 404);
    }

    // 获取音乐下载链接
    $urlInfo = $api->getSongUrl($musicId, $quality, $cookies);
    if (empty($urlInfo['data']) || empty($urlInfo['data'][0]['url'])) {
        APIResponse::error('无法获取音乐下载链接，可能是版权限制或音质不支持', 404);
    }

    // 构建音乐信息
    $songData = $songInfo['songs'][0];
    $urlData = $urlInfo['data'][0];

    $musicInfo = [
        'id' => $musicId,
        'name' => $songData['name'],
        'artist_string' => implode(', ', array_map(function($a) {
            return $a['name'];
        }, $songData['ar'] ?? [])),
        'album' => $songData['al']['name'] ?? '',
        'pic_url' => $songData['al']['picUrl'] ?? '',
        'file_type' => $urlData['type'] ?? 'mp3',
        'file_size' => $urlData['size'] ?? 0,
        'duration' => $songData['dt'] ?? 0,
        'download_url' => $urlData['url'],
    ];

    // 生成安全文件名
    $safeName = $musicInfo['name'] . ' [' . $quality . ']';
    $safeName = preg_replace('/[<>:"\/\\\\|?*]/', '_', $safeName);
    $filename = $safeName . '.' . $musicInfo['file_type'];
    $filePath = DOWNLOADS_DIR . DIRECTORY_SEPARATOR . $filename;

    // 检查文件是否已存在
    if (!file_exists($filePath)) {
        // 使用下载器下载
        $downloadResult = $service->getDownloader()->downloadMusicFile($musicId, $quality);
        if (!$downloadResult->success) {
            APIResponse::error("下载失败: " . $downloadResult->errorMessage, 500);
        }
        $filePath = $downloadResult->filePath;
        error_log("下载完成: {$filename}");
    } else {
        error_log("文件已存在: {$filename}");
    }

    // 根据返回格式返回结果
    if ($returnFormat === 'json') {
        APIResponse::success([
            'music_id' => $musicId,
            'name' => $musicInfo['name'],
            'artist' => $musicInfo['artist_string'],
            'album' => $musicInfo['album'],
            'quality' => $quality,
            'quality_name' => $service->getQualityDisplayName($quality),
            'file_type' => $musicInfo['file_type'],
            'file_size' => $musicInfo['file_size'],
            'file_size_formatted' => $service->formatFileSize($musicInfo['file_size']),
            'file_path' => realpath($filePath),
            'filename' => $filename,
            'duration' => $musicInfo['duration'],
        ], '下载完成');

    } else {
        // 返回文件下载
        if (!file_exists($filePath)) {
            APIResponse::error('文件不存在', 404);
        }

        // 设置下载头
        $encodedFilename = rawurlencode($filename);
        $mimeType = 'audio/' . $musicInfo['file_type'];

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . $encodedFilename);
        header('Content-Length: ' . filesize($filePath));
        header('X-Download-Message: Download completed successfully');
        header('X-Download-Filename: ' . $encodedFilename);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // 输出文件
        readfile($filePath);
        exit;
    }
}

/**
 * 处理生成二维码key
 */
function handleQrKey(MusicAPIService $service): void
{
    $api = $service->getApi();
    $result = $api->createQrLogin();

    APIResponse::success([
        'unikey' => $result['unikey'],
        'qr_url' => $result['qr_url'],
        'expire_time' => 180, // 3分钟
    ], '二维码生成成功');
}

/**
 * 处理检查二维码登录状态
 */
function handleQrStatus(MusicAPIService $service): void
{
    $data = $service->getRequestData();
    $unikey = $data['key'] ?? $data['unikey'] ?? null;

    if (!$unikey) {
        APIResponse::error("必须提供 'key' 参数");
    }

    $api = $service->getApi();
    $result = $api->checkQrLogin($unikey);

    // 如果登录成功，保存cookie
    if ($result['code'] == 803 && !empty($result['cookie'])) {
        file_put_contents(COOKIE_FILE, $result['cookie'] . "\n");
    }

    APIResponse::success([
        'code' => $result['code'],
        'message' => $result['message'],
        'cookie' => $result['code'] == 803 ? $result['cookie'] : '',
        'status' => $result['code'] == 803 ? 'success' : ($result['code'] == 801 ? 'waiting' : ($result['code'] == 802 ? 'scanned' : 'expired')),
    ], $result['message']);
}
