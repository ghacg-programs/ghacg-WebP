# GHACG Image WebP

一个在 WordPress 保存上传文件前将图片统一转换为 WebP 的插件，可与 S3 Uploads 等远程存储插件一起使用。

## 功能

- 静态图片输出 WebP；多帧图片输出 Animated WebP；支持 RGBA。
- 自动移除 EXIF、XMP、IPTC 等元数据。
- 可配置 WebP 质量
- 可配置最大分辨率（长边最大像素），超出此分辨率图片将进行等比例缩放。
- 注意：WordPress 7.1 客户端媒体处理会被关闭，统一使用此插件提供的服务端处理规则。
- 经测试，该插件与 S3 Uploads 协作良好。

## 环境要求

- WordPress 6.5+
- PHP 7.4+
- PHP Imagick 扩展及 ImageMagick

## 安装

### 作为普通插件安装

1. 从 GitHub 下载仓库 ZIP 并解压。
2. 将包含 `ghacg-image-webp.php` 和 `ghacg-image-webp/` 目录的插件目录放入 `wp-content/plugins/`。
3. 在 WordPress 后台“插件”页面启用 **GHACG Image WebP**。

### 作为 Must-Use 插件安装（推荐）

将 `ghacg-image-webp.php` 放入 `wp-content/mu-plugins/` 根目录（如果没有，请创建），并将 `ghacg-image-webp/` 子目录一并放入同一位置。
MU 插件会自动加载，不需要在后台启用。

两种安装方式只能选一种，避免转换器重复注册。

## 设置与使用

安装后前往“设置 → 图片优化”。默认设置如下：

| 设置 | 默认值 | 说明 |
| --- | --- | --- |
| WebP 有损质量 | 82 | 范围 1–100；这不是压缩比，100 也不是无损 |
| 长边尺寸限制 | 开启 | 超出设定值时按比例缩小 |
| 最大长边 | 2560 px | 只限制超长图片，不放大较小图片 |
| BMP / TIFF / HEIC / HEIF | 关闭 | 仅在服务器具备对应解码器时可开启 |

JPEG、PNG、GIF、AVIF 和 WebP 默认启用。插件根据解码结果保留透明通道；多帧图片生成 Animated WebP。已是 WebP 且不超出尺寸限制的文件会原样保留，避免再次有损编码。

## 处理流程与存储插件

转换发生在 WordPress 的上传和 sideload prefilter 阶段。插件只读写 PHP 上传临时文件，完成后更新上传文件名、MIME 和大小，再把处理结果交给 WordPress。WordPress 后续继续创建 attachment 和生成子尺寸；S3 Uploads 等存储插件可按原有流程接管原图及子图。

插件关闭 WordPress 7.1 的 `wp_client_side_media_processing_enabled`，让服务端成为图片转换的统一处理路径。子比等主题的上传重命名发生在转换之后，`.webp` 扩展名会随最终文件名保留。

## 已知限制

- 图片解码和转换依赖服务器的 Imagick/ImageMagick coder，且必须支持 WebP 编码器及目标格式解码器。可在设置页查看实际支持能力。
- WordPress 7.1 的浏览器端压缩、缩放和缩略图生成会被关闭，无法通过前端生成缩略图。
- 资源消耗受 PHP 与 ImageMagick 自身的内存、时间和 policy 限制。
- Animated WebP 在过旧的浏览器中可能无法播放。
- 不保留原始 JPEG/PNG/GIF 等文件。
- 插件不负责重新处理媒体库中已有的图片，也不负责修改主题的图片显示、缓存、CDN 或对象存储策略。

## 验证

在具有 PHP CLI、Imagick 和相应 ImageMagick 编解码器的环境中运行：

```sh
php tests/smoke.php
```

测试覆盖 JPEG、透明 PNG、静态/动画 GIF、AVIF、已有 WebP、超长边缩放和高级格式开关。BMP、TIFF、HEIC/HEIF 与 AVIF 样本会在本机缺少对应编码器时跳过；生产服务器上的真实支持状态以 WordPress 设置页及实际上传为准。

## 许可证

GPL-2.0-or-later，见仓库中的 [LICENSE](LICENSE)。
