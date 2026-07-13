<div align="center">

# 网易云音乐解析 (PHP)

**功能强大的网易云音乐解析工具 - PHP 版**

支持歌曲搜索 · 单曲解析 · 歌单解析 · 专辑解析 · 音乐下载（含元数据与歌词写入） · 扫码登录

[功能特性](#-功能特性) ·
[快速开始](#-快速开始) ·
[API 文档](#-api-接口文档) ·
[部署指南](#-部署方式) ·
[常见问题](#-常见问题)

</div>

---

## 📖 项目简介

基于 [Suxiaoqinx/Netease_url](https://github.com/Suxiaoqinx/Netease_url) Python 项目的 PHP 移植版本，提供完整的网易云音乐解析与下载服务。

纯 PHP 实现，无依赖框架，支持 PHP 内置服务器、Apache、Nginx 三种部署方式。下载时自动写入 ID3/VorbisComment 元数据、专辑封面与歌词（LRC），无需外部工具即可工作。

## ✨ 功能特性

### 🎵 核心功能

| 功能 | 说明 |
|---|---|
| 🔍 **歌曲搜索** | 关键词搜索网易云音乐库，支持设置返回数量 |
| 🎧 **单曲解析** | 解析单首歌曲的详细信息、播放链接、歌词 |
| 📋 **歌单解析** | 批量解析歌单中所有歌曲 |
| 💿 **专辑解析** | 批量解析专辑中所有歌曲 |
| ⬇️ **音乐下载** | 多音质下载，自动写入元数据 + 封面 + 歌词 |
| 📱 **扫码登录** | 二维码扫码自动获取 Cookie |
| 🎤 **歌词写入** | MP3 写入 USLT 帧，FLAC 写入 LYRICS/UNSYNCEDLYRICS 字段 |

### 🎼 音质支持

| 参数 | 说明 | 码率/规格 | 权限 |
|---|---|---|---|
| `standard` | 标准音质 | 128 kbps | 普通 |
| `exhigh` | 极高音质 | 320 kbps | 黑胶 VIP |
| `lossless` | 无损音质 | FLAC | 黑胶 VIP |
| `hires` | Hi-Res 音质 | 24bit / 96kHz | 黑胶 VIP |
| `jyeffect` | 高清环绕声 | - | 黑胶 VIP |
| `sky` | 沉浸环绕声 | - | 黑胶 SVIP |
| `jymaster` | 超清母带 | - | 黑胶 SVIP |
| `dolby` | 杜比全景声 | EAC3 | 黑胶 SVIP |

### 📝 元数据与歌词写入

下载时自动写入以下信息（无需手动操作）：

| 字段 | MP3 (ID3v2.3) | FLAC (VorbisComment) |
|---|---|---|
| 标题 | `TIT2` | `TITLE` |
| 艺术家 | `TPE1` / `TPE2` | `ARTIST` / `ALBUMARTIST` |
| 专辑 | `TALB` | `ALBUM` |
| 音轨号 | `TRCK` | `TRACKNUMBER` |
| 备注 | `COMM` | `COMMENT` |
| 封面 | `APIC` | `PICTURE` 块 |
| 歌词（原文） | `USLT` (UTF-16) | `LYRICS` + `UNSYNCEDLYRICS` |
| 歌词（翻译） | `USLT` (description=翻译) | `TRANSLATEDLYRICS` |

歌词为网易云返回的 LRC 格式文本，包含时间戳。

## 🚀 快速开始

### 1. 环境检查

```bash
# 需要 PHP ≥ 7.4，推荐 8.0+
php -v

# 检查必需扩展（curl / openssl / fileinfo）
php -r "foreach(['curl','openssl','fileinfo'] as \$e) echo \$e.': '.(extension_loaded(\$e)?'OK':'MISSING').PHP_EOL;"
```

### 2. 获取代码

```bash
git clone https://github.com/yourname/Netease_php_url.git
cd Netease_php_url
```

### 3. 配置 Cookie

<details>
<summary><b>方式 A：手动填入 Cookie（点击展开）</b></summary>

在 [cookie.txt](cookie.txt) 中填入网易云音乐黑胶会员账号的 Cookie：

```
MUSIC_U=your_music_u_value; __csrf=your_csrf; NMTID=your_nmtid;
```

**获取 Cookie 步骤**：
1. 登录 [网易云音乐网页版](https://music.163.com)
2. 按 F12 打开开发者工具 → Network 标签页
3. 复制任意请求的 Cookie 值，粘贴到 `cookie.txt`

</details>

<details>
<summary><b>方式 B：扫码登录（推荐，点击展开）</b></summary>

```bash
php qr_login.php login
```

按提示使用网易云音乐 APP 扫码登录，Cookie 自动保存到 `cookie.txt`。

</details>

### 4. 启动服务

```bash
# PHP 内置服务器（开发推荐）
php -d extension=curl -d extension=openssl -S 0.0.0.0:5000 index.php
```

### 5. 访问服务

打开浏览器访问 **http://localhost:5000/**

## 📦 部署方式

### 方式 A：PHP 内置服务器（开发环境）

```bash
php -d extension=curl -d extension=openssl -S 0.0.0.0:5000 index.php
```

**优点**：零配置，开箱即用
**适用**：本地开发、测试

### 方式 B：Apache（生产环境）

1. 将项目放入 Apache Web 目录（如 `/var/www/Netease_php_url`）
2. 确保 `mod_rewrite` 已启用：
   ```bash
   sudo a2enmod rewrite
   sudo systemctl restart apache2
   ```
3. 项目根目录的 [.htaccess](.htaccess) 会自动生效

### 方式 C：Nginx + php-fpm（生产环境）

1. 参考 [Nginx_Rewrite.txt](Nginx_Rewrite.txt) 创建站点配置：
   ```bash
   sudo cp Nginx_Rewrite.txt /etc/nginx/sites-available/netease
   sudo ln -s /etc/nginx/sites-available/netease /etc/nginx/sites-enabled/
   ```
2. 修改配置文件中的 `root` 路径和 `server_name`
3. 测试并重载：
   ```bash
   sudo nginx -t && sudo nginx -s reload
   ```

### 方式 D：Docker 部署

```dockerfile
FROM php:8.2-apache
RUN apt-get update && apt-get install -y ffmpeg libcurl4-openssl-dev libssl-dev
RUN docker-php-ext-install curl openssl fileinfo
RUN a2enmod rewrite
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
```

```bash
docker build -t netease-php .
docker run -d -p 5000:80 --name netease netease-php
```

## 📁 项目结构

```
Netease_php_url/
├── index.php              # 主入口，路由分发与 API 处理
├── config.php             # 配置文件（API 地址、密钥、音质、ffmpeg 路径）
├── music_api.php          # API 类（EAPI 加密、HTTP、搜索、歌曲、歌单、专辑、歌词、二维码）
├── music_downloader.php   # 下载器类（同步下载、双模式元数据写入、歌词写入）
├── qr_login.php           # 二维码登录 CLI 工具
├── cookie.txt             # Cookie 配置文件
├── .htaccess              # Apache 重写与敏感文件保护
├── Nginx_Rewrite.txt      # Nginx 站点配置示例
├── templates/
│   └── index.html         # Web 操作界面
├── libs/getid3/           # getID3 纯 PHP 库（元数据写入，无需 ffmpeg）
└── downloads/             # 下载文件目录（自动创建，禁止直接访问）
```

## 🌐 Web 界面使用

### 🔍 歌曲搜索
1. 功能选择：**歌曲搜索**
2. 输入关键词，设置返回数量
3. 点击 **搜索**
4. 在结果中点击 **解析** 或 **下载**

### 🎧 单曲解析
1. 功能选择：**单曲解析**
2. 输入歌曲 ID 或链接
3. 选择音质，点击 **解析**
4. 查看歌曲信息、歌词、在线试听（APlayer 播放器）
5. 点击 **点击下载** 直接下载（自动重命名为 `歌手-歌名-音质.格式`）

### 📋 歌单 / 专辑解析
1. 功能选择：**歌单解析** 或 **专辑解析**
2. 输入 ID 或链接
3. 点击解析，查看全部曲目
4. 对单首歌曲点击 **解析** 或 **下载**

### ⬇️ 音乐下载
1. 功能选择：**音乐下载**
2. 输入音乐 ID 或链接
3. 选择音质，点击 **下载音乐**
4. 文件自动下载到本地（含元数据、封面、歌词）

### 📱 扫码登录
1. 功能选择：**扫码登录**
2. 点击 **生成二维码**
3. 使用网易云音乐 APP 扫码
4. 手机确认后 Cookie 自动保存到服务器

## 🔌 API 接口文档

### 基础信息

| 项目 | 值 |
|---|---|
| Base URL | `http://localhost:5000` |
| 请求方式 | GET / POST |
| 响应格式 | JSON |

### 统一响应格式

```json
{
  "status": 200,
  "success": true,
  "message": "操作描述",
  "data": { ... }
}
```

错误时：
```json
{
  "status": 400,
  "success": false,
  "message": "错误描述"
}
```

---

### 1️⃣ 健康检查

```
GET /health
```

```bash
curl http://localhost:5000/health
```

<details>
<summary>响应示例</summary>

```json
{
  "status": 200,
  "success": true,
  "message": "API服务运行正常",
  "data": {
    "service": "running",
    "timestamp": 1783907849,
    "cookie_status": "valid",
    "cookie_count": 3,
    "downloads_dir": "/var/www/Netease_php_url/downloads",
    "metadata": {
      "engine": "getID3",
      "fallback": null,
      "ffmpeg": { "available": false, "path": null, "version": null },
      "getid3": { "available": true }
    },
    "version": "2.0.0-php"
  }
}
```
</details>

---

### 2️⃣ 歌曲搜索

```
POST /search
```

| 参数 | 类型 | 必填 | 默认 | 说明 |
|---|---|---|---|---|
| keyword | string | ✅ | - | 搜索关键词 |
| limit | int | ❌ | 30 | 返回数量（最大 100） |

```bash
curl -X POST http://localhost:5000/search \
  -d "keyword=周杰伦 稻香&limit=10"
```

<details>
<summary>响应示例</summary>

```json
{
  "status": 200,
  "success": true,
  "message": "搜索完成",
  "data": [
    {
      "id": 185668,
      "name": "稻香",
      "artists": "周杰伦",
      "album": "魔杰座",
      "picUrl": "https://p3.music.126.net/..."
    }
  ]
}
```
</details>

---

### 3️⃣ 单曲解析

```
POST /song
```

| 参数 | 类型 | 必填 | 默认 | 说明 |
|---|---|---|---|---|
| id | string | ✅ | - | 歌曲 ID 或链接 |
| level | string | ❌ | `lossless` | 音质等级 |
| type | string | ❌ | `url` | 返回类型 |

**type 取值**：

| 值 | 说明 |
|---|---|
| `url` | 仅返回播放链接 |
| `name` | 返回歌曲详情 |
| `lyric` | 返回歌词 |
| `json` | 完整信息（播放链接 + 详情 + 歌词） |

```bash
# 获取完整信息
curl -X POST http://localhost:5000/song \
  -d "id=185668&level=lossless&type=json"
```

<details>
<summary>响应示例（type=json）</summary>

```json
{
  "status": 200,
  "success": true,
  "message": "获取歌曲信息成功",
  "data": {
    "id": "185668",
    "name": "烟花易冷",
    "ar_name": "周杰伦",
    "al_name": "跨时代",
    "pic": "https://p2.music.126.net/...",
    "level": "lossless",
    "url": "https://...",
    "size": "28.44MB",
    "lyric": "[00:00.000] 作词 : ...",
    "tlyric": ""
  }
}
```
</details>

---

### 4️⃣ 歌单解析

```
POST /playlist
```

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| id | string | ✅ | 歌单 ID 或链接 |

```bash
curl -X POST http://localhost:5000/playlist \
  -d "id=123456789"
```

---

### 5️⃣ 专辑解析

```
POST /album
```

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| id | string | ✅ | 专辑 ID 或链接 |

```bash
curl -X POST http://localhost:5000/album \
  -d "id=123456789"
```

---

### 6️⃣ 音乐下载

```
POST /download
```

| 参数 | 类型 | 必填 | 默认 | 说明 |
|---|---|---|---|---|
| id | string | ✅ | - | 音乐 ID 或链接 |
| quality | string | ❌ | `lossless` | 音质等级 |
| format | string | ❌ | `file` | 返回格式：`file` / `json` |
| force | int | ❌ | `0` | 1 = 强制重新下载并写入元数据 |

```bash
# 直接下载文件
curl -X POST http://localhost:5000/download \
  -d "id=185668&quality=lossless" \
  -o "song.flac"

# 仅获取文件信息（不下载实际文件）
curl -X POST http://localhost:5000/download \
  -d "id=185668&quality=lossless&format=json"

# 强制重新下载并写入元数据（忽略已存在文件）
curl -X POST http://localhost:5000/download \
  -d "id=185668&quality=lossless&force=1"
```

<details>
<summary>响应说明</summary>

**format=file**：直接返回音频文件流，响应头包含：
- `Content-Type: audio/flac`
- `Content-Disposition: attachment; filename="..."`
- `X-Download-Filename: URL 编码的文件名`
- `X-Download-Message: Download completed successfully`

**format=json**：返回文件元数据，包含 `metadata_written`（true=成功 / false=失败 / null=跳过）和 `metadata_status` 字段。

**force 参数**：默认情况下，已存在的文件会直接返回（跳过元数据写入）。设置 `force=1` 会删除已存在文件并重新下载+写入元数据。
</details>

---

### 7️⃣ 二维码登录

**生成二维码**：

```
GET /qr/key
```

```bash
curl http://localhost:5000/qr/key
```

响应：
```json
{
  "status": 200,
  "success": true,
  "message": "二维码生成成功",
  "data": {
    "unikey": "8f604271-...",
    "qr_url": "https://music.163.com/login?codekey=...",
    "expire_time": 180
  }
}
```

**检查扫码状态**：

```
GET /qr/status?key=<unikey>
```

```bash
curl "http://localhost:5000/qr/status?key=8f604271-..."
```

**状态码说明**：

| code | status | 说明 |
|---|---|---|
| 801 | `waiting` | 等待扫码 |
| 802 | `scanned` | 已扫码，等待手机确认 |
| 803 | `success` | 登录成功，Cookie 已保存 |
| 800 | `expired` | 二维码已过期 |

---

### 8️⃣ API 信息

```
GET /api/info
```

## 💻 CLI 工具

`qr_login.php` 提供命令行扫码登录功能：

```bash
# 扫码登录（推荐）
php qr_login.php login

# 查看登录状态
php qr_login.php status

# 登出（清除 Cookie，自动备份）
php qr_login.php logout

# 显示帮助
php qr_login.php help
```

不带参数进入交互模式：
```
=== 网易云音乐登录工具 ===
1. 二维码登录
2. 查看登录状态
3. 登出
4. 退出
请选择操作 (1-4):
```

## 🔗 支持的链接格式

```
# 歌曲链接
https://music.163.com/song?id=1234567890
https://music.163.com/#/song?id=1234567890

# 歌单链接
https://music.163.com/playlist?id=1234567890
https://music.163.com/#/playlist?id=1234567890

# 专辑链接
https://music.163.com/album?id=1234567890
https://music.163.com/#/album?id=1234567890

# 短链接（自动解析重定向）
https://163cn.tv/xxxxx

# 直接使用 ID
1234567890
```

## ⚙️ 配置说明

主配置文件 [config.php](config.php)：

| 常量 | 默认值 | 说明 |
|---|---|---|
| `NETEASE_PORT` | `5000` | 服务监听端口 |
| `DOWNLOADS_DIR` | `./downloads` | 下载目录 |
| `COOKIE_FILE` | `./cookie.txt` | Cookie 文件路径 |
| `MAX_FILE_SIZE` | `500MB` | 最大文件大小限制 |
| `CORS_ORIGINS` | `*` | CORS 跨域来源 |
| `FFMPEG_PATH` | 环境变量 | ffmpeg 路径，留空自动检测；不可用降级 getID3 |
| `QUALITY_LEVELS` | 8 种音质 | 支持的音质等级列表 |

## 🎵 元数据写入（双模式：ffmpeg 优先，降级 getID3）

本程序采用 **双模式架构** 写入音频元数据/封面/歌词，确保在各种环境都能正常工作：

| 模式 | 引擎 | 说明 | 支持格式 |
|---|---|---|---|
| **主模式** | ffmpeg | 命令行工具，支持全格式 | MP3/FLAC/M4A/MP4/OGG/Opus |
| **降级模式** | getID3 | 纯 PHP 库，无需外部依赖 | MP3/FLAC/OGG/Opus |

**工作流程**：
1. 启动时检测 ffmpeg（优先 `FFMPEG_PATH` 常量/环境变量，其次 PATH 查找）
2. 下载完成后优先调用 ffmpeg 写入元数据
3. ffmpeg 不可用或写入失败时，自动降级到 getID3 纯 PHP 库
4. 前端在单曲解析和音乐下载界面实时显示当前使用的引擎

**ffmpeg 模式的歌词处理**：
- FLAC：直接通过 `-metadata lyrics=` 写入
- MP3：ffmpeg 不支持 USLT 帧，写入后调用 getID3 补写歌词

**getID3 模式的歌词处理**：
- MP3：使用 ID3v2.3 USLT 帧（UTF-16 with BOM 编码）
- FLAC：使用 VORBIS_COMMENT 的 `LYRICS` + `UNSYNCEDLYRICS` + `TRANSLATEDLYRICS` 字段
- FLAC 写入使用纯 PHP 实现（流式 I/O），不依赖 getID3 的 metaflac 外部工具

**安装 ffmpeg（可选，获得最佳兼容性）**：

```bash
# Ubuntu / Debian
sudo apt install ffmpeg

# CentOS / RHEL (需 EPEL)
sudo yum install ffmpeg

# macOS
brew install ffmpeg

# Windows
# 下载 https://ffmpeg.org/download.html 并添加到 PATH
```

**指定 ffmpeg 路径**（环境变量，可选）：

```bash
# Linux / macOS
export FFMPEG_PATH=/usr/local/bin/ffmpeg

# Windows
set FFMPEG_PATH=C:\ffmpeg\bin\ffmpeg.exe
```

> 即使不安装 ffmpeg，程序也会自动使用内置的 getID3 纯 PHP 库写入元数据和歌词，无需任何额外配置。

## 🔒 安全防护

### 敏感文件保护

以下文件禁止通过 Web 直接访问（返回 403）：

| 文件 | 说明 |
|---|---|
| `cookie.txt` | Cookie 凭据 |
| `config.php` | 配置文件 |
| `music_api.php` | API 核心代码 |
| `music_downloader.php` | 下载器代码 |
| `qr_login.php` | CLI 工具 |
| `.env` / `.htaccess` / `.htpasswd` | 配置文件 |
| `downloads/` 目录 | 下载文件（仅可通过 API 访问） |

**三层防护**：

| Web 服务器 | 实现方式 |
|---|---|
| PHP 内置服务器 | `index.php` 中正则匹配拦截 |
| Apache | `.htaccess` 的 `<FilesMatch>` 规则 |
| Nginx | `Nginx_Rewrite.txt` 的 `location` 规则 |

### CORS 配置

默认 `*` 允许所有来源跨域。生产环境建议限制为特定域名：

```php
// config.php
define('CORS_ORIGINS', 'https://yourdomain.com');
```

## 🔧 技术实现

### EAPI 加密算法

网易云接口使用 EAPI 加密：

1. URL 路径 `/eapi/` 替换为 `/api/`
2. 计算摘要 `MD5("nobody{path}use{payload}md5forencrypt")`
3. 拼接参数 `{path}-36cd479b6b5-{payload}-36cd479b6b5-{digest}`
4. AES-128-ECB 加密（PKCS7 填充）
5. 转换为十六进制字符串

PHP 实现使用 `openssl_encrypt()` + `AES-128-ECB`。

### 图片 ID 加密算法

网易云图片直链需对图片 ID 特殊加密：

1. 使用魔数串 `3go8&$8*3*3h0k(2)2` 与 ID 逐字符 XOR
2. 计算 MD5（返回原始二进制）
3. Base64 编码，替换为 URL 安全字符（`/` → `_`，`+` → `-`）

### Cookie 管理

- 支持标准格式 `k1=v1; k2=v2`
- 支持换行分隔
- 自动跳过 `#` 开头的注释行
- 有效性判断：检查 `MUSIC_U` / `__csrf` / `NMTID` 任一字段存在

### 下载文件命名规则

下载文件统一命名为 `歌手-歌名-音质.格式`，例如：

```
周杰伦-屋顶-lossless.flac
周杰伦/温岚/吴宗宪-屋顶-standard.mp3
```

- 文件名中的非法字符（`<>:"/\|?*`）会被替换为 `_`
- 文件名最大长度 200 字符
- 扩展名优先使用 API 返回的 `type` 字段，URL 后缀兜底

## ❓ 常见问题

<details>
<summary><b>Q：获取播放链接返回 <code>url: null</code> 或 <code>size: 0</code>？</b></summary>

原因：
1. Cookie 未配置或已失效 → 执行 `php qr_login.php login` 重新登录
2. 音质需要更高 VIP 等级 → 尝试降低音质（如 `exhigh`）
3. 歌曲版权限制 → 换其他歌曲

> 空 cookie.txt 会导致网易云 API 返回 null URL 和 0 size，下载文件会显示 0B。

</details>

<details>
<summary><b>Q：提示 <code>Call to undefined function curl_init()</code>？</b></summary>

PHP 未启用 `curl` 扩展：

```bash
# 方式 1：修改 php.ini，取消注释
extension=curl
extension=openssl

# 方式 2：启动时临时加载
php -d extension=curl -d extension=openssl -S 0.0.0.0:5000 index.php
```
</details>

<details>
<summary><b>Q：下载的文件没有封面/元数据/歌词？</b></summary>

程序采用双模式写入元数据：优先使用 ffmpeg，不可用时降级到内置的 getID3 纯 PHP 库。

1. **前端提示检查**：单曲解析和音乐下载界面顶部会显示当前元数据引擎状态
2. **安装 ffmpeg**（推荐，支持全格式含 M4A/MP4）：

```bash
# Linux
sudo apt install ffmpeg

# macOS
brew install ffmpeg

# Windows: 下载 https://ffmpeg.org/download.html 并添加到 PATH
```

3. **或确保 getID3 库完整**（纯 PHP，无需安装，支持 MP3/FLAC/OGG/Opus）：

```bash
git clone https://github.com/JamesHeinrich/getID3.git libs/getid3
rm -rf libs/getid3/.git
```

4. **虚拟主机注意**：若 `shell_exec`/`exec` 被禁用，ffmpeg 不可用，程序会自动降级到 getID3

5. **文件已存在跳过元数据写入**：默认情况下已存在的文件会直接返回，跳过元数据写入。使用 `force=1` 参数强制重新下载并写入元数据。

</details>

<details>
<summary><b>Q：如何修改监听端口？</b></summary>

```bash
# 方式 1：启动时指定
php -S 0.0.0.0:8080 index.php

# 方式 2：修改 config.php
define('NETEASE_PORT', 8080);
```
</details>

<details>
<summary><b>Q：服务重启后 Cookie 丢失？</b></summary>

不会丢失。扫码登录后 Cookie 写入 `cookie.txt` 文件持久保存，重启服务不影响。若 Cookie 失效，重新执行 `php qr_login.php login`。
</details>

<details>
<summary><b>Q：cookie.txt 中可以写注释吗？</b></summary>

可以。以 `#` 开头的行会被自动跳过：

```
# 这是注释，不会被解析
MUSIC_U=xxx; __csrf=yyy;  # 行内注释不会被识别，请整行写
NMTID=zzz;
```
</details>

## 📋 API 端点速查表

| 端点 | 方法 | 说明 |
|---|---|---|
| `/` | GET | Web 界面 |
| `/health` | GET | 健康检查（含元数据引擎状态） |
| `/search` | GET/POST | 歌曲搜索 |
| `/song` | GET/POST | 单曲解析（支持 url/name/lyric/json） |
| `/playlist` | GET/POST | 歌单解析 |
| `/album` | GET/POST | 专辑解析 |
| `/download` | GET/POST | 音乐下载（支持 file/json 格式，force 参数） |
| `/qr/key` | GET | 生成二维码 |
| `/qr/status` | GET | 检查扫码状态 |
| `/api/info` | GET | API 信息 |

## 🙏 致谢

- 原项目作者：[Suxiaoqinx](https://github.com/Suxiaoqinx) - [Netease_url](https://github.com/Suxiaoqinx/Netease_url)
- [Ravizhan](https://github.com/ravizhan)
- [getID3](https://github.com/JamesHeinrich/getID3) - 纯 PHP 元数据读写库

## 📄 许可证

[MIT License](LICENSE)

本项目旨在为开源社区做贡献，鼓励用户在遵守开源精神的前提下使用和分享代码。虽然 MIT 许可证允许商业使用，但希望用户能尊重开源精神，合理使用本项目。
