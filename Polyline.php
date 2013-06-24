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
	utils.php - contains class Polyline, useful for drawing weighted
				polygonal paths in GD
-----------------------------------------------------------------------------*/
require_once('utils.php');
class Polyline {
	public function __construct($points, $options){
		$this->colour = null;
		$this->weight = 1;
		$this->opacity = 1.0;
		//points should be in the GD coordinate system
		//NOT the map pixel coordinates
		if (is_array($points)) {
			$this->setPoints($points);
		} else {
			$this->points = array();
		}
		if (is_array($options)){
			$this->setOptions($options);
		}
	}
	public function addPoint($p){
		$this->points[] = $p;
	}
	public function setPoints($ps){
		$this->points = $ps;
	}
	public function getPoints(){
		return $this->points;
	}
	public function getPoint($i){
		return $this->points[$i];
	}
	public function setColour($col){
		$this->colour = $col;
	}
	public function setWeight($w){
		$this->weight = $w;
	}
	public function setOpacity($op){
		$this->opacity = $op;
	}
	public function setOptions($ops){
		foreach ($ops as $k => $v){
			switch ($k){
				case 'weight':
				$this->setWeight($v);
				break;
				
				case 'colour':
				$this->setColour($v);
				break;
				
				case 'opacity':
				$this->setOpacity($v);
				break;
				
				default:
			}
		}
	}
	public function render($canvas){
		//check that we actually have some points to render
		if (count($this->points) < 2) {
			return;
		}
		//avoid if-else confusion.  status is two bit number, bit 1 indicates if colour specified, bit 0 indicates if opacity specified
		$colourStatus = ($this->colour !== null ? 2 : 0 ) + ($this->opacity !== null ? 1 : 0 );
		$colour = null;
		switch ($colourStatus) {
				case 0:
				$colour = decodeColour($canvas,'blue', null);
				break;
				case 1:
				$colour = decodeColour($canvas,'blue',$this->opacity);
				break;
				case 2:
				$colour = decodeColour($canvas,$this->colour, null);
				break;
				case 3:
				$colour = decodeColour($canvas,$this->colour,$this->opacity);
		}
			
		$first = true;
		$lastPoint = null;
		if ($this->weight === 1){
			$p0 = $this->points[0];
			array_reduce($this->points, function ($last, $current) use($canvas, $colour){
				imageline($canvas, $last[0], $last[1], $current[0], $current[1]);
				return $current;
			}, $p0);
		} else {
			//weighted line - need to use a filled polygon
			//calculate the first points
			$fpZero = 1e-16; //small enough.  Do not trust php with floating point roundoff and type coercion
			
			//remove consecutive duplicates from the points array.  Ensures there's never zero distance
			//between points, helping us avoid division by zero problems
			$points = array_reduce($this->points, function($body, $curr){
				if (end($body) !== $curr){
					$body[] = $curr;
				}
				return $body;
			}, array());
			$numPoints = 2*count($points);
			$top = array();
			$bottom = array();
			$p1 = $points[0];
			$p2 = $points[1];
			$vec2 = array($p2[0] - $p1[0], $p2[1] - $p1[1]);
			$perp2 = array($vec2[1], -1*$vec2[0]);
			$norm2 = sqrt(pow($vec2[0], 2) + pow($vec2[1], 2));
			$top[] = array($p1[0] + intval($this->weight*$perp2[0]/(2*$norm2)), $p1[1] + intval($this->weight*$perp2[1]/(2*$norm2)));
			$bottom[] = array($p1[0] - intval($this->weight*$perp2[0]/(2*$norm2)), $p1[1] - intval($this->weight*$perp2[1]/(2*$norm2)));
		
			
			//now calculate the remaining points, except for the last
			// need to do angle bisection
			while (count($points) >= 3){
				$p0 = array_shift($points);
				$p1 = $points[0];
				$p2 = $points[1];
				$vec1 = $vec2;
				$vec2 = array($p2[0] - $p1[0], $p2[1] - $p1[1]);
				$norm1 = $norm2;
				$norm2 = sqrt(pow($vec2[0], 2) + pow($vec2[1], 2));
				
				//skip over duplicated points
				if ($norm1 < $fpZero || $norm2 < $fpZero){
					++$skipped;
					continue;
				}
				//find angle between vec1 and vec2
				$cosT = -1*($vec1[0]*$vec2[0] + $vec1[1]*$vec2[1])/($norm1*$norm2);
				$cosTon2 = sqrt((1+$cosT)/2);
				$sinTon2 = sqrt(1 - pow($cosTon2,2));
				$cross = Polyline::crossProduct($vec1, $vec2);
				if ($cross < 0) {
					$sinTon2 *= -1;
				}
				//rotate vec2 by and angle of +-T/2 (depending on whether cross product was +ve or -ve)
				//to get bisector
				$bisector = array($cosTon2*$vec2[0] - $sinTon2*$vec2[1],
								$cosTon2*$vec2[1] + $sinTon2*$vec2[0]);
				//now calculate the two points on the polygon
				//need to be careful about which we assign to top
				//and which we assign to bottom
				if (abs($sinTon2) > $fpZero){
					$adjustedWeight = abs($this->weight/$sinTon2);
					//need to check for self intersection
					//this will still look ugly, since GD will erase the self intersections,
					//but it looks much better than it might otherwise
					if (pow($adjustedWeight,2) > 4*pow($norm2,2) + pow($this->weight,2)){
						if (abs($cosTon2) < $fpZero){
							continue;
						} else {
							$adjustedWeight = $norm2/$cosTon2;
						}
					}
					$newPoint1 = array($p1[0] + intval($adjustedWeight*$bisector[0]/(2*$norm2)),
								$p1[1] + intval($adjustedWeight*$bisector[1]/(2*$norm2)));
					$newPoint2 = array($p1[0] - intval($adjustedWeight*$bisector[0]/(2*$norm2)),
								$p1[1] - intval($adjustedWeight*$bisector[1]/(2*$norm2)));
				} else {
					//going back over previous line sgment - add in butt ends
					//note that GD will erase the bits it goes over twice.
					$perp2 = array($vec2[1], -1*$vec2[0]);
					$newPoint1 = array($p1[0] + intval($this->weight*$perp2[0]/(2*$norm2)),
								$p1[1] + intval($this->weight*$perp2[1]/(2*$norm2)));
					$newPoint2 = array($p1[0] - intval($this->weight*$perp2[0]/(2*$norm2)),
								$p1[1] - intval($this->weight*$perp2[1]/(2*$norm2)));	
				}
								
				//want to make the two line segments from last point to current point
				//on top and bottom more or less parallel.  Take a cross product, and
				//assign top and bottom such that its absolute value is as close as possible
				//to zero.
				$lastTop = end($top);
				$lastBot = end($bottom);
				$topToNP1 = array($newPoint1[0]-$lastTop[0], $newPoint1[1]-$lastTop[1]);
				$botToNP2 = array($newPoint2[0]-$lastBot[0], $newPoint2[1]-$lastBot[1]);
				$cross1 = Polyline::crossProduct($topToNP1,$botToNP2);
				
				$topToNP2 = array($newPoint2[0]-$lastTop[0], $newPoint2[1]-$lastTop[1]);
				$botToNP1 = array($newPoint1[0]-$lastBot[0], $newPoint1[1]-$lastBot[1]);
				$cross2 = Polyline::crossProduct($topToNP2,$botToNP1);
				
				if (abs($cross1) < abs($cross2)){
					$top[] = $newPoint1;
					$bottom[] = $newPoint2;
				} else {
					$top[] = $newPoint2;
					$bottom[] = $newPoint1;
				}
			} 
				
			
			//finish off last points
			$perp2 = array($vec2[1], -1*$vec2[0]);
			$top[] = array($p2[0] + intval($this->weight*$perp2[0]/(2*$norm2)), $p2[1] + intval($this->weight*$perp2[1]/(2*$norm2)));
			$bottom[] = array($p2[0] - intval($this->weight*$perp2[0]/(2*$norm2)), $p2[1] - intval($this->weight*$perp2[1]/(2*$norm2)));
			
			//merge the top and bottom arrays, flatten them
			$allPoints = array_merge($top, array_reverse($bottom));
			$flattened = array_reduce($allPoints, function($body, $current){
				return array_merge($body, $current);
			}, array());
			
			imagefilledpolygon($canvas, $flattened, $numPoints, $colour);
			
		}
			
	}
	static function crossProduct($a,$b){
		return $a[0]*$b[1] - $a[1]*$b[0];
	}
}
?>
