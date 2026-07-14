<?php
/**
 * 网易云音乐API模块
 *
 * 提供网易云音乐相关API接口的封装，包括：
 * - 音乐URL获取 (EAPI加密)
 * - 歌曲详情获取
 * - 歌词获取
 * - 搜索功能
 * - 歌单和专辑详情
 * - 二维码登录
 * - 图片ID加密
 * - Cookie管理
 */

require_once __DIR__ . '/config.php';

/**
 * API异常类
 */
class APIException extends Exception {}

/**
 * 网易云音乐API主类
 */
class MusicAPI
{
    /**
     * 将字节数据转换为十六进制字符串 (小写)
     */
    public static function hexDigest(string $data): string
    {
        return bin2hex($data);
    }

    /**
     * 计算MD5哈希值 (返回十六进制字符串)
     */
    public static function hashHexDigest(string $text): string
    {
        return md5($text);
    }

    /**
     * 加密请求参数 (EAPI加密)
     *
     * 算法:
     * 1. 提取URL路径，将 /eapi/ 替换为 /api/
     * 2. 计算摘要: MD5("nobody{urlPath}use{jsonPayload}md5forencrypt")
     * 3. 构建参数: "{urlPath}-36cd479b6b5-{jsonPayload}-36cd479b6b5-{digest}"
     * 4. AES-128-ECB 加密 (PKCS7填充)
     * 5. 返回十六进制字符串
     */
    public static function encryptParams(string $url, array $payload): string
    {
        $urlPath = parse_url($url, PHP_URL_PATH);
        $urlPath = str_replace('/eapi/', '/api/', $urlPath);

        $jsonPayload = self::jsonEncode($payload);
        $digest = self::hashHexDigest("nobody{$urlPath}use{$jsonPayload}md5forencrypt");
        $params = "{$urlPath}-36cd479b6b5-{$jsonPayload}-36cd479b6b5-{$digest}";

        // AES-128-ECB 加密 (PHP的openssl自动处理PKCS7填充)
        $encrypted = openssl_encrypt($params, 'AES-128-ECB', AES_KEY, OPENSSL_RAW_DATA);
        if ($encrypted === false) {
            throw new APIException('AES加密失败: ' . openssl_error_string());
        }

        return self::hexDigest($encrypted);
    }

    /**
     * 将数组编码为JSON字符串
     * 匹配Python json.dumps默认格式 (带空格的分隔符)
     */
    private static function jsonEncode(array $data): string
    {
        return self::jsonEncodeInternal($data);
    }

    /**
     * 递归JSON编码 (匹配Python json.dumps格式: ", " 和 ": " 分隔符)
     */
    private static function jsonEncodeInternal($data): string
    {
        if (is_array($data)) {
            $isList = array_is_list($data);
            $parts = [];
            if ($isList) {
                foreach ($data as $item) {
                    $parts[] = self::jsonEncodeInternal($item);
                }
                return '[' . implode(', ', $parts) . ']';
            } else {
                foreach ($data as $key => $value) {
                    $parts[] = '"' . $key . '": ' . self::jsonEncodeInternal($value);
                }
                return '{' . implode(', ', $parts) . '}';
            }
        } elseif (is_string($data)) {
            return '"' . addslashes($data) . '"';
        } elseif (is_int($data) || is_float($data)) {
            return (string)$data;
        } elseif (is_bool($data)) {
            return $data ? 'true' : 'false';
        } elseif (is_null($data)) {
            return 'null';
        }
        return 'null';
    }

    /**
     * 发送POST请求
     *
     * @param string $url API地址
     * @param string $params 加密后的参数
     * @param array $cookies 用户cookies
     * @param bool $returnHeaders 是否返回完整响应(包含headers)
     * @return string|array 响应文本或 [body, headers]
     */
    public static function postRequest(string $url, string $params, array $cookies = [], bool $returnHeaders = false)
    {
        $cookieStr = self::buildCookieString($cookies);
        $postData = 'params=' . $params;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . USER_AGENT,
                'Referer: ' . REFERER,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_COOKIE => $cookieStr,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => $returnHeaders,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($returnHeaders) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            curl_close($ch);

            if ($httpCode >= 400) {
                throw new APIException("HTTP请求失败: HTTP {$httpCode}");
            }
            return ['body' => $body, 'headers' => $headers];
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            throw new APIException("HTTP请求失败: HTTP {$httpCode}");
        }

        return $response;
    }

    /**
     * 发送GET请求
     */
    public static function getRequest(string $url, array $cookies = [], array $headers = []): string
    {
        $cookieStr = self::buildCookieString($cookies);
        $headerLines = [
            'User-Agent: ' . USER_AGENT,
            'Referer: ' . REFERER,
        ];
        foreach ($headers as $k => $v) {
            $headerLines[] = "$k: $v";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_COOKIE => $cookieStr,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new APIException("HTTP GET请求失败: HTTP {$httpCode}");
        }

        return $response;
    }

    /**
     * 构建Cookie字符串
     */
    private static function buildCookieString(array $cookies): string
    {
        $allCookies = DEFAULT_COOKIES;
        foreach ($cookies as $k => $v) {
            $allCookies[$k] = $v;
        }
        $parts = [];
        foreach ($allCookies as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        return implode('; ', $parts);
    }

    /**
     * 生成随机requestId
     */
    private static function generateRequestId(): string
    {
        return (string)rand(20000000, 30000000);
    }

    /**
     * 构建默认请求头配置
     */
    private static function buildConfig(): array
    {
        $config = DEFAULT_CONFIG;
        $config['requestId'] = self::generateRequestId();
        return $config;
    }

    // ==================== API方法 ====================

    /**
     * 获取歌曲播放URL
     *
     * @param int|string $songId 歌曲ID
     * @param string $quality 音质等级
     * @param array $cookies 用户cookies
     * @return array 包含歌曲URL信息的数组
     */
    public function getSongUrl($songId, string $quality, array $cookies = []): array
    {
        $config = self::buildConfig();

        $payload = [
            'ids' => [(int)$songId],
            'level' => $quality,
            'encodeType' => $quality === 'dolby' ? 'mp4' : 'flac',
            'header' => json_encode($config),
        ];

        if ($quality === 'sky') {
            $payload['immerseType'] = 'c51';
        }

        $params = self::encryptParams(SONG_URL_V1_API, $payload);
        $responseText = self::postRequest(SONG_URL_V1_API, $params, $cookies);

        $result = json_decode($responseText, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new APIException('解析响应数据失败: ' . json_last_error_msg());
        }

        if (($result['code'] ?? -1) != 200) {
            throw new APIException('获取歌曲URL失败: ' . ($result['message'] ?? '未知错误'));
        }

        return $result;
    }

    /**
     * 获取歌曲详细信息
     *
     * @param int|string $songId 歌曲ID
     * @return array 包含歌曲详细信息的数组
     */
    public function getSongDetail($songId): array
    {
        $data = ['c' => json_encode([['id' => (int)$songId, 'v' => 0]])];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => SONG_DETAIL_V3_API,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . USER_AGENT,
                'Referer: ' . REFERER,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new APIException("获取歌曲详情请求失败: HTTP {$httpCode}");
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new APIException('解析歌曲详情响应失败: ' . json_last_error_msg());
        }

        if (($result['code'] ?? -1) != 200) {
            throw new APIException('获取歌曲详情失败: ' . ($result['message'] ?? '未知错误'));
        }

        return $result;
    }

    /**
     * 获取歌词信息
     *
     * @param int|string $songId 歌曲ID
     * @param array $cookies 用户cookies
     * @return array 包含歌词信息的数组
     */
    public function getLyric($songId, array $cookies = []): array
    {
        $data = [
            'id' => $songId,
            'cp' => 'false',
            'tv' => '0',
            'lv' => '0',
            'rv' => '0',
            'kv' => '0',
            'yv' => '0',
            'ytv' => '0',
            'yrv' => '0',
        ];

        $cookieStr = self::buildCookieString($cookies);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => LYRIC_API,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . USER_AGENT,
                'Referer: ' . REFERER,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_COOKIE => $cookieStr,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new APIException("获取歌词请求失败: HTTP {$httpCode}");
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new APIException('解析歌词响应失败: ' . json_last_error_msg());
        }

        if (($result['code'] ?? -1) != 200) {
            throw new APIException('获取歌词失败: ' . ($result['message'] ?? '未知错误'));
        }

        return $result;
    }

    /**
     * 搜索音乐
     *
     * @param string $keywords 搜索关键词
     * @param array $cookies 用户cookies
     * @param int $limit 返回数量限制
     * @return array 歌曲信息列表
     */
    public function searchMusic(string $keywords, array $cookies = [], int $limit = 30): array
    {
        $data = [
            's' => $keywords,
            'type' => 1,
            'limit' => $limit,
        ];

        $cookieStr = self::buildCookieString($cookies);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => SEARCH_API,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . USER_AGENT,
                'Referer: ' . REFERER,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_COOKIE => $cookieStr,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new APIException("搜索请求失败: HTTP {$httpCode}");
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new APIException('解析搜索响应失败: ' . json_last_error_msg());
        }

        if (($result['code'] ?? -1) != 200) {
            throw new APIException('搜索失败: ' . ($result['message'] ?? '未知错误'));
        }

        $songs = [];
        foreach (($result['result']['songs'] ?? []) as $item) {
            $artists = [];
            foreach (($item['ar'] ?? []) as $artist) {
                $artists[] = $artist['name'];
            }
            $songs[] = [
                'id' => $item['id'],
                'name' => $item['name'] ?? '',
                'artists' => implode('/', $artists),
                'album' => $item['al']['name'] ?? '',
                'picUrl' => $item['al']['picUrl'] ?? '',
            ];
        }

        return $songs;
    }

    /**
     * 获取歌单详情
     *
     * @param int|string $playlistId 歌单ID
     * @param array $cookies 用户cookies
     * @return array 歌单详情信息
     */
    public function getPlaylistDetail($playlistId, array $cookies = []): array
    {
        $data = ['id' => $playlistId];
        $cookieStr = self::buildCookieString($cookies);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => PLAYLIST_DETAIL_API,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . USER_AGENT,
                'Referer: ' . REFERER,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_COOKIE => $cookieStr,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new APIException("获取歌单详情请求失败: HTTP {$httpCode}");
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new APIException('解析歌单详情响应失败: ' . json_last_error_msg());
        }

        if (($result['code'] ?? -1) != 200) {
            throw new APIException('获取歌单详情失败: ' . ($result['message'] ?? '未知错误'));
        }

        $playlist = $result['playlist'] ?? [];
        $info = [
            'id' => $playlist['id'] ?? null,
            'name' => $playlist['name'] ?? '',
            'coverImgUrl' => $playlist['coverImgUrl'] ?? '',
            'creator' => $playlist['creator']['nickname'] ?? '',
            'trackCount' => $playlist['trackCount'] ?? 0,
            'description' => $playlist['description'] ?? '',
            'tracks' => [],
        ];

        // 分批获取歌曲详情
        $trackIds = [];
        foreach (($playlist['trackIds'] ?? []) as $t) {
            $trackIds[] = (string)$t['id'];
        }

        $headers = [
            'User-Agent: ' . USER_AGENT,
            'Referer: ' . REFERER,
            'Content-Type: application/x-www-form-urlencoded',
        ];

        for ($i = 0; $i < count($trackIds); $i += 100) {
            $batchIds = array_slice($trackIds, $i, 100);
            $songList = [];
            foreach ($batchIds as $sid) {
                $songList[] = ['id' => (int)$sid, 'v' => 0];
            }
            $songData = ['c' => json_encode($songList)];

            $ch2 = curl_init();
            curl_setopt_array($ch2, [
                CURLOPT_URL => SONG_DETAIL_V3_API,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($songData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_COOKIE => $cookieStr,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $songResp = curl_exec($ch2);
            curl_close($ch2);

            $songResult = json_decode($songResp, true);
            foreach (($songResult['songs'] ?? []) as $song) {
                $artists = [];
                foreach (($song['ar'] ?? []) as $artist) {
                    $artists[] = $artist['name'];
                }
                $info['tracks'][] = [
                    'id' => $song['id'],
                    'name' => $song['name'] ?? '',
                    'artists' => implode('/', $artists),
                    'album' => $song['al']['name'] ?? '',
                    'picUrl' => $song['al']['picUrl'] ?? '',
                ];
            }
        }

        return $info;
    }

    /**
     * 获取网易云官方排行榜列表
     * 返回所有榜单摘要 (飙升榜、新歌榜、热歌榜、原创榜等 + 23 种特色榜)
     *
     * @param array $cookies 用户cookies
     * @return array 榜单数组
     */
    public function getToplistList(array $cookies = []): array
    {
        $url = TOPLIST_API;
        $cookieStr = self::buildCookieString($cookies);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . USER_AGENT,
                'Referer: ' . REFERER,
            ],
            CURLOPT_COOKIE => $cookieStr,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new APIException("获取官方排行榜请求失败: HTTP {$httpCode}");
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new APIException('解析排行榜响应失败: ' . json_last_error_msg());
        }

        if (($result['code'] ?? -1) != 200) {
            throw new APIException('获取官方排行榜失败: ' . ($result['message'] ?? '未知错误'));
        }

        $list = [];
        foreach (($result['list'] ?? []) as $item) {
            $list[] = [
                'id' => $item['id'] ?? 0,
                'name' => $item['name'] ?? '',
                'description' => $item['description'] ?? '',
                'coverImgUrl' => $item['coverImgUrl'] ?? '',
                'updateFrequency' => $item['updateFrequency'] ?? '',
                'trackCount' => $item['trackCount'] ?? 0,
                'playCount' => $item['playCount'] ?? 0,
            ];
        }

        return $list;
    }

    /**
     * 获取专辑详情
     *
     * @param int|string $albumId 专辑ID
     * @param array $cookies 用户cookies
     * @return array 专辑详情信息
     */
    public function getAlbumDetail($albumId, array $cookies = []): array
    {
        $url = ALBUM_DETAIL_API . $albumId;
        $cookieStr = self::buildCookieString($cookies);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . USER_AGENT,
                'Referer: ' . REFERER,
            ],
            CURLOPT_COOKIE => $cookieStr,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new APIException("获取专辑详情请求失败: HTTP {$httpCode}");
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new APIException('解析专辑详情响应失败: ' . json_last_error_msg());
        }

        if (($result['code'] ?? -1) != 200) {
            throw new APIException('获取专辑详情失败: ' . ($result['message'] ?? '未知错误'));
        }

        $album = $result['album'] ?? [];
        $info = [
            'id' => $album['id'] ?? null,
            'name' => $album['name'] ?? '',
            'coverImgUrl' => $this->getPicUrl($album['pic'] ?? null),
            'artist' => $album['artist']['name'] ?? '',
            'publishTime' => $album['publishTime'] ?? null,
            'description' => $album['description'] ?? '',
            'songs' => [],
        ];

        foreach (($result['songs'] ?? []) as $song) {
            $artists = [];
            foreach (($song['ar'] ?? []) as $artist) {
                $artists[] = $artist['name'];
            }
            $info['songs'][] = [
                'id' => $song['id'],
                'name' => $song['name'] ?? '',
                'artists' => implode('/', $artists),
                'album' => $song['al']['name'] ?? '',
                'picUrl' => $this->getPicUrl($song['al']['pic'] ?? null),
            ];
        }

        return $info;
    }

    /**
     * 网易云加密图片ID算法
     *
     * 算法: XOR with magic string -> MD5 -> base64 (URL-safe)
     *
     * @param string $idStr 图片ID字符串
     * @return string 加密后的字符串
     */
    public function neteaseEncryptId(string $idStr): string
    {
        $magic = '3go8&$8*3*3h0k(2)2';
        $magicLen = strlen($magic);
        $result = '';

        for ($i = 0; $i < strlen($idStr); $i++) {
            $result .= chr(ord($idStr[$i]) ^ ord($magic[$i % $magicLen]));
        }

        $md5 = md5($result, true); // raw binary
        $base64 = base64_encode($md5);
        // URL-safe base64
        $base64 = str_replace(['/', '+'], ['_', '-'], $base64);

        return $base64;
    }

    /**
     * 获取网易云加密歌曲/专辑封面直链
     *
     * @param int|string|null $picId 封面ID
     * @param int $size 图片尺寸
     * @return string 图片URL
     */
    public function getPicUrl($picId, int $size = 300): string
    {
        if ($picId === null || $picId === '') {
            return '';
        }

        $encId = $this->neteaseEncryptId((string)$picId);
        return "https://p3.music.126.net/{$encId}/{$picId}.jpg?param={$size}y{$size}";
    }

    // ==================== 二维码登录 ====================

    /**
     * 生成二维码的key (unikey)
     *
     * @return string unikey
     */
    public function generateQrKey(): string
    {
        $config = self::buildConfig();

        $payload = [
            'type' => 1,
            'header' => json_encode($config),
        ];

        $params = self::encryptParams(QR_UNIKEY_API, $payload);
        $response = self::postRequest(QR_UNIKEY_API, $params, []);

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new APIException('解析二维码key响应失败: ' . json_last_error_msg());
        }

        if (($result['code'] ?? -1) == 200) {
            return $result['unikey'] ?? '';
        }

        throw new APIException('生成二维码key失败: ' . ($result['message'] ?? '未知错误'));
    }

    /**
     * 创建二维码登录 (返回二维码URL和key)
     *
     * @return array ['unikey' => string, 'qr_url' => string]
     */
    public function createQrLogin(): array
    {
        $unikey = $this->generateQrKey();
        if (!$unikey) {
            throw new APIException('生成二维码key失败');
        }

        $qrUrl = "https://music.163.com/login?codekey={$unikey}";

        return [
            'unikey' => $unikey,
            'qr_url' => $qrUrl,
        ];
    }

    /**
     * 检查二维码登录状态
     *
     * 状态码:
     * - 801: 等待扫码
     * - 802: 扫码成功，等待确认
     * - 803: 登录成功
     * - 其他: 失败/过期
     *
     * @param string $unikey 二维码key
     * @return array ['code' => int, 'cookie' => string, 'message' => string]
     */
    public function checkQrLogin(string $unikey): array
    {
        $config = self::buildConfig();

        $payload = [
            'key' => $unikey,
            'type' => 1,
            'header' => json_encode($config),
        ];

        $params = self::encryptParams(QR_LOGIN_API, $payload);
        $response = self::postRequest(QR_LOGIN_API, $params, [], true);

        $body = $response['body'];
        $headers = $response['headers'];

        $result = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new APIException('解析登录状态响应失败: ' . json_last_error_msg());
        }

        $code = $result['code'] ?? -1;
        $cookie = '';

        if ($code == 803) {
            // 登录成功，从Set-Cookie头提取MUSIC_U
            $cookieStr = '';
            if (preg_match_all('/Set-Cookie:\s*(MUSIC_U=[^;]+)/i', $headers, $matches)) {
                $cookieStr = $matches[1][0];
            }
            $cookie = $cookieStr . ';os=pc;appver=8.9.70;';
        }

        $messages = [
            800 => '二维码已过期',
            801 => '等待扫码',
            802 => '扫码成功，请在手机上确认登录',
            803 => '登录成功',
        ];

        return [
            'code' => $code,
            'cookie' => $cookie,
            'message' => $messages[$code] ?? "未知状态: {$code}",
        ];
    }

    // ==================== Cookie 工具 ====================

    /**
     * 从 cookie.txt 读取并解析为 dict
     *
     * 支持 "k1=v1; k2=v2" 或 "k1=v1\nk2=v2" 格式
     *
     * @param string $path cookie文件路径
     * @return array cookie字典
     */
    public static function loadCookies(string $path = COOKIE_FILE): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $content = trim($content);
        if ($content === '') {
            return [];
        }

        // 先按行处理: 过滤掉以 # 开头的注释行
        $lines = explode("\n", $content);
        $validLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $validLines[] = $line;
        }

        if (empty($validLines)) {
            return [];
        }

        return self::parseCookieString(implode(';', $validLines));
    }

    /**
     * 把 "k1=v1; k2=v2" 格式字符串解析为 dict
     * 支持 ; 或换行 作为分隔符
     */
    public static function parseCookieString(string $cookieString): array
    {
        $cookies = [];
        $cookieString = trim($cookieString);

        if ($cookieString === '') {
            return $cookies;
        }

        // 支持 ; 或换行 作为分隔符
        // 自动跳过以 # 开头的注释行
        $pairs = [];
        if (strpos($cookieString, ';') !== false) {
            $pairs = explode(';', $cookieString);
        } elseif (strpos($cookieString, "\n") !== false) {
            $pairs = explode("\n", $cookieString);
        } else {
            $pairs = [$cookieString];
        }

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            // 跳过空行和注释行 (# 开头)
            if ($pair === '' || $pair[0] === '#' || strpos($pair, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key !== '' && $value !== '') {
                $cookies[$key] = $value;
            }
        }

        return $cookies;
    }

    /**
     * 检查cookie是否有效
     */
    public static function isCookieValid(array $cookies): bool
    {
        $importantKeys = ['MUSIC_U', '__csrf', 'NMTID'];
        foreach ($importantKeys as $key) {
            if (isset($cookies[$key])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 加载多个 Cookie 账号
     *
     * cookie.txt 中每个非注释、非空行 = 一个 Cookie 账号
     * 行内可用 ; 分隔多个键值对
     *
     * @return array<int, array> Cookie 账号数组
     */
    public static function loadAllCookieSets(string $path = COOKIE_FILE): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $lines = explode("\n", $content);
        $cookieSets = [];
        foreach ($lines as $line) {
            $line = trim($line);
            // 跳过空行和注释行
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            // 每行解析为一个 Cookie 集合
            $cookies = self::parseCookieString($line);
            if (!empty($cookies)) {
                $cookieSets[] = $cookies;
            }
        }

        return $cookieSets;
    }

    /**
     * 检测单个 Cookie 账号的状态 (有效/失效 + VIP 等级)
     *
     * @param array $cookies Cookie 键值对
     * @return array { status: 'vip'|'normal'|'invalid', nickname, userId, vipType }
     */
    public function checkCookieStatus(array $cookies): array
    {
        // 未登录态
        if (!self::isCookieValid($cookies)) {
            return [
                'status' => 'invalid',
                'nickname' => '',
                'user_id' => 0,
                'vip_type' => 0,
                'message' => 'Cookie 无关键字',
            ];
        }

        try {
            // 调用网易云账号信息接口
            $url = 'https://music.163.com/api/nuser/account/get';
            $response = self::getRequest($url, $cookies);
            $data = json_decode($response, true);

            if (!isset($data['code']) || $data['code'] !== 200) {
                return [
                    'status' => 'invalid',
                    'nickname' => '',
                    'user_id' => 0,
                    'vip_type' => 0,
                    'message' => 'Cookie 已失效',
                ];
            }

            // 检查是否有账号信息
            $account = $data['account'] ?? null;
            $profile = $data['profile'] ?? null;

            if (!$account || !$profile) {
                return [
                    'status' => 'invalid',
                    'nickname' => '',
                    'user_id' => 0,
                    'vip_type' => 0,
                    'message' => 'Cookie 已失效',
                ];
            }

            $vipType = $profile['vipType'] ?? 0;
            $nickname = $profile['nickname'] ?? '未知用户';
            $userId = $account['id'] ?? 0;

            // vipType: 0 = 普通, 11 = 黑胶VIP, 其他 >= 10 也视为 VIP
            $isVip = ($vipType >= 10);

            return [
                'status' => $isVip ? 'vip' : 'normal',
                'nickname' => $nickname,
                'user_id' => $userId,
                'vip_type' => $vipType,
                'message' => $isVip ? 'VIP 有效' : '普通用户',
            ];

        } catch (Exception $e) {
            return [
                'status' => 'invalid',
                'nickname' => '',
                'user_id' => 0,
                'vip_type' => 0,
                'message' => '检测失败: ' . $e->getMessage(),
            ];
        }
    }
}

// ==================== 向后兼容的函数接口 ====================

/**
 * 获取歌曲URL
 */
function url_v1($songId, string $level, array $cookies = []): array
{
    $api = new MusicAPI();
    return $api->getSongUrl($songId, $level, $cookies);
}

/**
 * 获取歌曲详情
 */
function name_v1($songId): array
{
    $api = new MusicAPI();
    return $api->getSongDetail($songId);
}

/**
 * 获取歌词
 */
function lyric_v1($songId, array $cookies = []): array
{
    $api = new MusicAPI();
    return $api->getLyric($songId, $cookies);
}

/**
 * 搜索音乐
 */
function search_music(string $keywords, array $cookies = [], int $limit = 30): array
{
    $api = new MusicAPI();
    return $api->searchMusic($keywords, $cookies, $limit);
}

/**
 * 获取歌单详情
 */
function playlist_detail($playlistId, array $cookies = []): array
{
    $api = new MusicAPI();
    return $api->getPlaylistDetail($playlistId, $cookies);
}

/**
 * 获取专辑详情
 */
function album_detail($albumId, array $cookies = []): array
{
    $api = new MusicAPI();
    return $api->getAlbumDetail($albumId, $cookies);
}

/**
 * 加载cookies
 */
function load_cookies(string $path = COOKIE_FILE): array
{
    return MusicAPI::loadCookies($path);
}
