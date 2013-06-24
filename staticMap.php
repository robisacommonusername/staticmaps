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
	staticMap.php - main program, make calls to staticMap.php to generate maps
	required query parameters are detailed in README
-----------------------------------------------------------------------------*/
require('config.php');
require('images.php');
require('icons.php');

switch (MAP_TYPE){
	case 'DebugImage':
	$map = new DebugImage();
	break;
	
	case 'NearmapImage':
	$map = new NearmapImage(NEARMAP_USER,NEARMAP_PASS);
	break;
	
	case 'OpenStreetmapImage':
	$map = new OpenStreetmapImage();
	break;
	
	case 'LocalTileImage':
	$map = new LocalTileImage(LOCAL_TILE_BASE);
	break;
	
	default:
	die('Unsupported MAP_TYPE defined in config.php');
}

//parse gets
if (isset($_GET['centre'])){
	if (preg_match('/^-?[0-9]{1,3}[.]?[0-9]{0,15},-?[0-9]{1,3}[.]?[0-9]{0,15}$/', $_GET['centre'])){
		list($lat, $lng) = array_map('floatval', explode(',', $_GET['centre']));
		$map->setCentre($lat, $lng);
	} else {
		die('bad centre coord');
	}
}

if (isset($_GET['zoom'])){
	if (preg_match('/^1?[0-9]$/', $_GET['zoom']))
		$map->setZoom(intval($_GET['zoom']));
	else
		die('bad zoom');
}

if (isset($_GET['size'])){
	if (preg_match('/^\\d{1,4},\\d{1,4}$/', $_GET['size'])){
		list($width, $height) = array_map('intval', explode(',', $_GET['size']));
		$map->setSize($width, $height);
	} else {
		die('bad size');
	}
}

if (isset($_GET['viewbox'])){
	if (preg_match('/^(-?[0-9]{1,3}[.]?[0-9]{0,15},){3}-?[0-9]{1,3}[.]?[0-9]{0,15}$/', $_GET['viewbox'])){
		list($S,$W,$N,$E) = array_map('floatval', explode(',', $_GET['viewbox']));
		$map->setViewBox($S,$W,$N,$E);
	} else {
		die('bad viewbox specification');
	}
}

//render any markers
if (isset($_GET['markers'])){
	//need to make sure we've got an array even in the case where we're only
	//rendering one marker
	$markers = is_array($_GET['markers']) ? $_GET['markers'] : array($_GET['markers']);
	foreach ($markers as $marker){
		//parse the marker string to form a hash/dictionary with the marker options
		$components = preg_split('/(?<!@)[|]/', $marker); //allow separator to be escaped with @ if we want label with | in it
		$options = array_reduce($components, function ($body, $current){
			//problem with explode if label contains :, or if passing in data uri
			//thus need to use preg_split
			//$parts = explode(':', $current); 
			$parts = preg_split('/(?<!data|@|http|https|ftp):/', $current);
			$new = count($parts) < 2 ? array('location' => $current) : array($parts[0] => $parts[1]);
			return array_merge($body, $new);
		}, array());
		//set some options based on the marker type
		if (!array_key_exists('type',$options)){
			$options['type'] = 'default';
		}
		switch ($options['type']){
			case 'box':
			$icon = new BoxLabelIcon();
			goto checkColoursAndText;
			
			case 'callout_SW':
			case 'callout_NW':
			case 'callout_NE':
			case 'callout_SE':
			$icon = new CalloutLabelIcon();
			$currType = $options['type'];
			$types = array('callout_SW' => CALLOUT_SW, 'callout_NW' => CALLOUT_NW,
							'callout_NE' => CALLOUT_NE, 'callout_SE' => CALLOUT_NE);
			$icon->setType($types[$currType]);
			
			checkColoursAndText:
			if (array_key_exists('text', $options)){
				$icon->setText($options['text']);
			}
			if (array_key_exists('textcolour',$options)){
				if (preg_match('/^(black|white|red|green|blue|yellow|cyan|magenta|(0x|#)?[A-Fa-f0-9]{6,8})$/', $options['textcolour'])){
					$icon->setTextColour($options['textcolour']);
				}
			}
			if (array_key_exists('colour', $options)){
				if (preg_match('/^(black|white|red|green|blue|yellow|cyan|magenta|(0x|#)?[A-Fa-f0-9]{6,8})$/', $options['colour'])){
					$icon->setColour($options['colour']);
				}
			}
			if (array_key_exists('textsize', $options)){
				$val = intval($options['textsize']);
				if ($val > 8 && $val < 72){
					$icon->setTextSize($val);
				}
			}
			break;
			
			case 'image':
			$icon = new ImageIcon();
			if (array_key_exists('icon',$options)){
				if (preg_match('/^data:/', $options['icon'])){
					try {
						$icon->loadFromURI($options['icon']);
					} catch (Exception $e){
						die($e->getMessage());
					}
				} else {
					//need to check $options['icon'] is actually a proper URL, and not
					//trying to do file includes from our server, etc
					if (preg_match('|^https?://|', $options['icon'])){
						try {
							$icon->loadFromURL($options['icon']);
						} catch (Exception $e){
							die ($e->getMessage());
						}
					} else {
						die ('bad icon URL specified');
					}
				}
			}
			break;

			default:
			$icon = new DefaultIcon();
		}
		
		//check for setting of anchor and location
		if (array_key_exists('anchor', $options)){
			$xy = array_map('intval', explode(',', $val));
			if (count($xy) == 2){
				$icon->setAnchor($xy[0], $xy[1]);
			}
		}
				
		if (array_key_exists('location', $options)){
			if (preg_match("/^-?[0-9]{1,3}[.]?[0-9]{0,15},-?[0-9]{1,3}[.]?[0-9]{0,15}$/", $options['location'])){
				list($lat, $lng) = array_map('floatval', explode(',',$options['location']));
				$map->addMarker($lat, $lng, $icon);	
			}
		}
	}
}

//render polylines
if (isset($_GET['polylines'])){
	//need to make sure we've got an array, even in the case where we only have one polyline
	$polys = is_array($_GET['polylines']) ? $_GET['polylines'] : array($_GET['polylines']);
	foreach ($polys as $line){
	
		$points = array();
		$options = array();
		
		$components = explode('|',$line);
		foreach ($components as $component){
			$pair = explode(':',$component);
			$key = $pair[0];
			$val = count($pair) > 1 ? $pair[1] : null;
			switch ($key){
				case 'weight':
				if (preg_match('/^[1-9][0-9]?$/',$val)) {
					$options['weight'] = intval($val);
				} else {
					die('bad line weight specified');
				}
				break;
				
				case 'colour':
				if (preg_match('/^(black|white|red|green|blue|yellow|cyan|magenta|(0x|#)?[A-Fa-f0-9]{6,8})$/', $val)) {
					$options['colour'] = $val;
				} else {
					die('bad line colour specified');
				}
				break;
				
				case 'opacity':
				if (preg_match('/^1|0[.]?[0-9]{0,15}$/', $val)){
					$options['opacity'] = floatval($val);
				} else {
					die('bad line opacity specified');
				}
				break;
				
				default:
				//assume this is a coordinate
				if (preg_match('/^-?[0-9]{1,3}[.]?[0-9]{0,15},-?[0-9]{1,3}[.]?[0-9]{0,15}$/', $key)) {
					$points[] = array_map('floatval', explode(',', $key));
				} else {
					die('malformed input supplied as part of polyline definition');
				}
				
				
			}
		}
		if (count($points) >= 2) {
			$map->addLine($points, $options);
		}
		
	}
}

//output image to browser
$map->render();
?>
