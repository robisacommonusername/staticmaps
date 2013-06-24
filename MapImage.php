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
	MapImage.php - contains base class MapImage, responsible for rendering maps
-----------------------------------------------------------------------------*/
require_once('utils.php');
require('Polyline.php');

abstract class MapImage {
	function __construct(){
		$this->image = null;
		$this->lines = array();
		$this->markers = array();
		$this->implicitVBSet = false;
		$this->implicitVBN = -90;
		$this->implicitVBS = 90;
		$this->implicitVBE = -180;
		$this->implicitVBW = 180;
		
		$this->implicitCentre = null;
		
		$this->viewboxSet = false;
		$this->vbN = null;
		$this->vbS = null;
		$this->vbE = null;
		$this->vbW = null;
		
		$this->centre = null;
		$this->zoom = null;
		$this->width = DEFAULT_WIDTH;
		$this->height = DEFAULT_HEIGHT;
		$this->sizeSet = false;
		
		$this->maxZoom = 20;
		$this->defaultZoom = 15;
	}
	private function renderBaseLayer(){
		$status = 0;
		$status += $this->centre === null ? 0 : 1;
		$status += $this->zoom === null || $this->zoom < 0 || $this->zoom > $this->maxZoom ? 0 : 2;
		$status += $this->viewboxSet ? 4 : 0;
		$status += $this->sizeSet ? 8 : 0;
		
		//pick unspecified parameters
		//check for the "special" cases which may lead to no sensible output
		if ($status == 2 || $status == 8 || $status == 0){
			//check for implicit VB
			if ($this->implicitVBSet){
				list($S,$W,$N,$E) = $this->getImplicitViewbox();
				$this->setViewbox($S,$W,$N,$E);
				$status += 4;
			} elseif ($this->implicitCentre !== null){
				$this->centre = $this->implicitCentre;
				$status += 1;
			} else {
				die('Not enough information supplied to render map');
			}
		}
		
		//pick size
		if (in_array($status, array(1,3,4,5,7))) {
			$this->height = DEFAULT_HEIGHT;
			$this->width = DEFAULT_WIDTH;
		}
		
		//pick a zoom OR an implicit viewbox
		if ($status == 9 || $status == 1) {
			if ($this->implicitVBSet){
				$vb = $this->getImplicitViewbox();
				list($S,$W,$N,$E) = $this->getImplicitViewbox();
				$this->setViewbox($S,$W,$N,$E);
				$status += 4; //render using centre + viewbox (implicit) + size (13)
			} else {
				$this->zoom = $this->defaultZoom;
			}
		}
		
		//pick a centre from viewbox average
		if ($status == 4 || $status == 12){
			$this->centre = array(($this->vbN + $this->vbS)/2, ($this->vbE + $this->vbW)/2);
		}
		
		//corner pixel coordinates - initialise to prevent scope issues
		list($pSEx,$pSEy,$pNWx,$pNWy) = array(0,0,0,0);
		switch ($status){
			//render from zoom + viewbox
			case 6:
			case 14:
				//check that size does not exceed maximum, if it does, override zoom
				// if we need to override, pick the largest zoom that keeps everything
				// within the allowed size and also has viewbox in frame
				//die(var_dump($this->width,$this->height));
				do {
					list($pSEx,$pSEy) = $this->latLngToPixel($this->vbS,$this->vbE,$this->zoom);
					list($pNWx,$pNWy) = $this->latLngToPixel($this->vbN,$this->vbW,$this->zoom);
					$this->height = $pNWy - $pSEy;
					$this->width = $pSEx - $pNWx;
					$this->zoom--;
				} while ($this->width > MAX_WIDTH || $this->height > MAX_HEIGHT);
				$this->zoom++; //correct for final decrement
				break;
			
			////render from centre+viewbox+size
			//we need to compute a zoom in this case, so the viewbox isn't binding
			//we therefore adjust the viewbox to ensure that all the markers and polylines
			//stay in view
			case 13:
			case 15:
			case 7:
			case 12:
			case 4:
			case 5:
				//check size
				if ($this->width > MAX_WIDTH) $this->width = MAX_WIDTH;
				if ($this->height > MAX_HEIGHT) $this->height = MAX_HEIGHT;
				//ensure markers and polys are in view (implicit viewbox)
				$S = $this->vbS < $this->implicitVBS ? $this->vbS : $this->implicitVBS;
				$N = $this->vbN > $this->implicitVBN ? $this->vbN : $this->implicitVBN;
				$E = $this->vbE > $this->implicitVBE ? $this->vbE : $this->implicitVBE;
				$W = $this->vbW < $this->implicitVBW ? $this->vbW : $this->implicitVBW;
				//compute zoom such that viewbox stays in view
				list($mViewboxSEx, $mViewboxSEy) = $this->latLngToProjected($S,$E);
				list($mViewboxNWx, $mViewboxNWy) = $this->latLngToProjected($N,$W);
				list($Clat,$Clng) = $this->centre;
				list($mCx,$mCy) = $this->latLngToProjected($Clat,$Clng);
				for ($zoom = $this->maxZoom; $zoom >= 0; $zoom--){
					list($pViewboxSEx, $pViewboxSEy) = $this->projectedToPixel($mViewboxSEx,$mViewboxSEy,$zoom);
					list($pViewboxNWx, $pViewboxNWy) = $this->projectedToPixel($mViewboxNWx,$mViewboxNWy,$zoom);
					list($pCx,$pCy) = $this->projectedToPixel($mCx,$mCy,$zoom);
					$pSEx = intval($pCx + $this->width/2);
					$pSEy = intval($pCy - $this->height/2);
					$pNWx = intval($pCx - $this->width/2);
					$pNWy = intval($pCy + $this->height/2);
					$this->zoom = $zoom;
					if ($pSEx > $pViewboxSEx &&
						$pSEy < $pViewboxSEy &&
						$pNWx < $pViewboxNWx &&
						$pNWy > $pViewboxNWy) {
						break;
					}
				}
				break;
			
			//render from centre+size+zoom
			case 1:
			case 11:
			case 3:
			case 9:
				//check size
				if ($this->width > MAX_WIDTH) $this->width = MAX_WIDTH;
				if ($this->height > MAX_HEIGHT) $this->height = MAX_HEIGHT;
			
				list($Clat,$Clng) = $this->centre;
				list($pCx,$pCy) = $this->latLngToPixel($Clat,$Clng,$this->zoom);
				$pSEx = intval($pCx + $this->width/2);
				$pSEy = intval($pCy - $this->height/2);
				$pNWx = intval($pCx - $this->width/2);
				$pNWy = intval($pCy + $this->height/2);
				break;
			
			default:
				die('Not enough information supplied to render map');
		}
		
		$this->pSE = array($pSEx,$pSEy);
		$this->pNW = array($pNWx,$pNWy);
		
		//create image
		list($tSEx,$tSEy) = $this->pixelToTileCoords($pSEx,$pSEy,$this->zoom);
		list($tNWx,$tNWy) = $this->pixelToTileCoords($pNWx,$pNWy,$this->zoom);
		
		//need to account for different tile coordinate systems - what do??
		// GD coordinate system has (0,0) in top left (NW) corner, but map pixels coords
		// have (0,0) in the SW corner.  Tile coord system may be different from both of these
		// and may vary by provider
		$numXtiles = abs($tSEx - $tNWx)+1;
		$numYtiles = abs($tNWy - $tSEy)+1;
		//need to check for the edge case where we've crossed over the international date line, otherwise
		//instead of asking for just a few tiles across the line, we'll be asking for loads across the whole
		//world.
		if ($pNWx > $pSEx){
			//crossed IDL - need to be careful with tile range
			$numXtiles = pow(2,$this->zoom)-1-$numXtiles;
			list($pIDLx,$pIDLy) = $this->projectedToPixel(pi(),0);
			list($tIDLx,$tIDLy) = $this->pixelToTileCoords($pIDLx,$pIDLy);
			//need to determine sign convention for this tile provider.  Do tile numbers
			//increase or decrease as we go east?
			if ($tNWx > $tSEx){
				//tilenums increase as we go east (since remember that the NW corner is actually
				//to the RIGHT of the SE corner in this case)
				$tilesWestToEast = array_merge(range($tNWx,$tIDLx),range(0,$tSEx)); 
			} else {
				//tilenums decrease as we go right/east
				$tilesWestToEast = array_merge(range($tNWx,0),range($tIDLx,$tSEx));
			}
		} else {
			//no IDL crossing - normal case
			$tilesWestToEast = range($tNWx,$tSEx);
		}
		$tilesNorthToSouth = range($tNWy,$tSEy);
		$temp = imagecreatetruecolor(256*$numXtiles, 256*$numYtiles);
		foreach ($tilesWestToEast as $x => $tx){
			foreach ($tilesNorthToSouth as $y => $ty){
				//256*$x, 256*$y is the GD coord, tile coord is $tx,$ty.  Alway iterate West to East, and North to south
				//regardless of the tile coordinate system
				$tile = $this->getTileImage($tx,$ty,$this->zoom);
				if ($tile !== false){
					imagecopy($temp, $tile, 256*$x, 256*$y,0,0,256,256);
					imagedestroy($tile);
				}
			}
		}
		if ($this->image !== null){
			imagedestroy($this->image); //prevent mem leak
		}
		$this->image = imagecreatetruecolor($this->width,$this->height);
		list($pTempNWx,$pTempNWy) = $this->tileCoordsToPixel($tNWx,$tNWy,$this->zoom);
		//$pTempNWy is the pixel coordinate of the southern edge of the tile containing
		// the NW corner.  Need to be careful when computing $offsetY (ie need to convert to GD coords)
		$offsetX = $pNWx - $pTempNWx;
		$offsetY = 256 - ($pNWy - $pTempNWy);
		imagecopy($this->image,$temp,0,0,$offsetX,$offsetY,$this->width,$this->height);
		imagedestroy($temp);
	}
	
	private function renderMarkers(){
		foreach ($this->markers as $marker){
			list($lat, $lng, $icon) = $marker;
			$p = $this->latLngToPixel($lat, $lng, $this->zoom);
			$GD = $this->mapPixelToGD($p);
			$icon->render($this->image, $GD[0], $GD[1]);
		}
	}
	
	private function renderLines(){
		foreach ($this->lines as $line){
			list($points, $options) = $line;
			$poly = new Polyline(null, $options);
			
			//convert each of the points into GD coords, and add to
			//the polyline
			foreach ($points as $point){
				list($lat,$lng) = $point;
				$p = $this->latLngToPixel($lat, $lng, $this->zoom);
				$GD = $this->mapPixelToGD($p);
				$poly->addPoint($GD);
			}
			$poly->render($this->image);
		}
	}
	
	public function render(){
		$this->renderBaseLayer();
		$this->renderMarkers();
		$this->renderLines();
		header('Content-Type: image/png');
		imagepng($this->image);
	}
	
	public function addMarker($lat, $lng, $icon){
		$this->markers[] = array($lat, $lng, $icon);
		//adding a single marker to the map doesn't set an implicit viewbox
		//but it might set an implicit centre
		if (count($this->markers) > 1){
			$this->implicitVBSet = true;
		
			//do we need to change bounds?
			if ($lat < $this->implicitVBS)
				$this->implicitVBS = $lat;
			elseif ($lat > $this->implicitVBN)
				$this->implicitVBN = $lat;
			
			if ($lng > $this->implicitVBE)
				$this->implicitVBE = $lng;
			elseif ($lng < $this->implicitVBW)
				$this->implicitVBW = $lng;
		} else {
			//implicit centre
			$this->implicitCentre = array($lat,$lng);
		}
	}
	public function addLine($points, $options){
		$this->lines[] = array($points, $options);
		//check - do we need to change map bounds?
		//note that a vertical or horizontal line does not set an implicit
		//viewbox, but it does set an implicit centre
		list($lastLat, $lastLng) = $points[0];
		$vert = true;
		$horiz = true;
		$minLat = 90;
		$maxLat = -90;
		$minLng = 180;
		$maxLng = -180;
		foreach ($points as $point){
			list($lat,$lng) = $point;
			$vert = $vert && ($lng == $lastLng);
			$horiz = $horiz && ($lat == $lastLat);
			if ($lat > $maxLat){
				$maxLat = $lat;
			} elseif ($lat < $minLat){
				$minLat = $lat;
			}
			if ($lng > $maxLng){
				$maxLng = $lng;
			} elseif ($lng < $minLng){
				$minLng = $lng;
			}
			if (!($vert || $horiz)){
				break;
			}
			list($lastLat,$lastLng) = $point;
		}
		if ($vert || $horiz){
			$this->implicitCentre = array(($maxLat+$minLat)/2,($maxLng+$minLng)/2);
		} else {
			$this->implicitVBSet = true;			
			foreach ($points as $point){
				if ($point[0] > $this->implicitVBN)
					$this->implicitVBN = $point[0];
				elseif ($point[0] < $this->implicitVBS)
					$this->implicitVBS = $point[0];
				
				if ($point[1] > $this->implicitVBE)
					$this->implicitVBE = $point[1];
				elseif ($point[1] < $this->implicitVBW)
					$this->implicitVBW = $point[1];
			}
		}
	}
	
	public function setCentre($lat,$lng){
		$this->centre = array($lat,$lng);
	}
	
	public function setZoom($zoom){
		$this->zoom = $zoom;
	}
	
	public function setSize($width, $height){
		$this->width = $width;
		$this->height = $height;
		$this->sizeSet = true;
	}
	
	public function setViewbox($S,$W,$N,$E){
		$this->viewboxSet = true;
		$this->vbN = $N;
		$this->vbS = $S;
		$this->vbE = $E;
		$this->vbW = $W;
	}
	public function clearViewbox(){
		$this->viewboxSet = false;
	}
	public function getViewbox(){
		$ret = $this->viewboxSet ? array($this->vbS,$this->vbW,$this->vbN,$this->vbE) : array();
		return $ret;
	}
	public function setImplicitViewbox($S,$W,$N,$E){
		$this->implicitVBSet = true;
		$this->implicitVBN = $N;
		$this->implicitVBS = $S;
		$this->implicitVBE = $E;
		$this->implicitVBW = $W;
	}
	public function getImplicitViewbox(){
		$ret = $this->implicitVBSet ? array($this->implicitVBS,$this->implicitVBW,$this->implicitVBN,$this->implicitVBE) : array();
		return $ret;
	}
	public function clearImplicitViewbox(){
		$this->implicitVBSet = false;
	}	
	static function latLngToProjected($lat, $lng){
		//let radius of earth be 1 unit
		$mx = deg2rad($lng);
        $my = log( tan( (90 + $lat) * pi() / 360.0 ));
        return array($mx, $my);
	}
	static function projectedToPixel($x,$y,$zoom){
		$res = 2 * pi() / 256 / pow(2,$zoom); //resolution in earth radii/pixel (note lower number = better res)
        $px = ($x + pi()) / $res; //note that 0px,0px is South West corner
        $py = ($y + pi()) / $res;
        return array_map('intval',array($px, $py));
	}
	static function latLngToPixel($lat, $lng, $zoom){
		list ($x, $y) = MapImage::latLngToProjected($lat, $lng);
		return MapImage::projectedToPixel($x, $y, $zoom);
	}
	function pixelToTileCoords($px, $py, $zoom){
		//convert a projected pixel to a tile number
		//all maps use projected coords with (0,0) in southwest
		//tile coords may vary by provider, override if necessary
		$tx = floor( $px / 256.0 );
		$ty = pow(2, $zoom) - 1 - floor( $py / 256.0);
		if ($ty < 0) $ty = 0;
        return array($tx, $ty);
	}
	function tileCoordsToPixel($tx,$ty,$zoom){
		//inverse of pixelToTileCoords
		//override if necessary - just provide a sensible default
		return array(256*$tx,256*(pow(2,$zoom) - 1 - $ty));
	}
	static function checkTileWraparound($tx,$ty,$zoom){
		//check for wraparounds
		$numTiles = pow(2,$zoom);
		if ($tx >= $numTiles) $tx = $tx % $numTiles;
		if ($ty >= $numTiles) $ty = $ty % $numTiles;
		if ($tx < 0) $tx = $numTiles - 1 + $tx;
		if ($ty < 0) $ty = $numTiles - 1 + $ty;
		return array($tx, $ty);
	}
	function getTileImage($tx,$ty,$zoom){
		//return a GD image resource for the given tile
		//must override
	}
	
	private function mapPixelToGD($point){
		//convert a coordinate in the map coordinate system into the GD coord system
		$offX = $this->pNW[0];
		$offY = $this->pSE[1];
		return array($point[0] - $offX, $this->height - $point[1] + $offY);
	}
	function __destruct() {
		if ($this->image !== null){
			imagedestroy($this->image);
		}
	}
}
?>
