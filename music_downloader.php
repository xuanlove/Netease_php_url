<?php
/**
 * 网易云音乐下载器
 *
 * 提供功能:
 * - 同步下载 (本地落盘)
 * - 流式下载 (直接输出到客户端)
 * - 批量下载
 * - 进度查询
 * - 基于 ffmpeg 的元数据 + 封面写入 (可选)
 *
 * 设计要点:
 * 1. ffmpeg 路径懒加载: 每次写入前重新检测
 * 2. MP4 (杜比全景声 EAC3) 单独分支，启用 +faststart
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

    public function __construct(bool $success, string $filePath = '', int $fileSize = 0, string $errorMessage = '', ?MusicInfo $musicInfo = null)
    {
        $this->success = $success;
        $this->filePath = $filePath;
        $this->fileSize = $fileSize;
        $this->errorMessage = $errorMessage;
        $this->musicInfo = $musicInfo;
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

    /** @var string|null ffmpeg路径 */
    private $ffmpegPath;

    // API 返回的 type -> 文件扩展名
    private static $apiTypeToExt = [
        'mp3' => '.mp3',
        'flac' => '.flac',
        'm4a' => '.m4a',
        'mp4' => '.mp4',
        'ogg' => '.ogg',
        'opus' => '.opus',
    ];

    // 文件扩展名 -> ffmpeg 输出 muxer 名称
    private static $extToFfmpegFormat = [
        '.mp3' => 'mp3',
        '.flac' => 'flac',
        '.m4a' => 'mp4',
        '.mp4' => 'mp4',
        '.ogg' => 'ogg',
        '.opus' => 'opus',
    ];

    // 支持元数据写入的扩展名
    private static $metadataFormats = ['.mp3', '.flac', '.m4a', '.mp4', '.ogg', '.opus'];

    /**
     * @param string $downloadDir 下载目录
     * @param int $maxConcurrent 最大并发下载数
     * @param string|null $ffmpegPath ffmpeg路径
     */
    public function __construct(string $downloadDir = DOWNLOADS_DIR, int $maxConcurrent = 3, ?string $ffmpegPath = null)
    {
        $this->downloadDir = $downloadDir;
        if (!is_dir($this->downloadDir)) {
            @mkdir($this->downloadDir, 0755, true);
        }
        $this->maxConcurrent = $maxConcurrent;
        $this->api = new MusicAPI();

        // ffmpeg路径: 优先使用参数，其次配置，最后检测系统PATH
        $this->ffmpegPath = $ffmpegPath ?: FFMPEG_PATH;
        if (!$this->ffmpegPath) {
            $this->ffmpegPath = $this->which('ffmpeg');
        }
        if (!$this->ffmpegPath) {
            error_log("警告: 未检测到 ffmpeg，写入元数据功能将不可用");
        }
    }

    /**
     * 模拟 which 命令查找可执行文件
     */
    private function which(string $program): ?string
    {
        // Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $paths = explode(PATH_SEPARATOR, getenv('PATH'));
            $exts = ['.exe', '.bat', '.cmd'];
            foreach ($paths as $path) {
                foreach ($exts as $ext) {
                    $full = $path . DIRECTORY_SEPARATOR . $program . $ext;
                    if (file_exists($full) && is_executable($full)) {
                        return $full;
                    }
                }
            }
        } else {
            // Unix-like
            $paths = explode(PATH_SEPARATOR, getenv('PATH'));
            foreach ($paths as $path) {
                $full = $path . DIRECTORY_SEPARATOR . $program;
                if (file_exists($full) && is_executable($full)) {
                    return $full;
                }
            }
        }
        return null;
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
     * 懒加载解析 ffmpeg 路径
     */
    private function resolveFfmpeg(): ?string
    {
        if ($this->ffmpegPath) {
            return $this->ffmpegPath;
        }
        $path = $this->which('ffmpeg');
        if ($path) {
            $this->ffmpegPath = $path;
        }
        return $path;
    }

    /**
     * 检测 ffmpeg 是否可用
     *
     * @return array{available: bool, path: string|null, version: string|null}
     */
    public function detectFfmpeg(): array
    {
        $path = $this->resolveFfmpeg();
        if (!$path) {
            return ['available' => false, 'path' => null, 'version' => null];
        }

        // 尝试获取版本信息
        $version = null;
        $cmd = escapeshellarg($path) . ' -version';
        $output = @shell_exec($cmd . ' 2>&1');
        if ($output && preg_match('/ffmpeg version\s+([^\s]+)/i', $output, $m)) {
            $version = $m[1];
        }

        return ['available' => true, 'path' => $path, 'version' => $version];
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
        $filename = "{$info->artists} - {$info->name}";
        $safe = $this->sanitizeFilename($filename);
        $ext = $this->determineFileExtension($info->downloadUrl, $info->fileType);
        return $this->downloadDir . DIRECTORY_SEPARATOR . "{$safe}{$ext}";
    }

    /**
     * 同步下载音乐到本地
     *
     * @param int|string $musicId 音乐ID
     * @param string $quality 音质
     * @return DownloadResult 下载结果
     */
    public function downloadMusicFile($musicId, string $quality = 'standard'): DownloadResult
    {
        try {
            $musicInfo = $this->getMusicInfo($musicId, $quality);
            $filePath = $this->buildFilePath($musicInfo);

            // 已存在则直接返回
            if (file_exists($filePath)) {
                return new DownloadResult(true, $filePath, filesize($filePath), '', $musicInfo);
            }

            // 流式下载
            $this->downloadFile($musicInfo->downloadUrl, $filePath);

            // 写入元数据 (失败不影响主流程)
            $this->writeMetadataWithFfmpeg($filePath, $musicInfo);

            return new DownloadResult(true, $filePath, filesize($filePath), '', $musicInfo);

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

    // ==================== ffmpeg 元数据写入 ====================

    /**
     * 使用 ffmpeg 写入音频元数据
     *
     * 处理: title/artist/album/track + 封面 (如果支持)
     *
     * @param string $filePath 文件路径
     * @param MusicInfo $musicInfo 音乐信息
     * @return bool 是否写入成功
     */
    private function writeMetadataWithFfmpeg(string $filePath, MusicInfo $musicInfo): bool
    {
        $ffmpegPath = $this->resolveFfmpeg();
        if (!$ffmpegPath) {
            error_log("跳过元数据写入: ffmpeg 不可用");
            return false;
        }

        $fileExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $fileExt = '.' . $fileExt;

        if (!in_array($fileExt, self::$metadataFormats)) {
            error_log("跳过元数据写入: 不支持的格式 {$fileExt}");
            return false;
        }

        if (!file_exists($filePath)) {
            error_log("跳过元数据写入: 文件不存在 {$filePath}");
            return false;
        }

        // 临时输出文件
        $tmpOutput = preg_replace('/\.' . pathinfo($filePath, PATHINFO_EXTENSION) . '$/', '.tagging.' . pathinfo($filePath, PATHINFO_EXTENSION), $filePath);
        $coverTmp = null;

        try {
            // 下载封面到临时文件
            if ($musicInfo->picUrl) {
                try {
                    $coverContent = file_get_contents($musicInfo->picUrl);
                    if ($coverContent !== false) {
                        $coverTmp = tempnam($this->downloadDir, 'cover_');
                        $coverTmp = preg_replace('/$/', '.jpg', $coverTmp);
                        // 确保扩展名
                        if (!preg_match('/\.jpg$/', $coverTmp)) {
                            $newCover = $coverTmp . '.jpg';
                            rename($coverTmp, $newCover);
                            $coverTmp = $newCover;
                        }
                        file_put_contents($coverTmp, $coverContent);
                    }
                } catch (Exception $e) {
                    error_log("封面下载失败，跳过封面写入: " . $e->getMessage());
                    $coverTmp = null;
                }
            }

            // 构造 ffmpeg 命令
            $outputFormat = self::$extToFfmpegFormat[$fileExt] ?? '';
            $cmd = escapeshellarg($ffmpegPath) . ' -y -loglevel error -i ' . escapeshellarg($filePath);
            if ($coverTmp) {
                $cmd .= ' -i ' . escapeshellarg($coverTmp);
            }

            // 通用元数据
            $meta = [
                'title' => $musicInfo->name,
                'artist' => $musicInfo->artists,
                'album' => $musicInfo->album,
                'album_artist' => $musicInfo->artists,
                'comment' => 'Downloaded from Netease Cloud Music',
            ];
            if ($musicInfo->trackNumber > 0) {
                $meta['track'] = (string)$musicInfo->trackNumber;
                $meta['tracktotal'] = (string)$musicInfo->trackNumber;
            }
            foreach ($meta as $k => $v) {
                if ($v) {
                    $cmd .= ' -metadata ' . escapeshellarg("{$k}={$v}");
                }
            }

            // 各格式的 mapping / 编码选项
            $cmd .= ' ' . implode(' ', $this->buildFormatArgs($fileExt, $coverTmp !== null));

            // -f 必须放在所有 -i 之后
            if ($outputFormat) {
                $cmd .= ' -f ' . escapeshellarg($outputFormat);
            }
            $cmd .= ' ' . escapeshellarg($tmpOutput);

            // 执行
            $output = [];
            $returnCode = 0;
            exec($cmd . ' 2>&1', $output, $returnCode);

            if ($returnCode === 0 && file_exists($tmpOutput)) {
                // 原子替换原文件
                rename($tmpOutput, $filePath);
                return true;
            }

            error_log("ffmpeg 写入元数据失败: " . implode("\n", $output));
            return false;

        } catch (Exception $e) {
            error_log("ffmpeg 写入元数据异常: " . $e->getMessage());
            return false;
        } finally {
            // 清理封面临时文件
            if ($coverTmp && file_exists($coverTmp)) {
                @unlink($coverTmp);
            }
            // 清理可能残留的临时输出
            if (file_exists($tmpOutput)) {
                @unlink($tmpOutput);
            }
        }
    }

    /**
     * 根据文件格式构造 mapping / 编码参数
     */
    private function buildFormatArgs(string $fileExt, bool $hasCover): array
    {
        switch ($fileExt) {
            case '.mp3':
                // MP3: 封面通过 ID3v2 APIC 帧写入
                if ($hasCover) {
                    return [
                        '-map', '0:a', '-map', '1:v',
                        '-c:a', 'copy', '-c:v', 'copy',
                        '-id3v2_version', '3',
                        '-metadata:s:v', 'title=Album cover',
                        '-metadata:s:v', 'comment=Cover (front)',
                    ];
                }
                return ['-map', '0:a', '-c:a', 'copy', '-id3v2_version', '3'];

            case '.flac':
                // FLAC: 原生支持封面
                if ($hasCover) {
                    return [
                        '-map', '0', '-map', '1',
                        '-c', 'copy',
                        '-disposition:v:0', 'attached_pic',
                    ];
                }
                return ['-map', '0', '-c', 'copy'];

            case '.m4a':
                // M4A: AAC 音频 + mp4 容器
                if ($hasCover) {
                    return [
                        '-map', '0:a', '-map', '1:v',
                        '-c', 'copy',
                        '-disposition:v:0', 'attached_pic',
                        '-metadata:s:v', 'title=Album cover',
                        '-metadata:s:v', 'comment=Cover (front)',
                    ];
                }
                return ['-map', '0:a', '-c:a', 'copy'];

            case '.mp4':
                // MP4 (杜比全景声 EAC3)
                if ($hasCover) {
                    return [
                        '-map', '0', '-map', '1:v',
                        '-c', 'copy',
                        '-disposition:v:0', 'attached_pic',
                        '-metadata:s:v', 'title=Album cover',
                        '-metadata:s:v', 'comment=Cover (front)',
                        '-movflags', '+faststart',
                    ];
                }
                return ['-map', '0', '-c', 'copy', '-movflags', '+faststart'];

            case '.ogg':
            case '.opus':
                if ($hasCover) {
                    return [
                        '-map', '0:a', '-map', '1:v',
                        '-c:a', 'copy', '-c:v', 'copy',
                    ];
                }
                return ['-map', '0:a', '-c:a', 'copy'];

            default:
                if ($hasCover) {
                    return ['-map', '0:a', '-map', '1:v', '-c', 'copy'];
                }
                return ['-map', '0:a', '-c:a', 'copy'];
        }
    }
}
