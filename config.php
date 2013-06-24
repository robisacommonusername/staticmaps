<?php
//constants to be set by user
define('MAX_WIDTH',2048); //maximum width of generated image in pixels
define('MAX_HEIGHT',2048); //maximum height of generated image in pixels
define('DEFAULT_WIDTH',640); //default image width in pixels
define('DEFAULT_HEIGHT',640); //default image height in pixels
define('MAX_ICON_SIZE',102400); //maximum size in bytes that an icon image included from an external URL can be
define('MAX_CACHE_AGE',604800); //max age of cached items in seconds.  1 week = 604800 sec
define('SUGGESTED_CACHE_SIZE',100); //suggested cache size in MB.  Not a hard limit, will try to maintain cache size at this level

/*
set the map type.  Type can be one of
 'DebugImage' - used for debugging only
 'OpenStreetmapImage' - use tiles from openstreetmap
 'NearmapImage' - Nearmap tiles.  Needs username and password to be defined
 'LocalTileImage' - Use tiles stored locally on the server.  Needs base folder for tiles to be defined
 */
define('MAP_TYPE','DebugImage');
define('NEARMAP_USER','username'); //only required for MAP_TYPE NearmapImage
define('NEARMAP_PASS','password'); //as above
define('LOCAL_BASE_FOLDER','./resources'); //required if using local tile image

//php error reporting level and display
error_reporting(-1); //all errors
ini_set('display_errors','False'); //set to 'True' for debug, 'False' for production
ini_set('log_errors','True'); //log errors to server log.
?>
