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
	utils.php - contains utility functions to
	1. Parse html strings, hex strings, and english colours into GD colours
	2. Determine the size of jpeg, png, and gif files from string data.  Essentially
	an implementation of getimagesizefromstring for PHP versions before 5.4
	3. Join file paths in a platform independent way
-----------------------------------------------------------------------------*/

	function decodeColour($image,$colour, $opacity){
		if ($opacity === null){
			$opacity = 1.0;
		}
		switch ($colour){
			case 'black':
			$ret_colour = imagecolorallocatealpha($image,0,0,0,intval((1-$opacity)*127));
			break;
			case 'white':
			$ret_colour = imagecolorallocatealpha($image,255,255,255,intval((1-$opacity)*127));
			break;
			case 'red':
			$ret_colour = imagecolorallocatealpha($image,255,0,0,intval((1-$opacity)*127));
			break;
			case 'green':
			$ret_colour = imagecolorallocatealpha($image,0,255,0,intval((1-$opacity)*127));
			break;
			case 'blue':
			$ret_colour = imagecolorallocatealpha($image,0,0,255,intval((1-$opacity)*127));
			break;
			case 'yellow':
			$ret_colour = imagecolorallocatealpha($image,255,255,0,intval((1-$opacity)*127));
			break;		
			case 'cyan':
			$ret_colour = imagecolorallocatealpha($image,0,255,255,intval((1-$opacity)*127));
			break;
			case 'magenta':
			$ret_colour = imagecolorallocatealpha($image,255,0,255,intval((1-$opacity)*127));
			break;		
			default:
			//convert hexadeximal
			$fixedString = preg_replace('/0x|#/', '', $colour);
			$numeric = hexdec($fixedString);
			
			//check for opacity.  opacity passed in as part of $colour overrides specified opacity
			if (strlen($fixedString) > 6){
				$opacity = 127 - floor(($numeric & 0x000000FF) / 2.0);
				$numeric = $numeric >> 8;
			} else {
				$opacity = floor(127*(1-$opacity));
			}
			$red = ($numeric & 0xFF0000) >> 16;
			$green = ($numeric & 0x00FF00) >> 8;
			$blue = $numeric & 0x0000FF;
			$ret_colour = imagecolorallocatealpha($image, $red, $green, $blue, $opacity);
		}
		return $ret_colour;
	}
	
	function mygetimagesizefromstring($data){
		//getimagesizefromstring is only supported on php >=5.4,
		//which is annoying, as I'm developing on 5.3 right now
		
		//although data may be a large string, don't need to do pass by reference,
		// since PHP does copy-on-write
		if (function_exists('getimagesizefromstring')){
			return getimagesizefromstring($data);
		} else {
			if ($ret = getPngSize($data)){
				return $ret;
			} elseif ($ret = getGifSize($data)){
				return $ret;
			} elseif ($ret = getJpegSize($data)){
				return $ret;
			} else {
				return false;
			}
		}
	}
	
	function getPngSize($data){
		//get first 24 bytes
		$head = unpack('C24',$data);
		static $pngHeader = array(0x89,0x50,0x4E,0x47,0x0D,0x0A,0x1A,0x0A);
		if (array_slice($head,0,8) != $pngHeader){
			return false;
		}
		//big endian byte order for png, unsigned 32 bits
		//width starts from 17th byte, and height from 21st
		//note that the $head array is 1 indexed
		$height = 0;
		$width = 0;
		for ($i=0; $i<4; $i++){
			$width << 8;
			$width |= $head[$i+17];
			$height << 8;
			$height |= $head[$i+21];
		}
		// note that the above could fuck up (by causing sign
		// rollover) if php is compiled
		// on a platform with a 32 bit long type, though this
		// is highly unlikely on a modern machine.  Oh the joys of C and PHP.
		return array($width, $height, IMAGETYPE_PNG);
		
	}
	function getGifSize($data){
		$head = unpack('C10',$data);
		static $gifHeader = array(0x47,0x49,0x46,0x38,0x39,0x61);
		if (array_slice($head,0,6) != $gifHeader){
			return false;
		}
		//little endian byte order for gif.  Width is at offset 6, 2 bytes Lit End
		//height is at offset 8, 2 bytes, little endian
		//note that the head array is 1 indexed
		$width = $head[8] << 8 | $head[7];
		$height = $head[10] << 8 | $head[9];
		return array($width, $height, IMAGETYPE_GIF);
	}
	function getJpegSize($data){
		//look for SOF marker, 0xFF 0xC0 or 0xFF 0xC2
		//then after the marker, byte 4+5 gives height
		//as big endian, unsigned 16 bit, byte 6+7 is width
		
		//unpack the data as unsigned bytes
		
		//$unpacked = unpack('C*', $data);
		//cannot unpack all the data in one go.  Wastes even more memory
		//than could reasonably be expected, due to vagaries in php (possible mem leak, or maybe just the way php does arrays??)
		//egs - unpack 6.8k jpg => memory usage doubles
		//		unpack 80k jpg => memory usage increases by factor of 25
		//		unpack 1.2M jpg => >137M mem used (~19MB before unpack).  Cannot give exact increase factor, as exceeded mem limit
		//not sure exactly what it is in the php internals that causes this memory ballooning.
		//but the point is, don't unpack all the data at once.
		//tested: PHP 5.3.2-1ubuntu4.18 with Suhosin-Patch (cli) (built: Sep 12 2012 19:12:47) on linux x64
		$unpacked = unpack('C11',$data);
		//check header
		if (!($unpacked[1] == 0xFF && $unpacked[2] == 0xD8)){
			return false;
		}
		
		//find the start of frame header - read in each block, until we get to 0xFFC0 or 0xFFC2
		$off = 2;
		$i = 3;
		$dataSize = strlen($data); //strlen returns the number of bytes, not number of characters, so this is safe: http://php.net/manual/en/function.strlen.php
		while ($off < $dataSize){
			if ($unpacked[$i] != 0xFF){
				return false;
			}
			$blockType = $unpacked[$i+1];
			$i += 2;
			$off += 2;
			if ($blockType == 0xC0 || $blockType == 0xC2){
				//found start of frame header
				//height is bytes 4 and 5, 16 bit unsigned big endian
				//width is bytes 6 and 7, 16 bit unsigned big endian
				$height = $unpacked[$i+3] << 8 | $unpacked[$i+4];
				$width = $unpacked[$i+5] << 8 | $unpacked[$i+6];
				return array($width, $height, IMAGETYPE_JPEG);
			} else {
				//some other type of block.
				//read in its length, and skip over it.
				//block length is 16 bit unsigned, big endian
				$blockLength = $unpacked[$i] << 8 | $unpacked[$i+1];
				$off += $blockLength;
				$i = 1;
				$unpacked = @unpack("x$off/C9", $data); //suppress warning if we try to read past EOF
			}
		}
		return false;
	}
	
	//function for joining a directory and file name in an os independent way
	function pathJoin($head, $tail){
		$headParts = explode(DIRECTORY_SEPARATOR, $head); //this will filter out
		$tailParts = explode(DIRECTORY_SEPARATOR, $tail);
		$merged = implode(DIRECTORY_SEPARATOR, array_merge($headParts, $tailParts));
		//might need to filter out double or triple // or \\ (eg if merged /home/user/Documents/ with file.png)
		$delim = DIRECTORY_SEPARATOR === '/' ? '|' : '/';
		$sep = DIRECTORY_SEPARATOR;
		$final = preg_replace($delim.$sep.'{2,3}'.$delim, $sep, $merged);
		return $final;
	}
?>
