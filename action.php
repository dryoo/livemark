<?php
/** LiveMark dokuwiki plugin
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     s c yoo <dryoo@live.com>
 * url    http://openwiki.kr/tech/livemark
 * 
 * bASED ON iReflect Plugin https://github.com/i-net-software/dokuwiki-plugin-reflect
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');

class action_plugin_livemark extends DokuWiki_Action_Plugin {

	function register(&$controller) {
		if ( isset($_REQUEST['i']) ) { return; }
		$controller->register_hook('MEDIA_SENDFILE', 'BEFORE', $this, 'livemark__livemark');
	}

	function livemark__livemark(&$event, $args) {
		if (extension_loaded('gd') == false && !@dl('gd.so')) { return; }
		$data = $event->data;
		if ($data['ext']=='jpg') //||  $data['ext']=='png') 
		{
        	$ext=$data['ext'];
			$cacheFile = getCacheName($data['file'],".media.watermark.$ext");
			$mtime = @filemtime($cacheFile); // 0 if not exists
			$cache = $data['cache'];
			
			if( ($mtime == 0) ||           // cache does not exist
				($mtime < time()-$cache)   // 'recache' and cache has expired
			){
				if ( $this->create_watermark_image( $data, $cacheFile ) ) {
					$data['orig'] = $data['file'];
					$data['file'] = $cacheFile;
					list($data['ext'],$data['mime'],$data['download']) = mimetype($cacheFile);
					$event->data = $data;
				}
			}
		}
	}
	
	function create_watermark_image( $data, $cache_path ) {
        global $conf;
		$input = $data['file'];

		if ( !($image_details = getimagesize($input)) ) { return false; }

		$width = $image_details[0];
		$height = $image_details[1];
		$type = $image_details[2];
		$mime = $image_details['mime'];

        if ($width<$this->getConf('minsize')) return false;
		//	Detect the source image format
		switch ($type)
		{
			case 1://	GIF
						$source = imagecreatefromgif($input); break;
			case 2://	JPG
						$source = imagecreatefromjpeg($input); break;
			case 3://	PNG
						$source = imagecreatefrompng($input); break;
			default:	return false;
		}

		/*	Build the watermark image */
		$output = $this->imagewatermark($source, $width, $height);

		/* Output our final Image */
		if ( headers_sent() ) { return false; }

		//	If you'd rather output a JPEG instead of a PNG then pass the parameter 'jpeg' (no value needed) on the querystring
		if ( substr($cache_path, -3) == 'png' ) {
			imagepng($output, $cache_path, intval($conf['jpg_quality'] / 11));
		} else if ( substr($cache_path, -3) == 'jpg' ) {
			$finaloutput = imagecreatetruecolor($width, $height);
			imagecopy($finaloutput, $output, 0, 0, 0, 0, $width, $height);
			imagejpeg($finaloutput, $cache_path, intval($conf['jpg_quality']));
		}
		imagedestroy($output);
		return true;
		}
	
	function imagewatermark($src_img, $src_width, $src_height) {
        global $conf;
		// Create Object
		$marked = imagecreatetruecolor($src_width, $src_height);
		
		// Copy Source
		imagecopy($marked, $src_img, 0, 0, 0, 0, $src_width, $src_height);
	
			$watermark =imagecreatefrompng($this->getConf('watermark_path'));//'../plugins/livemark/livemark.png');

			$size=$this->getConf('size')/100;
			$ratio=$src_width/imagesx($watermark)*$size;
		    $watermark_width = imagesx($watermark)*$ratio;  
	        $watermark_height = imagesy($watermark)*$ratio;  
			$dest_x = ($src_width - $watermark_width)/2-$src_width*(0.5-$this->getConf('horizontal')/100);  
			$dest_y = ($src_height - $watermark_height)/2-$src_height*(0.5-$this->getConf('vertical')/100);  
			imagecopyresampled($marked, $watermark,  $dest_x, $dest_y, 0, 0, $watermark_width, $watermark_height, imagesx($watermark),  imagesy($watermark));
		 	 	
		return $marked;
	}
}