<?php
/**
 * A lightweight, dependency-free smoke test for the image conversion class.
 * Run with: php tests/smoke.php
 */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );

function add_filter() {}
function add_action() {}
function get_option( $name, $default = false ) {
    return $GLOBALS['ghacg_test_settings'] ?? $default;
}
function __( $text ) { return $text; }
function wp_basename( $path ) { return basename( $path ); }
function sanitize_file_name( $name ) { return $name; }

require dirname( __DIR__ ) . '/ghacg-image-webp/class-ghacg-image-webp.php';

function check( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function make_image( string $format, int $width = 20, int $height = 12, string $color = 'red' ): Imagick {
    $image = new Imagick();
    $image->newImage( $width, $height, new ImagickPixel( $color ) );
    $image->setImageFormat( $format );
    return $image;
}

function run_conversion( Imagick $source_image, string $source_format, string $filename, int $frames = 1, bool $alpha = false, ?int $iterations = null ): string {
    $path = tempnam( sys_get_temp_dir(), 'ghacg-webp-test-' );
    if ( false === $path ) {
        throw new RuntimeException( 'Could not create a test file.' );
    }
    try {
        foreach ( $source_image as $frame ) {
            $frame->setImageFormat( $source_format );
        }
        $source_image->writeImages( $path, true );
        $file = GHACG_Image_WebP::filter_upload( array(
            'name'     => $filename,
            'type'     => 'application/octet-stream',
            'tmp_name' => $path,
            'size'     => filesize( $path ),
            'error'    => 0,
        ) );
        check( empty( $file['error'] ), $filename . ': ' . ( $file['error'] ?? 'unknown error' ) );
        check( 'image/webp' === $file['type'] && '.webp' === strtolower( substr( $file['name'], -5 ) ), $filename . ': output name or MIME is wrong.' );

        $result = new Imagick( $path );
        check( 'WEBP' === strtoupper( $result->getImageFormat() ), $filename . ': output is not WebP.' );
        check( $frames === $result->getNumberImages(), $filename . ': frame count changed.' );
        foreach ( $result as $frame ) {
            check( max( $frame->getImageWidth(), $frame->getImageHeight() ) <= 10, $filename . ': maximum dimension was not enforced.' );
        }
        $result->setIteratorIndex( 0 );
        check( $alpha === (bool) $result->getImageAlphaChannel(), $filename . ': alpha behavior changed.' );
        if ( $frames > 1 ) {
            foreach ( $result as $frame ) {
                check( 12 === $frame->getImageDelay(), $filename . ': frame delay changed.' );
            }
        }
        if ( null !== $iterations ) {
            $result->setIteratorIndex( 0 );
            check( $iterations === $result->getImageIterations(), $filename . ': animation loop count changed.' );
        }
        $result->clear();
        return $path;
    } catch ( Throwable $error ) {
        @unlink( $path );
        throw $error;
    } finally {
        $source_image->clear();
    }
}

if ( ! class_exists( 'Imagick' ) || ! extension_loaded( 'fileinfo' ) ) {
    fwrite( STDERR, "Imagick and fileinfo are required.\n" );
    exit( 2 );
}

$GLOBALS['ghacg_test_settings'] = array(
    'quality'       => 82,
    'limit_enabled' => 1,
    'max_dimension' => 10,
    'allow_bmp'     => 0,
    'allow_tiff'    => 0,
    'allow_heic'    => 0,
);

$outputs = array();
try {
    $outputs[] = run_conversion( make_image( 'JPEG' ), 'JPEG', 'photo.jpeg' );

    $png = new Imagick();
    $png->newImage( 20, 12, new ImagickPixel( 'rgba(255,0,0,0.5)' ) );
    $outputs[] = run_conversion( $png, 'PNG', 'transparent.png', 1, true );

    $gif = new Imagick();
    foreach ( array( 'red', 'blue' ) as $color ) {
        $frame = make_image( 'GIF', 20, 12, $color );
        $frame->setImageDelay( 12 );
        $frame->setImageIterations( 2 );
        $gif->addImage( $frame );
        $frame->clear();
    }
    $outputs[] = run_conversion( $gif, 'GIF', 'motion.gif', 2, false, 2 );

    $transparent_gif = new Imagick();
    foreach ( array( 'red', 'blue' ) as $color ) {
        $frame = new Imagick();
        $frame->newImage( 20, 12, new ImagickPixel( 'none' ) );
        $draw = new ImagickDraw();
        $draw->setFillColor( new ImagickPixel( $color ) );
        $draw->rectangle( 0, 0, 4, 4 );
        $frame->drawImage( $draw );
        $frame->setImageFormat( 'GIF' );
        $frame->setImageDelay( 12 );
        $transparent_gif->addImage( $frame );
        $frame->clear();
    }
    $outputs[] = run_conversion( $transparent_gif, 'GIF', 'transparent-motion.gif', 2, true );

    // AVIF is optional in the local test environment; only fixture creation can skip it.
    $avif_fixture = tempnam( sys_get_temp_dir(), 'ghacg-avif-probe-' );
    $avif_available = false;
    try {
        $avif_probe = make_image( 'AVIF' );
        $avif_probe->writeImage( $avif_fixture );
        $avif_probe->clear();
        $avif_available = true;
    } catch ( Throwable $error ) {
        echo 'SKIP AVIF fixture: ' . $error->getMessage() . "\n";
    }
    @unlink( $avif_fixture );
    if ( $avif_available ) {
        $outputs[] = run_conversion( make_image( 'AVIF' ), 'AVIF', 'photo.avif' );
    }

    $webp = make_image( 'WEBP', 5, 5, 'purple' );
    $webp_path = tempnam( sys_get_temp_dir(), 'ghacg-webp-original-' );
    $webp->writeImage( $webp_path );
    $hash_before = hash_file( 'sha256', $webp_path );
    $webp_file = GHACG_Image_WebP::filter_upload( array( 'name' => 'existing.webp', 'type' => 'image/webp', 'tmp_name' => $webp_path, 'size' => filesize( $webp_path ), 'error' => 0 ) );
    check( empty( $webp_file['error'] ), 'Existing WebP was rejected.' );
    check( $hash_before === hash_file( 'sha256', $webp_path ), 'Existing in-bounds WebP was re-encoded.' );
    check( 'image/webp' === $webp_file['type'], 'Existing WebP MIME changed.' );
    $outputs[] = $webp_path;
    $webp->clear();

    $bmp = make_image( 'BMP', 5, 5, 'yellow' );
    $bmp_path = tempnam( sys_get_temp_dir(), 'ghacg-bmp-test-' );
    $bmp->writeImage( $bmp_path );
    $bmp_file = GHACG_Image_WebP::filter_upload( array( 'name' => 'disabled.bmp', 'type' => 'image/bmp', 'tmp_name' => $bmp_path, 'size' => filesize( $bmp_path ), 'error' => 0 ) );
    check( ! empty( $bmp_file['error'] ), 'BMP should be disabled by default.' );
    @unlink( $bmp_path );
    echo "PASS BMP defaults to disabled.\n";

    foreach ( array( 'BMP' => 'allow_bmp', 'TIFF' => 'allow_tiff', 'HEIC' => 'allow_heic' ) as $format => $setting ) {
        $fixture = tempnam( sys_get_temp_dir(), 'ghacg-format-probe-' );
        try {
            $probe = make_image( $format, 5, 5, 'cyan' );
            $probe->writeImage( $fixture );
            $probe->clear();
        } catch ( Throwable $error ) {
            echo 'SKIP ' . $format . ' fixture: ' . $error->getMessage() . "\n";
            @unlink( $fixture );
            continue;
        }
        @unlink( $fixture );
        $GLOBALS['ghacg_test_settings'][ $setting ] = 1;
        $outputs[] = run_conversion( make_image( $format, 5, 5, 'cyan' ), $format, strtolower( $format ) . '-enabled.' . strtolower( $format ) );
        $GLOBALS['ghacg_test_settings'][ $setting ] = 0;
        echo 'PASS optional ' . $format . " conversion.\n";
    }

    echo "All available smoke tests passed.\n";
} finally {
    foreach ( $outputs as $path ) {
        @unlink( $path );
    }
}
