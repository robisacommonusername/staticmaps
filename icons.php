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
	icons.php - Contains classes used to draw markers on the map.  Classes in
	this file
	1. ImageIcon - Uses a user specified image as a marker
	2. DefaultIcon - like image icon, but does not require user to specify an
	image.  Will load resources/default.png as its image
	3. BoxLabelIcon - Displays text, surrounded by a rectangle as a marker
	4. CalloutLabelIcon - Displays text in a callout/speech bubble
	5. PolygonLabelIcon - abstract class, parent of CalloutLabelIcon and BoxLabelIcon
-----------------------------------------------------------------------------*/
require_once('utils.php');
class ImageIcon {
	function __construct(){
		$this->image = null;
		$this->anchorX = 0;
		$this->anchorY = 0;
	}
	public function render($canvas, $x, $y){
		list($aX, $aY) = $this->getAnchor();
		imagecopy($canvas, $this->image, $x-$aX, $y-$aY, 0, 0, $this->width, $this->height); 
	}
	public function loadFromURL($url){
		//load an icon from the specified url
		
		//we use file_get_contents to get the image data to ensure
		//that we don't need to download the image twice (once for
		//getimagesize, and once for imagecreate)
		
		//want to get the data, then do a getimagesizefromstring
		//however, this limits us to php 5.4 or greater
		//to support older php, I've implemented getimagesizefromstring
		//in utils.php
		
		$imageData = file_get_contents($url,false,null,0,MAX_ICON_SIZE); //need specify a max number of bytes to prevent DOS
		$size = mygetimagesizefromstring($imageData);
		if ($size === false){
			throw new Exception('Bad image data type');
		}
		list($width, $height, $type) = $size;
		$anchorX = intval($width/2);
		$anchorY = intval($height/2);
		$this->width = $width;
		$this->height = $height;
		$this->setAnchor($anchorX,$anchorY);
		$image = imagecreatefromstring($imageData);
		if ($image === false){
			throw new Exception('Bad image data type');
		}
		$this->image = $image;
	}
	
	public function loadFromURI($uri){
		if (preg_match("/data:/", $uri)){
			$data = preg_replace('/^[^,]*,/', '', $uri);
			if (preg_match('/base64/',$uri)) {
				$data = base64_decode($data);
			} else {
				$data = rawurldecode($data);
			}
			$image = imagecreatefromstring($data);
			if ($image === false){
				throw new Excpetion('Could not load from URI');
			}
			$this->image = $image;
			
			//need to find out size of image.  Should use getimagesizefromstring
			//but that's only available on php >= 5.4.0.  For php<5.4, I've implemented
			//getimagesizefromstring for gif, png and jpeg files in utils.php
			$size = mygetimagesizefromstring($data);
			if ($size === false){
				throw new Exception('Could not load from URI');
			}
			list($width, $height) = $size;
			$this->width = $width;
			$this->height = $height;
			$anchorX = intval($width/2);
			$anchorY = intval($height/2);
			$this->setAnchor($anchorX, $anchorY);
		} else {
			throw new Exception('Could not load from URI'); 
		}
	}
	
	public function setAnchor($x, $y){
		$this->anchorX = $x;
		$this->anchorY = $y;
	}
	
	public function getAnchor(){
		return array($this->anchorX, $this->anchorY);
	}
	function __destruct() {
		if ($this->image !== null){
			imagedestroy($this->image);
		}
	}
}

class DefaultIcon extends ImageIcon {
	function __construct(){
		parent::__construct();
		$this->loadFromURL('./resources/default.png');
		$this->setAnchor(12,40);
	}
}

abstract class PolygonLabelIcon {
	function __construct(){
		$this->colour = 'white';
		$this->anchorX = 0;
		$this->anchorY = 0;
		$this->txtColour = 'black';
		$this->points = array();
		$this->text = '';
		$this->txtSize = 14;
		
		//find the font filename
		$baseDir = explode(DIRECTORY_SEPARATOR, realpath('.'));
		$baseDir[] = 'resources';
		$baseDir[] = 'LiberationSans-Regular.ttf';
		$this->font = implode(DIRECTORY_SEPARATOR, $baseDir);
		
	}
	function addPoint($p){
		$this->points[] = $p;
	}
	function getPoints(){
		return $this->points;
	}
	function setPoints($points){
		$this->points = $points;
	}
	function getPoint($i){
		return $this->points[$i];
	}
	function deletePoint($i){
		array_splice($this->points,$i,1);
	}
	function setText($text){
		$this->text = $text;
	}
	function setAnchor($x,$y){
		$this->anchorX = $x;
		$this->anchorY = $y;
	}
	function getAnchor(){
		return array($this->anchorX,$this->anchorY);
	}
	function setColour($colour){
		$this->colour = $colour;
	}
	function setTextColour($colour){
		$this->txtColour = $colour;
	}
	function setTextSize($size){
		$this->txtSize = $size;
	}
	function render($canvas, $x, $y){
		//render the polygon
		$colour = decodeColour($canvas, $this->colour, null);
		$offsetX = $x - $this->anchorX;
		$offsetY = $y - $this->anchorY;
		$offsetPointsFlattened = array_reduce($this->points, 
			function($body, $p) use($offsetX,$offsetY){
				$offsetPoint = array($p[0]+$offsetX, $p[1]+$offsetY);
				return array_merge($body,$offsetPoint);
			}, array());
		imagefilledpolygon($canvas,$offsetPointsFlattened,count($this->points),$colour);
		//render the text
		$colour = decodeColour($canvas, $this->txtColour, null);
		imagettftext($canvas, $this->txtSize, 0, $x-$this->anchorX, $y-$this->anchorY,$colour,$this->font,$this->text); 
	}
}

class BoxLabelIcon extends PolygonLabelIcon{
	function __construct(){
		parent::__construct();
		$this->setText('');
	}
	function setText($text){
		parent::setText($text);
		$this->recalculateBB();
	}
	function setTextSize($size){
		parent::setTextSize($size);
		$this->recalculateBB();
	}
	private function recalculateBB(){
		//recalculate bounding box
		$corners = imagettfbbox($this->txtSize, 0, $this->font, $this->text);
		// For some reason, GD returns the BB relative to bottom left of text
		// despite its coordinate system being relative to top right of image
		//need to fiddle with coords
		$height = $corners[1] - $corners[5];
		$width = $corners[2] - $corners[0];
		$anchorX = intval($width/2);
		$anchorY = -1*intval($height/2);
		$this->setAnchor($anchorX, $anchorY);
		$newPoints = array_chunk($corners, 2);
		$this->setPoints($newPoints);
	}
	
}
define('CALLOUT_SW',0);
define('CALLOUT_NW',1);
define('CALLOUT_NE',2);
define('CALLOUT_SE',3);
class CalloutLabelIcon extends PolygonLabelIcon{
	function __construct(){
		parent::__construct();
		$this->type = CALLOUT_SW;
		$this->setText('');
	}
	function setText($text){
		parent::setText($text);
		$this->recalculateBB();
	}
	function setTextSize($size){
		parent::setTextSize($size);
		$this->recalculateBB();
	}
	private function recalculateBB(){
		//recalculate bounding box
		$corners = imagettfbbox($this->txtSize, 0, $this->font, $this->text);
		$height = $corners[1] - $corners[7];
		$newPts = array();
		switch($this->type){
			case CALLOUT_SW:
			$offset = 2;
			$newPts[] = $corners[0] + $height;
			$newPts[] = $corners[1];
			$anchorX = $corners[0];
			$newPts[] = $anchorX;
			$anchorY = $corners[1] + $height;
			$newPts[] = $anchorY;
			$newPts[] = $corners[0] + 2*$height;
			$newPts[] = $corners[1];
			break;
			
			case CALLOUT_NW:
			$offset = 6;
			$newPts[] = $corners[6] + 2*$height;
			$newPts[] = $corners[7];
			$anchorX = $corners[6];
			$newPts[] = $anchorX;
			$anchorY = $corners[7] - $height;
			$newPts[] = $anchorY;
			$newPts[] = $corners[6] + $height;
			$newPts[] = $corners[7];
			break;
			
			case CALLOUT_NE:
			$offset = 6;
			$newPts[] = $corners[4] - $height;
			$newPts[] = $corners[5];
			$anchorX = $corners[4];
			$newPts[] = $anchorX;
			$anchorY = $corners[5] - $height;
			$newPts[] = $anchorY;
			$newPts[] = $corners[4] - 2*$height;
			$newPts[] = $corners[5];
			break;
			
			case CALLOUT_SE:
			$offset = 2;
			$newPts[] = $corners[2] - 2*$height;
			$newPts[] = $corners[3];
			$anchorX = $corners[2];
			$newPts[] = $anchorX;
			$anchorY = $corners[3] + $height;
			$newPts[] = $anchorY;
			$newPts[] = $corners[2] - $height;
			$newPts[] = $corners[3];
			break;
			
			default:
			throw new Exception('Unsupported call out type');
		}
		array_splice($corners,$offset,0,$newPts);
		$this->setAnchor($anchorX, $anchorY);
		$newPoints = array_chunk($corners, 2);
		$this->setPoints($newPoints);
	}
	function setType($t){
		if (in_array($t, array(CALLOUT_SW,CALLOUT_NW,CALLOUT_NE,CALLOUT_SE))){
			$this->type = $t;
		}
	}
}
?>
