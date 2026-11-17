<?php
/** LiveMark dokuwiki plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     s c yoo <dryoo@live.com>
 * url    http://openwiki.kr/tech/livemark
 *
 * based on iReflect Plugin https://github.com/i-net-software/dokuwiki-plugin-reflect
 *
 * 2026-11 fix: cache is written atomically (tmp+rename), cache is actually reused,
 * the watered file is served on warm cache too, EXIF orientation is baked in
 * before stripping, and ICC profile is preserved.
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once(DOKU_PLUGIN . 'action.php');

class action_plugin_livemark extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        if (isset($_REQUEST['i'])) {
            return;
        }
        $controller->register_hook('MEDIA_SENDFILE', 'BEFORE', $this, 'livemark__livemark');
    }

    public function livemark__livemark(&$event, $args)
    {
        if (!extension_loaded('imagick')) {
            return;
        }

        $data = $event->data;
        $src = $data['file'];
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!in_array($ext, ['jpg', 'png', 'webp'], true)) {
            return;
        }

        $cacheFile = getCacheName($src, '.media.watermark.' . $ext);
        $srcMtime = @filemtime($src);
        $mtime = @filemtime($cacheFile);

        // DokuWiki cache param: -1 = keep forever, 0 = never cache, > 0 = seconds
        $ttl = (int) $data['cache'];

        $stale = ($mtime === false)
            || ($mtime < $srcMtime)
            || ($ttl === 0)
            || ($ttl > 0 && $mtime < time() - $ttl);

        if ($stale) {
            if (!$this->create_watermark_image($data, $cacheFile)) {
                return; // fall back to the original file
            }
            // the request already stat'ed this path; drop the cached stat so
            // Last-Modified/ETag describe the bytes we are about to send
            clearstatcache(true, $cacheFile);
            if (!is_file($cacheFile)) {
                return;
            }
        }

        // warm cache must also be served, otherwise the original unmarked file leaks out
        if (!is_file($cacheFile)) {
            return;
        }

        $data['file'] = $cacheFile;
        $data['ext'] = $ext;
        $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $data['mime'] = $mimes[$ext];
        $event->data = $data;
    }

    /**
     * Resolve the configured watermark path regardless of the current working directory.
     */
    protected function watermark_file()
    {
        $p = $this->getConf('watermark_path');
        if (!$p) {
            $p = 'livemark.png';
        }
        if ($p[0] === '/') {
            return is_file($p) ? $p : null;
        }
        $candidates = [
            DOKU_INC . 'lib/exe/' . $p,          // legacy relative cwd
            DOKU_INC . 'lib/' . ltrim($p, './'), // ../plugins/x -> lib/plugins/x
            __DIR__ . '/' . basename($p),
        ];
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real && is_file($real)) {
                return $real;
            }
        }
        return null;
    }

    /**
     * Bake EXIF orientation into pixels; autoOrientImage() is missing in older imagick builds.
     */
    protected function orient_image(Imagick $image)
    {
        if (method_exists($image, 'autoOrientImage')) {
            try {
                $image->autoOrientImage();
                return;
            } catch (Exception $e) {
                // fall through to the manual path
            }
        }

        $bg = in_array($image->getImageFormat(), ['PNG', 'WEBP'], true) ? 'none' : 'white';
        if (!method_exists($image, 'getImageOrientation')) {
            return;
        }
        switch ($image->getImageOrientation()) {
            case Imagick::ORIENTATION_TOPLEFT:
                return;
            case Imagick::ORIENTATION_TOPRIGHT:
                $image->flopImage();
                break;
            case Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage($bg, 180);
                break;
            case Imagick::ORIENTATION_BOTTOMLEFT:
                $image->flopImage();
                $image->rotateImage($bg, 180);
                break;
            case Imagick::ORIENTATION_LEFTTOP:
                $image->transposeImage();
                break;
            case Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage($bg, 90);
                break;
            case Imagick::ORIENTATION_RIGHTBOTTOM:
                $image->transverseImage();
                break;
            case Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage($bg, -90);
                break;
        }
        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    protected function create_watermark_image($data, $cache_path)
    {
        $input = $data['file'];

        try {
            $image = new Imagick($input);

            if ($image->getImageWidth() < (int) $this->getConf('minsize')) {
                $image->clear();
                return false;
            }

            // never flatten an animated image into a single frame
            if ($image->getNumberImages() > 1) {
                $image->clear();
                return false;
            }

            // bake EXIF rotation into pixels before stripImage() drops the tag
            $this->orient_image($image);

            $wm_file = $this->watermark_file();
            if (!$wm_file) {
                $image->clear();
                return false;
            }

            $watermark = new Imagick($wm_file);

            // opacity (%) applied to the alpha channel
            $opacity = $this->getConf('opacity');
            if ($opacity === false || $opacity === null || $opacity === '') {
                $opacity = 100; // unset means fully opaque, not invisible
            }
            $opacity = (float) $opacity;
            if ($opacity < 100) {
                $watermark->evaluateImage(
                    Imagick::EVALUATE_MULTIPLY,
                    max(0.0, $opacity / 100),
                    Imagick::CHANNEL_ALPHA
                );
            }

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            // watermark is % of the image width
            $size = (float) $this->getConf('size') / 100;
            $wm_width = $watermark->getImageWidth();
            $wm_height = $watermark->getImageHeight();
            $scale = ($width * $size) / $wm_width;

            $wm_new_width = max(1, (int) round($wm_width * $scale));
            $wm_new_height = max(1, (int) round($wm_height * $scale));
            $watermark->resizeImage($wm_new_width, $wm_new_height, Imagick::FILTER_LANCZOS, 1);

            $horizontal = (float) $this->getConf('horizontal');
            $vertical = (float) $this->getConf('vertical');
            $dest_x = (int) round(($width - $wm_new_width) / 2 - $width * (0.5 - $horizontal / 100));
            $dest_y = (int) round(($height - $wm_new_height) / 2 - $height * (0.5 - $vertical / 100));

            $image->compositeImage($watermark, Imagick::COMPOSITE_OVER, $dest_x, $dest_y);

            $format = strtoupper($image->getImageFormat());
            $ext = $format === 'PNG' ? 'png' : ($format === 'WEBP' ? 'webp' : 'jpg');
            $icc = null;
            try {
                $icc = $image->getImageProfile('icc'); // throws when the profile is absent
            } catch (Exception $e) {
                $icc = null;
            }
            $image->stripImage();
            if ($icc) {
                $image->profileImage('icc', $icc);
            }

            if ($ext === 'png') {
                $image->setImageFormat('png');
            } elseif ($ext === 'webp') {
                $quality = (int) $this->getConf('quality');
                if ($quality < 1) {
                    $quality = 85;
                }
                $image->setImageFormat('webp');
                $image->setImageCompressionQuality($quality);
            } else {
                $quality = (int) $this->getConf('quality');
                if ($quality < 1) {
                    $quality = 85;
                }
                $image->setImageFormat('jpeg');
                $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality($quality);
            }

            // atomic publish: never let a reader see a half written file
            $tmp = $cache_path . '.' . getmypid() . '.tmp';
            $written = $image->writeImage($tmp);
            $image->clear();
            $watermark->clear();

            if (!$written || !is_file($tmp) || filesize($tmp) === 0) {
                @unlink($tmp);
                return false;
            }
            @chmod($tmp, 0644);
            if (!@rename($tmp, $cache_path)) {
                @unlink($tmp);
                return false;
            }

            return true;
        } catch (Exception $e) {
            msg('Imagick error: ' . $e->getMessage(), -1);
            return false;
        }
    }
}
