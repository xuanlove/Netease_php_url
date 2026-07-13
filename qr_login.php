<?php
/**
 * 网易云音乐二维码登录模块 (CLI)
 *
 * 提供网易云音乐二维码登录功能:
 * - 二维码生成和显示 (终端ASCII)
 * - 登录状态检查
 * - Cookie 获取和保存 (直接读写 cookie.txt)
 *
 * 用法:
 *   php qr_login.php login     # 执行二维码登录
 *   php qr_login.php status    # 显示登录状态
 *   php qr_login.php logout    # 登出 (清除Cookie)
 *   php qr_login.php help      # 显示帮助
 *
 * 注意: 此脚本仅可在CLI模式下运行
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/music_api.php';

// 仅CLI模式
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "此脚本仅可在命令行模式下运行\n");
    exit(1);
}

// 判断 cookie 是否"有效": 包含任一关键字段即可
$IMPORTANT_COOKIE_KEYS = ['MUSIC_U', '__csrf', 'NMTID'];

/**
 * 二维码登录客户端
 */
class QRLoginClient
{
    /** @var string Cookie保存文件路径 */
    private $cookieFile;

    /** @var MusicAPI */
    private $api;

    public function __construct(string $cookieFile = COOKIE_FILE)
    {
        $this->cookieFile = $cookieFile;
        $this->api = new MusicAPI();
    }

    // ==================== Cookie 文件操作 ====================

    /**
     * 读取Cookie
     */
    private function readCookie(): string
    {
        if (!file_exists($this->cookieFile)) {
            return '';
        }
        $content = file_get_contents($this->cookieFile);
        if ($content === false) {
            return '';
        }
        return trim($content);
    }

    /**
     * 写入Cookie
     */
    private function writeCookie(string $cookie): bool
    {
        if (empty(trim($cookie))) {
            fwrite(STDERR, "Cookie 内容不能为空\n");
            return false;
        }
        $result = file_put_contents($this->cookieFile, trim($cookie) . "\n");
        if ($result === false) {
            fwrite(STDERR, "写入 Cookie 失败\n");
            return false;
        }
        return true;
    }

    /**
     * 备份Cookie
     */
    private function backupCookie(string $suffix = 'backup'): ?string
    {
        if (!file_exists($this->cookieFile)) {
            return null;
        }
        $ts = date('Ymd_His');
        $backupPath = preg_replace('/\.txt$/', ".{$suffix}.{$ts}.txt", $this->cookieFile);
        if (copy($this->cookieFile, $backupPath)) {
            return $backupPath;
        }
        fwrite(STDERR, "备份 Cookie 失败\n");
        return null;
    }

    /**
     * 清除Cookie
     */
    private function clearCookie(): bool
    {
        if (!file_exists($this->cookieFile)) {
            return true;
        }
        return unlink($this->cookieFile);
    }

    /**
     * 检查Cookie是否有效
     */
    private function isCookieValid(): bool
    {
        global $IMPORTANT_COOKIE_KEYS;
        $cookies = MusicAPI::loadCookies($this->cookieFile);
        foreach ($IMPORTANT_COOKIE_KEYS as $key) {
            if (isset($cookies[$key])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取Cookie信息
     */
    private function getCookieInfo(): array
    {
        global $IMPORTANT_COOKIE_KEYS;
        $cookies = MusicAPI::loadCookies($this->cookieFile);
        $present = array_values(array_filter($IMPORTANT_COOKIE_KEYS, function($k) use ($cookies) {
            return isset($cookies[$k]);
        }));

        $info = [
            'file_path' => realpath($this->cookieFile) ?: $this->cookieFile,
            'file_exists' => file_exists($this->cookieFile),
            'cookie_count' => count($cookies),
            'is_valid' => !empty($present),
            'important_cookies_present' => $present,
            'missing_important_cookies' => array_values(array_filter($IMPORTANT_COOKIE_KEYS, function($k) use ($cookies) {
                return !isset($cookies[$k]);
            })),
        ];

        if (file_exists($this->cookieFile)) {
            $info['last_modified'] = date('Y-m-d H:i:s', filemtime($this->cookieFile));
        }

        return $info;
    }

    // ==================== 业务方法 ====================

    /**
     * 检查是否已有有效登录
     */
    public function checkExistingLogin(): bool
    {
        if ($this->isCookieValid()) {
            echo "检测到有效的登录 Cookie\n";
            return true;
        }
        echo "未检测到有效的登录 Cookie\n";
        return false;
    }

    /**
     * 交互式二维码登录
     *
     * @return array [success, errorMessage]
     */
    public function interactiveLogin(): array
    {
        try {
            echo "\n=== 网易云音乐二维码登录 ===\n";

            if ($this->checkExistingLogin()) {
                echo "是否重新登录？(y/N): ";
                $choice = trim(fgets(STDIN));
                if (strtolower($choice) !== 'y' && strtolower($choice) !== 'yes') {
                    echo "使用现有登录状态\n";
                    return [true, null];
                }
            }

            echo "\n开始二维码登录流程...\n";
            echo "正在生成二维码...\n";

            $qrResult = $this->api->createQrLogin();
            $unikey = $qrResult['unikey'];
            $qrUrl = $qrResult['qr_url'];

            echo "\n二维码已生成！\n";
            echo "请使用网易云音乐手机APP扫描以下链接进行登录:\n";
            echo "\n  {$qrUrl}\n";
            echo "\n二维码有效期: 3 分钟\n";
            echo "\n等待扫码中...\n";

            // 在终端显示二维码ASCII (如果可用)
            if ($this->canShowQrAscii()) {
                $this->printQrAscii($qrUrl);
            }

            $maxAttempts = 60; // 最多5分钟
            $attempt = 0;

            while ($attempt < $maxAttempts) {
                try {
                    $statusResult = $this->api->checkQrLogin($unikey);
                    $code = $statusResult['code'];

                    switch ($code) {
                        case 803:
                            // 登录成功
                            $cookie = $statusResult['cookie'];
                            if (!$cookie) {
                                return [false, '登录成功但未获取到 Cookie'];
                            }
                            if ($this->saveCookie($cookie)) {
                                echo "\n✅ 登录成功！Cookie 已保存\n";
                                return [true, null];
                            }
                            return [false, '登录成功但 Cookie 保存失败'];

                        case 801:
                            // 等待扫码
                            if ($attempt % 10 == 0) {
                                echo "等待扫码中... (" . ($attempt + 1) . "/{$maxAttempts})\n";
                            }
                            break;

                        case 802:
                            // 已扫码，等待确认
                            echo "二维码已扫描，请在手机上确认登录\n";
                            break;

                        case 800:
                            // 已过期
                            echo "\n❌ 二维码已过期，请重新尝试\n";
                            return [false, '二维码已过期'];

                        default:
                            $msg = $statusResult['message'];
                            echo "\n❌ 登录失败: {$msg}\n";
                            return [false, "登录失败: {$msg}"];
                    }

                    sleep(5);
                    $attempt++;

                } catch (Exception $e) {
                    fwrite(STDERR, "检查登录状态时发生错误: " . $e->getMessage() . "\n");
                    sleep(5);
                    $attempt++;
                }
            }

            echo "\n❌ 登录超时，请重新尝试\n";
            return [false, '登录超时'];

        } catch (APIException $e) {
            fwrite(STDERR, "API 调用失败: " . $e->getMessage() . "\n");
            return [false, "API 调用失败: " . $e->getMessage()];

        } catch (Exception $e) {
            fwrite(STDERR, "登录过程中发生未知错误: " . $e->getMessage() . "\n");
            return [false, "登录过程中发生未知错误: " . $e->getMessage()];
        }
    }

    /**
     * 保存Cookie到文件
     */
    public function saveCookie(string $cookie): bool
    {
        try {
            if (file_exists($this->cookieFile)) {
                $backup = $this->backupCookie();
                if ($backup) {
                    echo "已备份现有 Cookie 到: {$backup}\n";
                }
            }
            if (!$this->writeCookie($cookie)) {
                return false;
            }
            if ($this->isCookieValid()) {
                echo "Cookie 保存并验证成功\n";
                return true;
            }
            echo "Cookie 保存成功但验证失败（缺少关键字段）\n";
            return false;
        } catch (Exception $e) {
            fwrite(STDERR, "保存 Cookie 时发生错误: " . $e->getMessage() . "\n");
            return false;
        }
    }

    /**
     * 显示登录信息
     */
    public function showLoginInfo(): void
    {
        $info = $this->getCookieInfo();
        echo "\n=== 登录状态信息 ===\n";
        echo "Cookie 文件: {$info['file_path']}\n";
        echo "文件存在: " . ($info['file_exists'] ? '是' : '否') . "\n";
        echo "Cookie 数量: {$info['cookie_count']}\n";
        echo "登录状态: " . ($info['is_valid'] ? '有效' : '无效') . "\n";
        if (isset($info['last_modified'])) {
            echo "最后更新: {$info['last_modified']}\n";
        }
        if ($info['is_valid']) {
            echo "重要 Cookie: " . implode(', ', $info['important_cookies_present']) . "\n";
        } else {
            if (!empty($info['missing_important_cookies'])) {
                echo "缺少 Cookie: " . implode(', ', $info['missing_important_cookies']) . "\n";
            }
        }
    }

    /**
     * 登出 (清除Cookie)
     */
    public function logout(): bool
    {
        if (file_exists($this->cookieFile)) {
            $backup = $this->backupCookie('logout');
            if ($backup) {
                echo "Cookie 已备份到: {$backup}\n";
            }
        }
        if ($this->clearCookie()) {
            echo "已成功登出\n";
            return true;
        }
        echo "登出失败\n";
        return false;
    }

    /**
     * 检查是否能显示ASCII二维码
     */
    private function canShowQrAscii(): bool
    {
        // 检查是否有可用的二维码生成工具
        if (class_exists('QRCode')) {
            return true;
        }
        // 检查qrencode命令行工具
        $test = `which qrencode 2>/dev/null`;
        return !empty(trim($test));
    }

    /**
     * 在终端打印ASCII二维码
     */
    private function printQrAscii(string $url): void
    {
        // 尝试使用 qrencode 命令行工具
        $output = shell_exec("qrencode -t ANSI '{$url}' 2>/dev/null");
        if ($output) {
            echo "\n" . $output . "\n";
            return;
        }

        // 如果没有 qrencode，提示用户使用链接
        echo "(如需二维码图片，请安装 qrencode: brew install qrencode / apt install qrencode)\n";
        echo "(或直接复制上方链接到浏览器生成二维码)\n";
    }
}

// ==================== CLI入口 ====================

function printHelp(): void
{
    echo "网易云音乐二维码登录工具\n";
    echo "\n用法: php qr_login.php [命令]\n";
    echo "\n命令:\n";
    echo "  login   - 执行二维码登录\n";
    echo "  status  - 显示登录状态\n";
    echo "  logout  - 登出 (清除 Cookie)\n";
    echo "  help    - 显示此帮助信息\n";
    echo "\n如果不提供命令，将进入交互模式\n";
}

function main(): void
{
    $client = new QRLoginClient();

    if ($argc > 1) {
        $command = strtolower($argv[1]);
        switch ($command) {
            case 'login':
                [$success, $error] = $client->interactiveLogin();
                if ($success) {
                    echo "\n登录完成！\n";
                    $client->showLoginInfo();
                    exit(0);
                }
                fwrite(STDERR, "\n登录失败: {$error}\n");
                exit(1);

            case 'status':
            case 'info':
                $client->showLoginInfo();
                exit(0);

            case 'logout':
                exit($client->logout() ? 0 : 1);

            case 'help':
            case '-h':
            case '--help':
                printHelp();
                exit(0);

            default:
                fwrite(STDERR, "未知命令: {$command}\n");
                fwrite(STDERR, "使用 'php qr_login.php help' 查看帮助\n");
                exit(1);
        }
    }

    // 交互模式
    while (true) {
        echo "\n=== 网易云音乐登录工具 ===\n";
        echo "1. 二维码登录\n";
        echo "2. 查看登录状态\n";
        echo "3. 登出\n";
        echo "4. 退出\n";
        echo "\n请选择操作 (1-4): ";

        $choice = trim(fgets(STDIN));

        switch ($choice) {
            case '1':
                [$success, $error] = $client->interactiveLogin();
                if ($success) {
                    echo "\n登录成功！\n";
                    $client->showLoginInfo();
                } else {
                    fwrite(STDERR, "\n登录失败: {$error}\n");
                }
                break;

            case '2':
                $client->showLoginInfo();
                break;

            case '3':
                $client->logout();
                break;

            case '4':
                echo "再见！\n";
                exit(0);

            default:
                echo "无效选择，请重试\n";
        }
    }
}

main();
