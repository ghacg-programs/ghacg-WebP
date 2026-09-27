<?php
/**
 * Upload normalization. This class never reads or writes the WordPress upload directory.
 * Copyright (C) 2026 GHACG Programs
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

final class GHACG_Image_WebP {
    private const OPTION = 'ghacg_image_webp_settings';

    private const DEFAULTS = array(
        'quality'       => 82,
        'limit_enabled' => 1,
        'max_dimension' => 2560,
        'allow_bmp'     => 0,
        'allow_tiff'    => 0,
        'allow_heic'    => 0,
    );

    private const INPUT_FORMATS = array(
        'JPEG' => 'image/jpeg',
        'PNG'  => 'image/png',
        'GIF'  => 'image/gif',
        'AVIF' => 'image/avif',
        'WEBP' => 'image/webp',
        'BMP'  => 'image/bmp',
        'TIFF' => 'image/tiff',
        'HEIC' => 'image/heic',
        'HEIF' => 'image/heif',
    );

    private const MIME_FORMATS = array(
        'image/jpeg'          => 'JPEG',
        'image/png'           => 'PNG',
        'image/gif'           => 'GIF',
        'image/avif'          => 'AVIF',
        'image/webp'          => 'WEBP',
        'image/bmp'           => 'BMP',
        'image/x-ms-bmp'      => 'BMP',
        'image/tiff'          => 'TIFF',
        'image/x-tiff'        => 'TIFF',
        'image/heic'          => 'HEIC',
        'image/heic-sequence' => 'HEIC',
        'image/heif'          => 'HEIF',
        'image/heif-sequence' => 'HEIF',
    );

    private static $formats;
    private static $webp_encoder_available;
    private static $animated_webp_available;

    public static function init(): void {
        add_filter( 'wp_client_side_media_processing_enabled', '__return_false' );
        add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'filter_upload' ), 1 );
        add_filter( 'wp_handle_sideload_prefilter', array( __CLASS__, 'filter_upload' ), 1 );
        add_filter( 'upload_mimes', array( __CLASS__, 'upload_mimes' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    }

    private static function settings(): array {
        $saved = get_option( self::OPTION, array() );
        return array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );
    }

    private static function formats(): array {
        if ( null === self::$formats ) {
            self::$formats = class_exists( 'Imagick' ) ? array_fill_keys( Imagick::queryFormats(), true ) : array();
        }
        return self::$formats;
    }

    private static function can_decode( string $format ): bool {
        return isset( self::formats()[ $format ] );
    }

    private static function can_encode_webp(): bool {
        if ( null !== self::$webp_encoder_available ) {
            return self::$webp_encoder_available;
        }
        self::$webp_encoder_available = false;
        if ( ! self::can_decode( 'WEBP' ) ) {
            return false;
        }
        $path = tempnam( sys_get_temp_dir(), 'ghacg-webp-check-' );
        if ( false === $path ) {
            return false;
        }
        $image = new Imagick();
        try {
            $image->newImage( 1, 1, new ImagickPixel( 'red' ) );
            $image->setImageFormat( 'WEBP' );
            if ( $image->writeImage( $path ) ) {
                $check = new Imagick( $path );
                self::$webp_encoder_available = 'WEBP' === $check->getImageFormat();
                $check->clear();
            }
        } catch ( Throwable $error ) {
            self::$webp_encoder_available = false;
        } finally {
            $image->clear();
            @unlink( $path );
        }
        return self::$webp_encoder_available;
    }

    private static function can_encode_animated_webp(): bool {
        if ( null !== self::$animated_webp_available ) {
            return self::$animated_webp_available;
        }
        self::$animated_webp_available = false;
        if ( ! self::can_encode_webp() ) {
            return false;
        }

        $path = tempnam( sys_get_temp_dir(), 'ghacg-webp-check-' );
        if ( false === $path ) {
            return false;
        }
        $animation = new Imagick();
        try {
            foreach ( array( 'red', 'blue' ) as $color ) {
                $frame = new Imagick();
                $frame->newImage( 1, 1, new ImagickPixel( $color ) );
                $frame->setImageFormat( 'WEBP' );
                $frame->setImageDelay( 10 );
                $animation->addImage( $frame );
                $frame->clear();
            }
            if ( $animation->writeImages( $path, true ) ) {
                $check = new Imagick( $path );
                self::$animated_webp_available = 2 === $check->getNumberImages();
                $check->clear();
            }
        } catch ( Throwable $error ) {
            self::$animated_webp_available = false;
        } finally {
            $animation->clear();
            @unlink( $path );
        }
        return self::$animated_webp_available;
    }

    private static function enabled( string $format, array $settings ): bool {
        if ( in_array( $format, array( 'JPEG', 'PNG', 'GIF', 'AVIF', 'WEBP' ), true ) ) {
            return true;
        }
        if ( 'BMP' === $format ) {
            return ! empty( $settings['allow_bmp'] );
        }
        if ( 'TIFF' === $format ) {
            return ! empty( $settings['allow_tiff'] );
        }
        return in_array( $format, array( 'HEIC', 'HEIF' ), true ) && ! empty( $settings['allow_heic'] );
    }

    /**
     * The browser's MIME and filename are only hints. Imagick must identify the bytes.
     */
    public static function filter_upload( array $file ): array {
        if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_file( $file['tmp_name'] ) ) {
            return $file;
        }

        if ( class_exists( 'finfo' ) ) {
            $finfo = new finfo( FILEINFO_MIME_TYPE );
            $detected_mime = $finfo->file( $file['tmp_name'] );
        } else {
            $detected_mime = wp_get_image_mime( $file['tmp_name'] );
        }
        if ( ! is_string( $detected_mime ) || 0 !== strpos( $detected_mime, 'image/' ) ) {
            return $file;
        }

        // Do not ask ImageMagick to parse SVG or any other unlisted image type.
        if ( ! isset( self::MIME_FORMATS[ $detected_mime ] ) ) {
            $file['error'] = __( '不支持此图片格式，请上传 JPEG、PNG、GIF、AVIF 或 WebP。', 'ghacg-image-webp' );
            return $file;
        }

        $mime_format = self::MIME_FORMATS[ $detected_mime ];
        $settings = self::settings();
        if ( ! self::enabled( $mime_format, $settings ) ) {
            $file['error'] = sprintf( __( '当前不允许上传 %s 图片。', 'ghacg-image-webp' ), $mime_format );
            return $file;
        }

        if ( ! class_exists( 'Imagick' ) || ! self::can_encode_webp() ) {
            $file['error'] = __( '服务器当前无法生成 WebP 图片，请联系站点管理员。', 'ghacg-image-webp' );
            return $file;
        }
        if ( ! self::can_decode( $mime_format ) ) {
            $file['error'] = self::unsupported_message( $mime_format );
            return $file;
        }

        $image = null;
        $output = false;

        try {
            $image = new Imagick();
            $image->readImage( $file['tmp_name'] );
            $image->setIteratorIndex( 0 );
            $format = strtoupper( $image->getImageFormat() );
            if ( 'JPG' === $format || 'JPE' === $format ) {
                $format = 'JPEG';
            } elseif ( 0 === strpos( $format, 'PNG' ) ) {
                $format = 'PNG';
            } elseif ( 0 === strpos( $format, 'BMP' ) ) {
                $format = 'BMP';
            } elseif ( 0 === strpos( $format, 'TIFF' ) ) {
                $format = 'TIFF';
            } elseif ( 'GIF87' === $format ) {
                $format = 'GIF';
            }

            if ( ! isset( self::INPUT_FORMATS[ $format ] ) ) {
                $file['error'] = __( '不支持此图片格式，请上传 JPEG、PNG、GIF、AVIF 或 WebP。', 'ghacg-image-webp' );
                return $file;
            }

            if ( $format !== $mime_format && ! ( in_array( $format, array( 'HEIC', 'HEIF' ), true ) && in_array( $mime_format, array( 'HEIC', 'HEIF' ), true ) ) ) {
                $file['error'] = __( '图片内容与检测到的格式不一致，请重新导出图片后上传。', 'ghacg-image-webp' );
                return $file;
            }
            if ( $image->getNumberImages() > 1 && ! self::can_encode_animated_webp() ) {
                $file['error'] = __( '服务器当前无法生成 WebP 动图，请联系站点管理员。', 'ghacg-image-webp' );
                return $file;
            }

            $max_side = 0;
            foreach ( $image as $frame ) {
                $max_side = max( $max_side, $frame->getImageWidth(), $frame->getImageHeight() );
            }
            $image->setIteratorIndex( 0 );
            $needs_resize = ! empty( $settings['limit_enabled'] ) && $max_side > (int) $settings['max_dimension'];

            // Avoid generation loss for an existing WebP that already meets the dimension rule.
            if ( 'WEBP' !== $format || $needs_resize ) {
                $output = tempnam( sys_get_temp_dir(), 'ghacg-webp-' );
                if ( false === $output ) {
                    throw new RuntimeException( 'Unable to create a local temporary file.' );
                }
                self::encode( $image, $output, $settings, $needs_resize );
                self::verify_output( $output, $image->getNumberImages() );

                $bytes = filesize( $output );
                if ( false === $bytes || ! copy( $output, $file['tmp_name'] ) || filesize( $file['tmp_name'] ) !== $bytes ) {
                    throw new RuntimeException( 'Unable to replace the upload temporary file.' );
                }
            }

            $base = pathinfo( wp_basename( $file['name'] ), PATHINFO_FILENAME );
            $file['name'] = sanitize_file_name( ( $base ?: 'image' ) . '.webp' );
            $file['type'] = 'image/webp';
            $file['size'] = filesize( $file['tmp_name'] );
            return $file;
        } catch ( Throwable $error ) {
            error_log( 'GHACG Image WebP: ' . $error->getMessage() );
            $file['error'] = __( '图片处理失败，请确认文件完整，或尝试转换为 JPEG、PNG、GIF、AVIF、WebP 后重新上传。', 'ghacg-image-webp' );
            return $file;
        } finally {
            if ( $image instanceof Imagick ) {
                $image->clear();
            }
            if ( false !== $output && is_file( $output ) ) {
                unlink( $output );
            }
        }
    }

    private static function encode( Imagick $image, string $output, array $settings, bool $needs_resize ): void {
        $frame_count = $image->getNumberImages();
        $iterations = $image->getImageIterations();
        $work = $frame_count > 1 ? $image->coalesceImages() : clone $image;

        try {
            foreach ( $work as $frame ) {
                if ( method_exists( $frame, 'autoOrientImage' ) ) {
                    $frame->autoOrientImage();
                }
                if ( $frame->getImageColorspace() !== Imagick::COLORSPACE_SRGB ) {
                    $frame->transformImageColorspace( Imagick::COLORSPACE_SRGB );
                }
                if ( $needs_resize ) {
                    $width = $frame->getImageWidth();
                    $height = $frame->getImageHeight();
                    $scale = min( 1, (int) $settings['max_dimension'] / max( $width, $height ) );
                    $frame->resizeImage( max( 1, (int) round( $width * $scale ) ), max( 1, (int) round( $height * $scale ) ), Imagick::FILTER_LANCZOS, 1 );
                }
                $frame->stripImage();
                $frame->setImageFormat( 'WEBP' );
                $frame->setImageCompressionQuality( (int) $settings['quality'] );
                if ( $frame_count > 1 ) {
                    $frame->setImageDispose( Imagick::DISPOSE_NONE );
                }
            }
            $work->setImageIterations( $iterations );
            if ( ! $work->writeImages( $output, true ) ) {
                throw new RuntimeException( 'WebP encoder returned false.' );
            }
        } finally {
            $work->clear();
        }
    }

    private static function verify_output( string $path, int $expected_frames ): void {
        if ( ! is_file( $path ) || 0 >= filesize( $path ) ) {
            throw new RuntimeException( 'WebP output is empty.' );
        }
        $check = new Imagick();
        try {
            $check->readImage( $path );
            $check->setIteratorIndex( 0 );
            if ( 'WEBP' !== strtoupper( $check->getImageFormat() ) || $expected_frames !== $check->getNumberImages() ) {
                throw new RuntimeException( 'WebP output verification failed.' );
            }
        } finally {
            $check->clear();
        }
    }

    private static function unsupported_message( string $format ): string {
        return sprintf( __( '服务器当前无法处理 %s 图片，请转换为 JPEG、PNG 或 WebP 后重新上传。', 'ghacg-image-webp' ), $format );
    }

    public static function upload_mimes( array $mimes ): array {
        $settings = self::settings();
        if ( ! empty( $settings['allow_bmp'] ) ) {
            $mimes['bmp'] = 'image/bmp';
        }
        if ( ! empty( $settings['allow_tiff'] ) ) {
            $mimes['tif|tiff'] = 'image/tiff';
        }
        if ( ! empty( $settings['allow_heic'] ) ) {
            $mimes['heic|heics'] = 'image/heic';
            $mimes['heif|heifs'] = 'image/heif';
        }
        return $mimes;
    }

    public static function add_settings_page(): void {
        add_options_page( '图片优化', '图片优化', 'manage_options', 'ghacg-image-webp', array( __CLASS__, 'render_settings' ) );
    }

    public static function register_settings(): void {
        register_setting( 'ghacg_image_webp', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) ) );
    }

    public static function sanitize_settings( $input ): array {
        $input = is_array( $input ) ? $input : array();
        $previous = self::settings();
        $quality = isset( $input['quality'] ) ? filter_var( $input['quality'], FILTER_VALIDATE_INT ) : false;
        $dimension = isset( $input['max_dimension'] ) ? filter_var( $input['max_dimension'], FILTER_VALIDATE_INT ) : false;
        if ( false === $quality || $quality < 1 || $quality > 100 ) {
            add_settings_error( self::OPTION, 'quality', '图片质量必须是 1 到 100 的整数。' );
            $quality = (int) $previous['quality'];
        }
        if ( false === $dimension || $dimension < 1 || $dimension > 30000 ) {
            add_settings_error( self::OPTION, 'max_dimension', '最大分辨率必须是 1 到 30000 的整数。' );
            $dimension = (int) $previous['max_dimension'];
        }

        $settings = array(
            'quality'       => (int) $quality,
            'limit_enabled' => ! empty( $input['limit_enabled'] ) ? 1 : 0,
            'max_dimension' => (int) $dimension,
            'allow_bmp'     => ! empty( $input['allow_bmp'] ) && self::can_decode( 'BMP' ) ? 1 : 0,
            'allow_tiff'    => ! empty( $input['allow_tiff'] ) && self::can_decode( 'TIFF' ) ? 1 : 0,
            'allow_heic'    => ! empty( $input['allow_heic'] ) && ( self::can_decode( 'HEIC' ) || self::can_decode( 'HEIF' ) ) ? 1 : 0,
        );
        return $settings;
    }

    public static function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = self::settings();
        $format_groups = array(
            'allow_bmp'  => array( 'BMP', array( 'BMP' ) ),
            'allow_tiff' => array( 'TIFF', array( 'TIFF' ) ),
            'allow_heic' => array( 'HEIC / HEIF', array( 'HEIC', 'HEIF' ) ),
        );
        ?>
        <div class="wrap">
            <h1>图片优化</h1>
            <p>上传的图片在进入媒体库前统一转换为 WebP；多帧图片保留动画。转换失败时会拒绝上传。</p>
            <?php settings_errors( self::OPTION ); ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'ghacg_image_webp' ); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="ghacg-quality">WebP 有损质量</label></th><td><input id="ghacg-quality" type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION ); ?>[quality]" value="<?php echo esc_attr( (string) $settings['quality'] ); ?>"> <span>1–100</span></td></tr>
                    <tr><th scope="row">最大分辨率</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[limit_enabled]" value="1" <?php checked( $settings['limit_enabled'] ); ?>> 启用长边限制</label></td></tr>
                    <tr><th scope="row"><label for="ghacg-dimension">最大长边</label></th><td><input id="ghacg-dimension" type="number" min="1" max="30000" name="<?php echo esc_attr( self::OPTION ); ?>[max_dimension]" value="<?php echo esc_attr( (string) $settings['max_dimension'] ); ?>"> px</td></tr>
                    <?php foreach ( $format_groups as $key => $group ) :
                        $available = self::can_encode_webp() && ( self::can_decode( $group[1][0] ) || ( isset( $group[1][1] ) && self::can_decode( $group[1][1] ) ) );
                        ?>
                        <tr><th scope="row"><?php echo esc_html( $group[0] ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( $settings[ $key ] ); ?> <?php disabled( ! $available ); ?>> 允许上传并转换</label> <span><?php echo $available ? '当前可用' : '当前环境缺少解码器或 WebP 编码器'; ?></span></td></tr>
                    <?php endforeach; ?>
                </table>
                <h2>运行环境</h2>
                <p>Imagick：<?php echo class_exists( 'Imagick' ) ? '可用' : '不可用'; ?>；WebP 编码：<?php echo self::can_encode_webp() ? '可用' : '不可用'; ?>；Animated WebP 实际编码：<?php echo self::can_encode_animated_webp() ? '可用' : '不可用'; ?>。</p>
                <p>输入格式解码：<?php foreach ( array_keys( self::INPUT_FORMATS ) as $format ) { echo esc_html( $format . ' ' . ( self::can_decode( $format ) ? '✓' : '×' ) ) . '　'; } ?></p>
                <?php submit_button( '保存设置' ); ?>
            </form>
        </div>
        <?php
    }
}
