<?php
/* 
Copyright Robert Palmer, 2013

This file is part of static_maps.

    static_maps is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    static_maps is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with static_maps.  If not, see <http://www.gnu.org/licenses/>.
*/
/*----------------------------------------------------------------------------
	images.php - several derived classes of MapImage.  Each derived class uses
	map tiles from a different source.  Classes in this file
	DebugImage - used for debugging purposes
	NearmapImage - use Nearmap tiles
	OpenStreetmapImage - use openstreetmap tiles
	LocalTileImage - use tiles stored locally on the server
	CachedImage - abstract class which caches the tiles it downloads
-----------------------------------------------------------------------------*/
require('MapImage.php');

class DebugImage extends MapImage {
	function getTileImage($tx,$ty,$zoom){
		list($x,$y) = MapImage::checkTileWraparound($tx,$ty,$zoom);
		$im = imagecreatetruecolor(256, 256);
		$xOdd = $x % 2 == 1 ? True : False;
		$yOdd = $y % 2 == 1 ? True : False;
		$colour = $xOdd ^ $yOdd ? imagecolorallocate($im, 255,0,0) : imagecolorallocate($im,255,255,0);
		imagefilledrectangle($im,0,0,256,256,$colour);
		$black = imagecolorallocate($im,0,0,0);
		$font = pathJoin(realpath('.'), implode(DIRECTORY_SEPARATOR, array('resources','LiberationSans-Regular.ttf')));
		imagettftext($im,14,0,0,128,$black,$font,"x=$x, y=$y, z=$zoom");
		return $im;
	}
}

abstract class CachedImage extends MapImage {
	function getFile($url) {
		$hash = sha1($url);
		// blithely ignoring hash collisions.  Not a big issue, collision probability
		// is very small - see below
		//
		// Collision Probability - A Birthday Problem
		// Maximum zoom is 23 => 2^24 tiles (maximum) (# people)
		// sha1 = 160bit => 2^160 hashes (birthdays)
		// probability of collision is, by birthday formula, approximately
		// P(collision) ~ 1-Exp(-2^24(2^24-1)/2^161) = 9.63e-35
		
		//check for existence of lock file, if it exists, busy wait
		$lockFile = "./cache/tmp_$hash";
		while (file_exists($lockFile)) {
			usleep(200000);
		}

		if (file_exists("./cache/$hash")){
			//check cache time
			$age = time() - filemtime("./cache/$hash");
			if ($age < MAX_CACHE_AGE) {
				return file_get_contents("./cache/$hash");
			}
		}
		
		//download the file and save it to the cache.
		//store to a temp file first, then rename the file
		//to mitigate race conditions - what if another request
		//tries to read cache file while it is still being written?
		
		//ie essentially use the tmp_file as a lock, assume renaming is atomic
		try {
			$contents = file_get_contents($url);
			if ($contents === false){
				throw new Exception('Could not download file');
			}
			if (file_put_contents($lockFile, $contents) === false){
				throw new Exception('could not save file to cache');
			}
			if (rename($lockFile, "./cache/$hash") === false){
				throw new Exception('could not rename lock');
			}
		} catch (Exception $e){}
		return $contents;
	}
}

class NearmapImage extends CachedImage {
	function __construct($user,$pass){
		parent::__construct();
		$this->user = $user;
		$this->pass = $pass;
		$this->maxZoom = 23;
	}
	
	function getTileImage($tx,$ty,$zoom){
		list($tx,$ty) = MapImage::checkTileWraparound($tx,$ty,$zoom);
		//nearmap uses jpeg images, but need to log in to get tiles
		$img_data = $this->getFile("http://{$this->user}:{$this->pass}@www.nearmap.com/maps/hl=en&x=$tx&y=$ty&z=$zoom&nml=Vert&s=Ga");
		return imagecreatefromstring($img_data); //will return false on error, don't need to warn
	}
	
}

class OpenStreetmapImage extends CachedImage {
	function __construct(){
		parent::__construct();
		$this->maxZoom = 18;
	}
	function getTileImage($tx,$ty,$zoom){
		list($tx,$ty) = MapImage::checkTileWraparound($tx,$ty,$zoom);
		$server = chr(ord('a') + ($tx+$ty)%3); //a,b or c
		$img_data = $this->getFile("http://$server.tile.openstreetmap.org/$zoom/$tx/$ty.png");
		return imagecreatefromstring($img_data); //will return false on error
	}
}

class LocalTileImage extends MapImage {
	function __construct($baseFolder){
		parent::__construct();
		$this->base = realpath($baseFolder);
		$this->maxZoom = 18;
	}
	function getTileImage($x,$y,$z){
		list($tx,$ty) = MapImage::checkTileWraparound($x,$y,$z);
		
		$fn = pathJoin($this->base, implode(DIRECTORY_SEPARATOR, array($z,$tx,"$ty.png")));
		return imagecreatefrompng($fn);
	}
}
?>
