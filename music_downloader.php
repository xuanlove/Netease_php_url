<?php
/**
 * 网易云音乐下载器
 *
 * 提供功能:
 * - 同步下载 (本地落盘)
 * - 流式下载 (直接输出到客户端)
 * - 批量下载
 * - 进度查询
 * - 元数据 + 封面写入 (双模式: ffmpeg 优先，不可用降级 getID3 纯 PHP 库)
 *
 * 设计要点:
 * 1. 优先使用 ffmpeg 写入元数据 (支持 MP3/FLAC/M4A/MP4/OGG/Opus 全格式)
 * 2. ffmpeg 不可用时自动降级到 getID3 纯 PHP 库 (支持 MP3/FLAC/OGG/Opus)
 * 3. 扩展名优先使用 API 返回的 type 字段，URL/Content-Type 兜底
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/music_api.php';

/**
 * 下载异常类
 */
class DownloadException extends Exception {}

/**
 * 音乐信息数据类
 */
class MusicInfo
{
    public $id;
    public $name;
    public $artists;
    public $album;
    public $picUrl;
    public $duration;
    public $trackNumber;
    public $downloadUrl;
    public $fileType;
    public $fileSize;
    public $quality;
    public $lyric;
    public $tlyric;

    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? 0;
        $this->name = $data['name'] ?? '';
        $this->artists = $data['artists'] ?? '';
        $this->album = $data['album'] ?? '';
        $this->picUrl = $data['picUrl'] ?? '';
        $this->duration = $data['duration'] ?? 0;
        $this->trackNumber = $data['trackNumber'] ?? 0;
        $this->downloadUrl = $data['downloadUrl'] ?? '';
        $this->fileType = $data['fileType'] ?? 'mp3';
        $this->fileSize = $data['fileSize'] ?? 0;
        $this->quality = $data['quality'] ?? 'standard';
        $this->lyric = $data['lyric'] ?? '';
        $this->tlyric = $data['tlyric'] ?? '';
    }
}

/**
 * 下载结果数据类
 */
class DownloadResult
{
    public $success;
    public $filePath;
    public $fileSize;
    public $errorMessage;
    public $musicInfo;
    /** @var bool|null 元数据写入状态: null=未尝试, true=成功, false=失败 */
    public $metadataWritten;

    public function __construct(bool $success, string $filePath = '', int $fileSize = 0, string $errorMessage = '', ?MusicInfo $musicInfo = null, ?bool $metadataWritten = null)
    {
        $this->success = $success;
        $this->filePath = $filePath;
        $this->fileSize = $fileSize;
        $this->errorMessage = $errorMessage;
        $this->musicInfo = $musicInfo;
        $this->metadataWritten = $metadataWritten;
    }
}

/**
 * 网易云音乐下载器
 */
class MusicDownloader
{
    /** @var string 下载目录 */
    private $downloadDir;

    /** @var int 最大并发下载数 */
    private $maxConcurrent;

    /** @var MusicAPI */
    private $api;

    // API 返回的 type -> 文件扩展名
    private static $apiTypeToExt = [
        'mp3' => '.mp3',
        'flac' => '.flac',
        'm4a' => '.m4a',
        'mp4' => '.mp4',
        'ogg' => '.ogg',
        'opus' => '.opus',
    ];

    // getID3 支持元数据写入的扩展名 -> 标签格式
    private static $extToTagFormat = [
        '.mp3' => 'id3v2.3',
        '.flac' => 'metaflac',
        '.ogg' => 'vorbiscomment',
        '.opus' => 'vorbiscomment',
    ];

    // ffmpeg 支持元数据写入的扩展名列表 (覆盖面更广，含 M4A/MP4)
    private static $ffmpegSupportedExt = ['mp3', 'flac', 'm4a', 'mp4', 'ogg', 'opus'];

    /** @var string|null ffmpeg 可执行文件路径 (运行期解析，null 表示不可用) */
    private $ffmpegPath;

    /**
     * @param string $downloadDir 下载目录
     * @param int $maxConcurrent 最大并发下载数
     */
    public function __construct(string $downloadDir = DOWNLOADS_DIR, int $maxConcurrent = 3)
    {
        $this->downloadDir = $downloadDir;
        if (!is_dir($this->downloadDir)) {
            @mkdir($this->downloadDir, 0755, true);
        }
        $this->maxConcurrent = $maxConcurrent;
        $this->api = new MusicAPI();
        $this->ffmpegPath = $this->resolveFfmpeg();
    }

    /**
     * 清理文件名非法字符
     */
    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[<>:"\/\\\\|?*]/', '_', $filename);
        $filename = trim($filename, ' .');
        if (strlen($filename) > 200) {
            $filename = substr($filename, 0, 200);
        }
        return $filename ?: 'unknown';
    }

    /**
     * 确定文件扩展名
     *
     * 优先级: API返回的type > URL后缀 > 默认mp3
     */
    private function determineFileExtension(string $url, string $apiType = ''): string
    {
        $apiType = strtolower($apiType);
        if (isset(self::$apiTypeToExt[$apiType])) {
            return self::$apiTypeToExt[$apiType];
        }

        $urlLower = strtolower($url);
        if (strpos($urlLower, '.flac') !== false) return '.flac';
        if (strpos($urlLower, '.mp3') !== false) return '.mp3';
        if (strpos($urlLower, '.m4a') !== false || strpos($urlLower, '.mp4') !== false) return '.m4a';

        return '.mp3';
    }

    /**
     * 获取音乐详细信息
     *
     * @param int|string $musicId 音乐ID
     * @param string $quality 音质
     * @return MusicInfo 音乐信息
     * @throws DownloadException
     */
    public function getMusicInfo($musicId, string $quality = 'standard'): MusicInfo
    {
        try {
            $cookies = MusicAPI::loadCookies();

            // 获取播放URL
            $urlResult = $this->api->getSongUrl($musicId, $quality, $cookies);
            if (empty($urlResult['data'])) {
                throw new DownloadException("无法获取音乐ID {$musicId} 的播放链接");
            }

            $songData = $urlResult['data'][0];
            $downloadUrl = $songData['url'] ?? '';
            if (!$downloadUrl) {
                throw new DownloadException("音乐ID {$musicId} 无可用的下载链接");
            }

            // 获取歌曲详情
            $detailResult = $this->api->getSongDetail($musicId);
            if (empty($detailResult['songs'])) {
                throw new DownloadException("无法获取音乐ID {$musicId} 的详细信息");
            }
            $songDetail = $detailResult['songs'][0];

            // 获取歌词
            $lyric = '';
            $tlyric = '';
            try {
                $lyricResult = $this->api->getLyric($musicId, $cookies);
                $lyric = $lyricResult['lrc']['lyric'] ?? '';
                $tlyric = $lyricResult['tlyric']['lyric'] ?? '';
            } catch (Exception $e) {
                // 歌词获取失败不影响主流程
            }

            // 提取艺术家
            $artists = [];
            foreach (($songDetail['ar'] ?? []) as $artist) {
                $artists[] = $artist['name'];
            }

            return new MusicInfo([
                'id' => $musicId,
                'name' => $songDetail['name'] ?? '未知歌曲',
                'artists' => implode('/', $artists) ?: '未知艺术家',
                'album' => $songDetail['al']['name'] ?? '未知专辑',
                'picUrl' => $songDetail['al']['picUrl'] ?? '',
                'duration' => intval(($songDetail['dt'] ?? 0) / 1000),
                'trackNumber' => $songDetail['no'] ?? 0,
                'downloadUrl' => $downloadUrl,
                'fileType' => strtolower($songData['type'] ?? 'mp3'),
                'fileSize' => $songData['size'] ?? 0,
                'quality' => $quality,
                'lyric' => $lyric,
                'tlyric' => $tlyric,
            ]);

        } catch (APIException $e) {
            throw new DownloadException("API调用失败: " . $e->getMessage());
        } catch (DownloadException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new DownloadException("获取音乐信息时发生错误: " . $e->getMessage());
        }
    }

    /**
     * 根据MusicInfo生成下载文件路径
     */
    private function buildFilePath(MusicInfo $info): string
    {
        $filename = "{$info->artists}-{$info->name}-{$info->quality}";
        $safe = $this->sanitizeFilename($filename);
        $ext = $this->determineFileExtension($info->downloadUrl, $info->fileType);
        return $this->downloadDir . DIRECTORY_SEPARATOR . "{$safe}{$ext}";
    }

    /**
     * 同步下载音乐到本地
     *
     * @param int|string $musicId 音乐ID
     * @param string $quality 音质
     * @param bool $force 强制重新下载并写入元数据 (忽略已存在文件)
     * @return DownloadResult 下载结果
     */
    public function downloadMusicFile($musicId, string $quality = 'standard', bool $force = false): DownloadResult
    {
        try {
            $musicInfo = $this->getMusicInfo($musicId, $quality);
            $filePath = $this->buildFilePath($musicInfo);

            // 已存在且非强制模式则直接返回 (跳过元数据写入)
            if (!$force && file_exists($filePath)) {
                return new DownloadResult(true, $filePath, filesize($filePath), '', $musicInfo, null);
            }

            // 强制模式: 删除已存在文件
            if ($force && file_exists($filePath)) {
                @unlink($filePath);
            }

            // 流式下载
            $this->downloadFile($musicInfo->downloadUrl, $filePath);

            // 写入元数据 (双模式: ffmpeg 优先，不可用降级 getID3；失败不影响主流程)
            $metadataOk = $this->writeMetadata($filePath, $musicInfo);

            return new DownloadResult(true, $filePath, filesize($filePath), '', $musicInfo, $metadataOk);

        } catch (DownloadException $e) {
            throw $e;
        } catch (Exception $e) {
            return new DownloadResult(false, '', 0, "下载过程中发生错误: " . $e->getMessage());
        }
    }

    /**
     * 下载文件到指定路径
     */
    private function downloadFile(string $url, string $filePath): void
    {
        $ch = curl_init();
        $fp = fopen($filePath, 'wb');
        if (!$fp) {
            throw new DownloadException("无法创建文件: {$filePath}");
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => USER_AGENT,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$result || $httpCode >= 400) {
            @unlink($filePath);
            throw new DownloadException("下载请求失败: HTTP {$httpCode}");
        }
    }

    /**
     * 流式下载音乐 (直接输出到浏览器，不落盘)
     *
     * @param int|string $musicId 音乐ID
     * @param string $quality 音质
     * @return array ['info' => MusicInfo, 'content' => string]
     */
    public function downloadMusicToMemory($musicId, string $quality = 'standard'): array
    {
        $musicInfo = $this->getMusicInfo($musicId, $quality);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $musicInfo->downloadUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => USER_AGENT,
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content === false || $httpCode >= 400) {
            throw new DownloadException("下载到内存失败: HTTP {$httpCode}");
        }

        return ['info' => $musicInfo, 'content' => $content];
    }

    /**
     * 批量下载
     *
     * @param array $musicIds 音乐ID列表
     * @param string $quality 音质
     * @return array DownloadResult列表
     */
    public function downloadBatch(array $musicIds, string $quality = 'standard'): array
    {
        $results = [];
        foreach ($musicIds as $musicId) {
            try {
                $results[] = $this->downloadMusicFile($musicId, $quality);
            } catch (Exception $e) {
                $results[] = new DownloadResult(false, '', 0, "下载音乐ID {$musicId} 时发生异常: " . $e->getMessage());
            }
        }
        return $results;
    }

    /**
     * 查询下载进度
     *
     * @param int|string $musicId 音乐ID
     * @param string $quality 音质
     * @return array 进度信息
     */
    public function getDownloadProgress($musicId, string $quality = 'standard'): array
    {
        try {
            $musicInfo = $this->getMusicInfo($musicId, $quality);
            $filePath = $this->buildFilePath($musicInfo);

            if (file_exists($filePath)) {
                $currentSize = filesize($filePath);
                $progress = $musicInfo->fileSize > 0 ? ($currentSize / $musicInfo->fileSize * 100) : 0;
                return [
                    'music_id' => $musicId,
                    'filename' => basename($filePath),
                    'total_size' => $musicInfo->fileSize,
                    'current_size' => $currentSize,
                    'progress' => min($progress, 100),
                    'completed' => $currentSize >= $musicInfo->fileSize,
                ];
            }

            return [
                'music_id' => $musicId,
                'filename' => basename($filePath),
                'total_size' => $musicInfo->fileSize,
                'current_size' => 0,
                'progress' => 0,
                'completed' => false,
            ];

        } catch (Exception $e) {
            return [
                'music_id' => $musicId,
                'error' => $e->getMessage(),
                'progress' => 0,
                'completed' => false,
            ];
        }
    }

    // ==================== 元数据写入 (双模式) ====================

    /**
     * 双模式写入元数据入口
     *
     * 优先使用 ffmpeg；不可用或写入失败时降级到 getID3 纯 PHP 库
     *
     * @param string $filePath 文件路径
     * @param MusicInfo $musicInfo 音乐信息
     * @return bool 是否写入成功
     */
    private function writeMetadata(string $filePath, MusicInfo $musicInfo): bool
    {
        // 1. 优先尝试 ffmpeg
        if ($this->ffmpegPath !== null) {
            if ($this->writeMetadataWithFfmpeg($filePath, $musicInfo)) {
                return true;
            }
            error_log("ffmpeg 元数据写入失败，降级到 getID3");
        }

        // 2. 降级到 getID3
        return $this->writeMetadataWithGetid3($filePath, $musicInfo);
    }

    /**
     * 获取当前元数据引擎信息 (供健康检查 / 前端提示使用)
     *
     * @return array 引擎状态信息
     */
    public function getMetadataEngine(): array
    {
        $ffmpegInfo = $this->detectFfmpeg();
        $getid3Path = __DIR__ . '/libs/getid3/getid3/getid3.php';
        $getid3Available = file_exists($getid3Path);

        if ($ffmpegInfo['available']) {
            $engine = 'ffmpeg';
            $fallback = $getid3Available ? 'getID3' : null;
        } else {
            $engine = $getid3Available ? 'getID3' : 'none';
            $fallback = null;
        }

        return [
            'engine' => $engine,
            'fallback' => $fallback,
            'ffmpeg' => $ffmpegInfo,
            'getid3' => ['available' => $getid3Available],
        ];
    }

    // ==================== ffmpeg 元数据写入 ====================

    /**
     * 检测 ffmpeg 可用性
     *
     * @return array {available: bool, path: string|null, version: string|null}
     */
    public function detectFfmpeg(): array
    {
        if ($this->ffmpegPath === null) {
            return ['available' => false, 'path' => null, 'version' => null];
        }

        $output = @shell_exec('"' . $this->ffmpegPath . '" -version 2>&1');
        if (empty($output) || strpos($output, 'ffmpeg version') === false) {
            return ['available' => false, 'path' => $this->ffmpegPath, 'version' => null];
        }

        $version = '';
        if (preg_match('/ffmpeg version ([\d.\-a-z]+)/i', $output, $m)) {
            $version = $m[1];
        }
        return ['available' => true, 'path' => $this->ffmpegPath, 'version' => $version];
    }

    /**
     * 解析 ffmpeg 路径
     *
     * 优先级: FFMPEG_PATH 常量 > PATH 环境变量查找
     *
     * @return string|null
     */
    private function resolveFfmpeg(): ?string
    {
        // shell_exec 被禁用时直接返回 null
        if (!function_exists('shell_exec')) {
            return null;
        }

        // 1. 配置的常量
        if (defined('FFMPEG_PATH') && FFMPEG_PATH) {
            $path = FFMPEG_PATH;
            // Windows 下 is_executable 对 .exe 可能误判，放宽检查
            if (file_exists($path)) {
                return $path;
            }
        }

        // 2. PATH 中查找
        return $this->which('ffmpeg');
    }

    /**
     * 在 PATH 中查找可执行命令
     *
     * @param string $cmd 命令名
     * @return string|null 完整路径
     */
    private function which(string $cmd): ?string
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $checkCmd = $isWindows ? "where {$cmd} 2>nul" : "command -v {$cmd} 2>/dev/null";
        $result = @shell_exec($checkCmd);
        if (empty($result)) {
            return null;
        }
        $result = trim($result);
        // Windows 的 where 可能返回多行，取第一行
        if (strpos($result, "\n") !== false) {
            $lines = explode("\n", $result);
            $result = trim($lines[0]);
        }
        // 再次验证文件存在
        if ($result !== '' && file_exists($result)) {
            return $result;
        }
        return null;
    }

    /**
     * 使用 ffmpeg 写入音频元数据 + 封面
     *
     * @param string $filePath 文件路径
     * @param MusicInfo $musicInfo 音乐信息
     * @return bool 是否写入成功
     */
    private function writeMetadataWithFfmpeg(string $filePath, MusicInfo $musicInfo): bool
    {
        if ($this->ffmpegPath === null) {
            return false;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::$ffmpegSupportedExt)) {
            error_log("ffmpeg 跳过元数据写入: 不支持格式 {$ext}");
            return false;
        }

        if (!file_exists($filePath)) {
            error_log("ffmpeg 跳过元数据写入: 文件不存在 {$filePath}");
            return false;
        }

        // 下载封面到临时文件
        $coverPath = $this->downloadCoverToTemp($musicInfo->picUrl);

        try {
            $tmpOutput = $filePath . '.tmp_' . bin2hex(random_bytes(4));
            $cmd = $this->buildFfmpegCommand($filePath, $tmpOutput, $musicInfo, $coverPath);

            $output = [];
            $returnCode = 0;
            @exec($cmd . ' 2>&1', $output, $returnCode);

            if ($returnCode === 0 && file_exists($tmpOutput) && filesize($tmpOutput) > 0) {
                // 替换原文件
                @unlink($filePath);
                @rename($tmpOutput, $filePath);
                return true;
            }

            error_log("ffmpeg 写入失败 (code={$returnCode}): " . implode("\n", $output));
            if (file_exists($tmpOutput)) {
                @unlink($tmpOutput);
            }
            return false;
        } catch (Exception $e) {
            error_log("ffmpeg 写入异常: " . $e->getMessage());
            return false;
        } finally {
            if ($coverPath !== null && file_exists($coverPath)) {
                @unlink($coverPath);
            }
        }
    }

    /**
     * 下载封面图片到临时文件
     *
     * @param string $picUrl 封面 URL
     * @return string|null 临时文件路径
     */
    private function downloadCoverToTemp(string $picUrl): ?string
    {
        if (empty($picUrl)) {
            return null;
        }

        try {
            $content = @file_get_contents($picUrl);
            if ($content === false || $content === '') {
                return null;
            }

            $tmpBase = tempnam($this->downloadDir, 'cover_');
            if ($tmpBase === false) {
                return null;
            }

            // 根据图像类型追加扩展名 (ffmpeg 读取图片更可靠)
            $finalPath = $tmpBase;
            if (function_exists('exif_imagetype')) {
                $tmpProbe = tempnam($this->downloadDir, 'probe_');
                if ($tmpProbe !== false) {
                    file_put_contents($tmpProbe, $content);
                    $imageType = @exif_imagetype($tmpProbe);
                    @unlink($tmpProbe);
                    if ($imageType !== false) {
                        $ext = image_type_to_extension($imageType, false); // 'jpeg' / 'png' / 'gif'
                        $finalPath = $tmpBase . '.' . $ext;
                    }
                }
            }

            file_put_contents($finalPath, $content);
            @unlink($tmpBase);
            return $finalPath;
        } catch (Exception $e) {
            error_log("封面下载失败: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 构建 ffmpeg 写入元数据的命令行
     *
     * @param string $input 输入文件
     * @param string $output 输出文件
     * @param MusicInfo $info 音乐信息
     * @param string|null $coverPath 封面文件路径
     * @return string 完整命令
     */
    private function buildFfmpegCommand(string $input, string $output, MusicInfo $info, ?string $coverPath): string
    {
        $parts = [];
        $parts[] = '"' . $this->ffmpegPath . '"';
        $parts[] = '-i';
        $parts[] = '"' . $input . '"';

        // 封面作为第二个输入
        if ($coverPath !== null) {
            $parts[] = '-i';
            $parts[] = '"' . $coverPath . '"';
            $parts[] = '-map 0:a';
            $parts[] = '-map 1:0';
            $parts[] = '-c copy';
            $parts[] = '-disposition:v:0 attached_pic';
        } else {
            $parts[] = '-c copy';
        }

        // ID3v2 版本 (仅 MP3)
        $ext = strtolower(pathinfo($input, PATHINFO_EXTENSION));
        if ($ext === 'mp3') {
            $parts[] = '-id3v2_version 3';
        }

        // 元数据字段
        if ($info->name) {
            $parts[] = '-metadata title=' . escapeshellarg($info->name);
        }
        if ($info->artists) {
            $parts[] = '-metadata artist=' . escapeshellarg($info->artists);
            $parts[] = '-metadata album_artist=' . escapeshellarg($info->artists);
        }
        if ($info->album) {
            $parts[] = '-metadata album=' . escapeshellarg($info->album);
        }
        if ($info->trackNumber > 0) {
            $parts[] = '-metadata track=' . escapeshellarg((string)$info->trackNumber);
        }
        $parts[] = '-metadata comment=' . escapeshellarg('Downloaded from Netease Cloud Music');

        // 封面流元数据
        if ($coverPath !== null) {
            $parts[] = '-metadata:s:v title=' . escapeshellarg('Album cover');
            $parts[] = '-metadata:s:v comment=' . escapeshellarg('Cover (front)');
        }

        // 覆盖输出
        $parts[] = '-y';
        $parts[] = '"' . $output . '"';

        return implode(' ', $parts);
    }

    // ==================== getID3 元数据写入 ====================

    /**
     * 使用 getID3 (纯PHP) 写入音频元数据
     *
     * 处理: title/artist/album/track + 封面 (如果支持)
     *
     * 注意: getID3 的 metaflac/vorbiscomment 写入器依赖外部命令行工具，
     *       因此 FLAC 使用内置的纯 PHP 写入器 (writeFlacMetadataNative)
     *
     * @param string $filePath 文件路径
     * @param MusicInfo $musicInfo 音乐信息
     * @return bool 是否写入成功
     */
    private function writeMetadataWithGetid3(string $filePath, MusicInfo $musicInfo): bool
    {
        $fileExt = '.' . strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // FLAC 使用纯 PHP 写入器 (getID3 的 metaflac 依赖外部工具)
        if ($fileExt === '.flac') {
            return $this->writeFlacMetadataNative($filePath, $musicInfo);
        }

        // 检查 getID3 是否支持该格式
        if (!isset(self::$extToTagFormat[$fileExt])) {
            error_log("跳过元数据写入: getID3 不支持格式 {$fileExt}");
            return false;
        }

        if (!file_exists($filePath)) {
            error_log("跳过元数据写入: 文件不存在 {$filePath}");
            return false;
        }

        // 加载 getID3 库
        $getid3Path = __DIR__ . '/libs/getid3/getid3/getid3.php';
        if (!file_exists($getid3Path)) {
            error_log("跳过元数据写入: getID3 库不存在");
            return false;
        }

        require_once $getid3Path;
        require_once __DIR__ . '/libs/getid3/getid3/write.php';

        try {
            $tagFormat = self::$extToTagFormat[$fileExt];
            $tagwriter = new getid3_writetags;
            $tagwriter->filename = $filePath;
            $tagwriter->tagformats = [$tagFormat];
            $tagwriter->overwrite_tags = true;
            $tagwriter->remove_other_tags = false;
            $tagwriter->tag_encoding = 'UTF-8';

            // 构建元数据 (所有值必须是数组)
            $tagData = [];
            if ($musicInfo->name) {
                $tagData['title'] = [$musicInfo->name];
            }
            if ($musicInfo->artists) {
                $tagData['artist'] = [$musicInfo->artists];
                $tagData['album_artist'] = [$musicInfo->artists];
            }
            if ($musicInfo->album) {
                $tagData['album'] = [$musicInfo->album];
            }
            $tagData['comment'] = ['Downloaded from Netease Cloud Music'];
            if ($musicInfo->trackNumber > 0) {
                $tagData['track_number'] = [(string)$musicInfo->trackNumber];
            }

            // 下载并嵌入封面图片 (仅 ID3v2 / MP3 支持)
            if ($musicInfo->picUrl) {
                try {
                    $coverContent = @file_get_contents($musicInfo->picUrl);
                    if ($coverContent !== false && function_exists('exif_imagetype')) {
                        $tmpCover = tempnam($this->downloadDir, 'cover_');
                        file_put_contents($tmpCover, $coverContent);
                        $imageType = @exif_imagetype($tmpCover);
                        @unlink($tmpCover);

                        if ($imageType !== false) {
                            $mime = image_type_to_mime_type($imageType);
                            $tagData['attached_picture'][0]['data'] = $coverContent;
                            $tagData['attached_picture'][0]['picturetypeid'] = 3; // Cover (front)
                            $tagData['attached_picture'][0]['description'] = 'Album cover';
                            $tagData['attached_picture'][0]['mime'] = $mime;
                        }
                    }
                } catch (Exception $e) {
                    error_log("封面下载失败，跳过封面写入: " . $e->getMessage());
                }
            }

            $tagwriter->tag_data = $tagData;

            if ($tagwriter->WriteTags()) {
                if (!empty($tagwriter->warnings)) {
                    error_log("getID3 写入警告: " . implode('; ', $tagwriter->warnings));
                }
                return true;
            }

            error_log("getID3 写入元数据失败: " . implode('; ', $tagwriter->errors));
            return false;

        } catch (Exception $e) {
            error_log("getID3 写入元数据异常: " . $e->getMessage());
            return false;
        }
    }

    // ==================== FLAC 纯 PHP 元数据写入 ====================

    /**
     * 纯 PHP 写入 FLAC 元数据 (VORBIS_COMMENT + PICTURE 块)
     *
     * 使用流式 I/O，避免将整个大文件读入内存
     *
     * FLAC 文件格式:
     * - "fLaC" (4 bytes) 文件标识
     * - metadata 块序列: header(1B) + length(3B BE) + data(NB)
     *   header: bit7=is_last, bits6-0=block_type
     *   type: 0=STREAMINFO, 4=VORBIS_COMMENT, 6=PICTURE
     *
     * @param string $filePath FLAC 文件路径
     * @param MusicInfo $musicInfo 音乐信息
     * @return bool 是否写入成功
     */
    private function writeFlacMetadataNative(string $filePath, MusicInfo $musicInfo): bool
    {
        // 只读取 metadata 部分到内存（头部几十 KB），音频数据用流式拷贝
        $source = @fopen($filePath, 'rb');
        if ($source === false) {
            error_log("FLAC 写入失败: 无法打开文件 {$filePath}");
            return false;
        }

        try {
            // 读取并验证 fLaC 标识
            $magic = fread($source, 4);
            if ($magic !== 'fLaC') {
                error_log("FLAC 写入失败: 不是有效的 FLAC 文件");
                return false;
            }

            // 解析所有 metadata 块，只保留非 VORBIS_COMMENT(4)/PICTURE(6) 块到内存
            $keptBlocks = []; // ['type' => int, 'data' => string]
            $audioDataOffset = -1;

            while (!feof($source)) {
                $headerBytes = fread($source, 4);
                if (strlen($headerBytes) < 4) {
                    break;
                }

                $header = ord($headerBytes[0]);
                $isLast = ($header & 0x80) !== 0;
                $blockType = $header & 0x7F;
                $blockLen = (ord($headerBytes[1]) << 16)
                          | (ord($headerBytes[2]) << 8)
                          | ord($headerBytes[3]);

                if ($blockLen > 16 * 1024 * 1024) { // 单块超过 16MB 视为异常
                    error_log("FLAC 写入失败: metadata 块过大 (type={$blockType}, len={$blockLen})");
                    return false;
                }

                $blockData = '';
                $remaining = $blockLen;
                while ($remaining > 0 && !feof($source)) {
                    $chunk = fread($source, min($remaining, 65536));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $blockData .= $chunk;
                    $remaining -= strlen($chunk);
                }

                if (strlen($blockData) < $blockLen) {
                    error_log("FLAC 写入失败: 块数据不完整 (type={$blockType})");
                    return false;
                }

                // 保留 STREAMINFO(0)/PADDING(1)/APPLICATION(2)/SEEKTABLE(3)/CUESHEET(5)
                // 移除旧的 VORBIS_COMMENT(4) 和 PICTURE(6)，稍后重新添加
                if ($blockType !== 4 && $blockType !== 6) {
                    $keptBlocks[] = ['type' => $blockType, 'data' => $blockData];
                }

                if ($isLast) {
                    $audioDataOffset = ftell($source);
                    break;
                }
            }

            if ($audioDataOffset < 0) {
                error_log("FLAC 写入失败: 未找到最后一个 metadata 块");
                return false;
            }

            // 追加新的 VORBIS_COMMENT 块
            $vorbisCommentData = $this->buildFlacVorbisCommentBlock($musicInfo);
            $keptBlocks[] = ['type' => 4, 'data' => $vorbisCommentData];

            // 追加新的 PICTURE 块 (如果有封面)
            $coverContent = $this->downloadCoverContent($musicInfo->picUrl);
            if ($coverContent !== null) {
                $pictureBlockData = $this->buildFlacPictureBlock($coverContent);
                if ($pictureBlockData !== null) {
                    $keptBlocks[] = ['type' => 6, 'data' => $pictureBlockData];
                }
            }

            // 写入临时文件: 先写 fLaC + metadata 块，再流式拷贝音频数据
            $tmpPath = $filePath . '.tmp_' . bin2hex(random_bytes(4));
            $dest = @fopen($tmpPath, 'wb');
            if ($dest === false) {
                error_log("FLAC 写入失败: 无法创建临时文件 {$tmpPath}");
                return false;
            }

            try {
                // 写入 fLaC 标识
                fwrite($dest, 'fLaC');

                // 写入所有 metadata 块
                $blockCount = count($keptBlocks);
                for ($i = 0; $i < $blockCount; $i++) {
                    $isLast = ($i === $blockCount - 1);
                    $type = $keptBlocks[$i]['type'];
                    $data = $keptBlocks[$i]['data'];
                    $len = strlen($data);

                    // 1 字节 header
                    fwrite($dest, chr(($isLast ? 0x80 : 0x00) | $type));
                    // 3 字节大端长度
                    fwrite($dest, chr(($len >> 16) & 0xFF));
                    fwrite($dest, chr(($len >> 8) & 0xFF));
                    fwrite($dest, chr($len & 0xFF));
                    // 块数据
                    fwrite($dest, $data);
                }

                // 流式拷贝音频数据 (避免大块内存占用)
                fseek($source, $audioDataOffset);
                while (!feof($source)) {
                    $chunk = fread($source, 1024 * 1024); // 1MB 每次
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    fwrite($dest, $chunk);
                }
            } finally {
                fclose($dest);
            }

        } finally {
            fclose($source);
        }

        // 替换原文件
        @unlink($filePath);
        if (!@rename($tmpPath, $filePath)) {
            if (!@copy($tmpPath, $filePath)) {
                error_log("FLAC 写入失败: 无法替换原文件 {$filePath}");
                @unlink($tmpPath);
                return false;
            }
            @unlink($tmpPath);
        }

        return true;
    }

    /**
     * 构建 FLAC VORBIS_COMMENT 块数据
     *
     * 格式 (小端序):
     * - 4B vendor string length (LE)
     * - NB vendor string
     * - 4B comment count (LE)
     * - 每个: 4B length (LE) + NB "KEY=VALUE"
     *
     * @param MusicInfo $info 音乐信息
     * @return string 块数据
     */
    private function buildFlacVorbisCommentBlock(MusicInfo $info): string
    {
        $comments = [];
        if ($info->name) {
            $comments[] = 'TITLE=' . $info->name;
        }
        if ($info->artists) {
            $comments[] = 'ARTIST=' . $info->artists;
            $comments[] = 'ALBUMARTIST=' . $info->artists;
        }
        if ($info->album) {
            $comments[] = 'ALBUM=' . $info->album;
        }
        $comments[] = 'COMMENT=Downloaded from Netease Cloud Music';
        if ($info->trackNumber > 0) {
            $comments[] = 'TRACKNUMBER=' . (string)$info->trackNumber;
        }

        $data = '';
        // vendor string
        $vendor = 'Netease Cloud Music PHP Downloader';
        $data .= pack('V', strlen($vendor)); // little-endian 32-bit
        $data .= $vendor;
        // comment count
        $data .= pack('V', count($comments));
        // each comment
        foreach ($comments as $comment) {
            $data .= pack('V', strlen($comment));
            $data .= $comment;
        }

        return $data;
    }

    /**
     * 构建 FLAC PICTURE 块数据
     *
     * 格式 (大端序):
     * - 4B picture type (3=Cover front)
     * - 4B MIME length + MIME string
     * - 4B description length + description string
     * - 4B width, 4B height, 4B color depth, 4B colors
     * - 4B picture data length + picture data
     *
     * @param string $coverData 封面二进制数据
     * @return string|null 块数据
     */
    private function buildFlacPictureBlock(string $coverData): ?string
    {
        if (empty($coverData)) {
            return null;
        }

        // 检测图片 MIME 类型
        $mime = 'image/jpeg';
        if (function_exists('exif_imagetype')) {
            $tmpProbe = tempnam($this->downloadDir, 'probe_');
            if ($tmpProbe !== false) {
                file_put_contents($tmpProbe, $coverData);
                $imageType = @exif_imagetype($tmpProbe);
                @unlink($tmpProbe);
                if ($imageType !== false) {
                    $mime = image_type_to_mime_type($imageType);
                }
            }
        }

        $data = '';
        $data .= pack('N', 3); // picture type: Cover (front) - big-endian
        $data .= pack('N', strlen($mime)); // MIME length
        $data .= $mime;
        $desc = 'Album cover';
        $data .= pack('N', strlen($desc)); // description length
        $data .= $desc;
        $data .= pack('N', 0); // width (unknown)
        $data .= pack('N', 0); // height (unknown)
        $data .= pack('N', 0); // color depth (unknown)
        $data .= pack('N', 0); // number of colors (unknown)
        $data .= pack('N', strlen($coverData)); // picture data length
        $data .= $coverData;

        return $data;
    }

    /**
     * 下载封面图片并返回二进制内容
     *
     * @param string $picUrl 封面 URL
     * @return string|null 封面二进制数据
     */
    private function downloadCoverContent(string $picUrl): ?string
    {
        if (empty($picUrl)) {
            return null;
        }

        try {
            $content = @file_get_contents($picUrl);
            if ($content === false || $content === '') {
                return null;
            }
            return $content;
        } catch (Exception $e) {
            error_log("封面下载失败: " . $e->getMessage());
            return null;
        }
    }
}
