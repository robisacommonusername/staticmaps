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
	debugImageGenerator.php - generates tile images for debugging purposes
-----------------------------------------------------------------------------*/
$im = imagecreatetruecolor(256, 256);
$xOdd = $_GET['x'] % 2 == 1 ? True : False;
$yOdd = $_GET['y'] % 2 == 1 ? True : False;
$colour = $xOdd ^ $yOdd ? imagecolorallocate($im, 255,0,0) : imagecolorallocate($im,255,255,0);
imagefilledrectangle($im,0,0,256,256,$colour);
$black = imagecolorallocate($im,0,0,0);
$base = explode(DIRECTORY_SEPARATOR, realpath('.'));
$base[] = 'resources';
$base[] = 'LiberationSans-Regular.ttf';
$font = implode(DIRECTORY_SEPARATOR, $base);
imagettftext($im,14,0,0,128,$black,$font,"x={$_GET['x']}, y={$_GET['y']}, z={$_GET['z']}");
header('Content-Type: image/png');
imagepng($im);
imagedestroy($im);
?>
