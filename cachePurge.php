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
    
//Cache purge.php - set this file up as a cron job to maintain the cache
//at a reasonable size

require('config.php');

//might be a lot of files in cache.  Don't simply do a scandir, or we'll run out
//of memory.  Similarly, don't calculate cache size by iterating over every file
//that's slow and inefficient - will use statistical sampling to estimate cache size and maximum
//file age.  Assumes that all tiles in cache have similar filesize

//determine number of files in cache
$d = new DirectoryIterator('./cache');
$i = 1;
//get an upper bound on the number of files
while ($d->valid()) {
	$i *= 2;
	$d->seek($i);
}
//now know that there are between $i and $i/2 files.  Find out exact number by binary search
//won't worry about off by one errors - unimportant
$lower = $i/2;
$upper = $i;
do {
	$mid = intval(($lower + $upper)/2);
	$d->seek($mid);
	if ($d->valid()){
		$lower = $mid;
	} else {
		$upper = $mid;
	}
	$width = $upper - $lower;
} while ($width > 1);

$numFiles = $mid;
//use first 100 files in cache to estimate the disk space occupied by the cache
//and the distribution of the file ages (where age here means time since last access)
// Properties of hash function ensure sampling is random
$d->rewind();
$totalSize = 0;
$filesSeen = 0;
$now = time();
$ageSum = 0;
$ageSquareSum = 0;
foreach ($d as $entry){
	if ($entry->isFile()){
		$totalSize += $entry->getSize();
		$filesSeen++;
		$age = $now - $entry->getATime();
		$ageSum += $age;
		$ageSquareSum += $age*$age;
	}
	if ($filesSeen > 100){
		break;
	}
}
$cacheSize = $totalSize / $filesSeen * $numFiles / 1048576; //estimated cache size in MB
//estimate mean and standard deviation of file ages
$mean = $ageSum / $filesSeen;
$sigma = sqrt($ageSquareSum/($filesSeen-1) - $mean*$mean);

//use mean + 2sigma as our estimate for the maximum age of the files in the cache
//if uniformally distributed (ie constant load) in some interval, this should give us a very slight overestimate
// ie X ~ U(0,RealMaxAge)
//estimated max = RealMaxAge/2 + 2/sqrt(12)*RealMaxAge = 1.07*RealMaxAge
//TODO: calculate confidence intervals, show 100 samples is enough 
$maxAge = $mean + 2*$sigma;

$thresholdAge = $maxAge * SUGGESTED_CACHE_SIZE / $cacheSize;
//now clear anything that's older than the threshold age
$d->rewind();
foreach ($d as $entry){
	if ($entry->isFile()){
		$age = $now - $entry->getATime();
		if ($age > $thresholdAge){
			$fn = $entry->getFilename();
			unlink("./cache/$fn");
		}
	}
}

?>
