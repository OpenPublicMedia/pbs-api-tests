<?php
/** using this file in this location to collect some data from IMA and other public media folks */

//CONSTANTS
define ('SODOR_ENDPOINT', "http://services.pbs.org/");
define ('API_KEY', 'ENTER YOUR PBS PROVIDED API KEY');

//VARIABLES
$station_list = Array();
$jArray = Array();
$logArray = Array();

$userIP;
$zipcode;
$knowniptest = FALSE;
$knownziptest = FALSE;
// Variables for Logging
$strLogID = date('YmdHis');
$strLogDate = date('Y-m-d H:i:s');
$strLogTesting = 'false';
$strLogError = 'NONE';
$arrLogFlagship = Array();
$ipToFilter = 'ENTER AN IP ADDRESS THAT YOU WOULD LIKE TO FILTER'; // filtering my own IP so it doesn't keep showing up in the logs
$pathToLogfile = 'ENTER FULL PATH TO LOG FILE';

if (isset($_GET['test'])) {
	switch ($_GET['test']) {
		case 'knownip':
			$knowniptest = TRUE;
			$knownip = $_GET['knownip'];
			$strLogTesting = 'true';
			break;
		case 'knownzip':
			$knownziptest = TRUE;
			$knownzip = $_GET['knownzip'];
			$strLogTesting = 'true';
			break;
	}
}

// try to get the users IP address	
if ($knowniptest && isset($knownip) ) {
	$userIP = $knownip;
	echo 'Using known IP Address: '.$userIP;
} else {
	$userIP = getIPAddress();
	echo 'Detected IP Address: '.$userIP;
}
echo '<br/><br/>';

if (validateIPAddress($userIP)) {
	/** Valid IP address, so let's try to automatically determine available stations */
	if ($knownziptest && isset($knownzip) ) {
		$zipcode = $knownzip;
		echo 'Using known ZIP code: ' . $zipcode;
		unset($knownzip);
	} else {
		$zipcode = getZipByIP($userIP);
		echo 'ZIP code (from IP): '.$zipcode;
	}
	echo '<br/><br/>';

	if (validateZIPCode($zipcode)) {
		$jArray = getStationArrayByZip($zipcode);
	} else {
		echo 'ERROR BAD ZIP<br/>';
		echo 'The Locator Service was not able to validate the ZIP ('.$zipcode.') associated with your IP address.<br/>';
		echo 'Your information has been recorded.<br/>';
		echo 'Thank you for using the Station Locator Test script<br/>';
		$strLogError = 'ERROR_BAD_ZIP';
		writeLogFile();
	}
} else {
	// IP address was not valid so we need to prompt for ZIP code
	echo 'ERROR BAD IP:';
	echo 'For some reason the IP address we detected did not validate.<br/>';
	echo 'Your information has been recorded.<br/>';
	echo 'Thank you for using the Station Locator Test Script<br/><br/>';
	$strLogError = 'ERROR_BAD_IP';
	writeLogFile();
}

if ($jArray) {
	if ((isset($_GET['debugjarray'])) && $_GET['debugjarray'] == 'true') :
		debugArray($jArray);
		echo '<br/><br/>';
	endif;
	$station_list = getStationByZip($jArray);	
} else { 
	echo 'No station array data was available';
	$strLogError = 'ERROR_EMPTY_JARRAY';
	writeLogFile();
}

if (count($station_list) > 0) {
	displayAvailableStations($station_list);
} else {
	echo 'ERR: NO STATIONS:<br/>';
	echo 'The Locator Service did not find any stations for your ZIP code<br/>';
	echo 'There are many possible reasons why this happened.<br/>';
	echo 'You information has been recorded and will be reviewed<br/>';
	echo 'Thank you for using the Station Locator Test Script<br/>';
	$strLogError = 'ERROR_EMPTY_STATIONLIST';
	writeLogFile();
}

/** FUNCTIONS */
/**
* Makes request to PBS SODOR API for all available stations in a given ZIP code
*
* @return Array of station objects based on ZIP code
*/
function getStationArrayByZip($zip) {
	$callsign_url = SODOR_ENDPOINT.'callsigns/zip/'.$zip.'.json';
	$json = file_get_contents($callsign_url);
	if ($json == FALSE)
	{
		$data = json_decode('{"status": "Request failed."}');
		return false;
	}
	$jArray = json_decode($json, TRUE);
	return $jArray;
}
/**
* Makes request to PBS SODOR API for all available stations in a given IP address
*
* @return Array of station objects based on user IP addres
*/
function getZipByIP($ip) {
	$callsign_url = SODOR_ENDPOINT.'zipcodes/ip/'.$ip.'.json';
	$json = file_get_contents($callsign_url);
	if ($json == FALSE)
	{
		return false;
	}
	$jArray = json_decode($json, TRUE);

	$zipcode = $jArray['$items'][0]['zipcode'];

	return $zipcode;
}
/**
* function to pull hardcoded array elements based on knowledge of JSON
* 
*/
function getStationByZip ($jArray) {
	
	global $station_list;
	
	foreach ($jArray['$items'] as $callsign_map) {
		$callsign = $callsign_map['$links'][0];
		$owner_station = $callsign['$links'][0];		
		$owner_station_callsign = '';
		foreach($owner_station['$links'] as $rel) {
			if ($rel['$relationship'] == 'flagship') {
				$owner_station_callsign = $rel['callsign'];
			}
			if ($owner_station_callsign != '') {
				// Check to see if we've seen this station before
				if (array_key_exists($owner_station_callsign, $station_list)) {
					// we have seen this station before so reprocess it
					$station = $station_list[$owner_station_callsign];
					// Check to see if we've moved up the ranking
					if ($callsign_map['rank'] != '' && $station['rank'] > $callsign_map['rank']) {
						// this callsign has a lower (better) ranking so use it
						$station['rank'] = $callsign_map['rank'];
					}
					if ($callsign_map['confidence'] != '' && $station['confidence'] < $callsign_map['confidence']) {
						// this callsign has a higher (better) confidence so use it
						$station['confidence'] = $callsign_map['confidence'];
					}
					// now add callsign to the list
					$station['callsigns'][]  = $callsign['callsign'];
					$station_list[$owner_station_callsign] = $station;
				} else {
					// create a new station entry and add it to the stations list
					$station = Array();
					$station['flagship'] = $owner_station_callsign;
					$station['confidence'] = $callsign_map['confidence'];
					$station['rank'] = $callsign_map['rank'];
					$station['short_common_name'] = $owner_station['short_common_name'];
					// get SODOR station ID from URL
					$station['id'] = getIDFromURL($owner_station['$self']);
					$station['callsigns'] = Array();
					$station['callsigns'][] = $callsign['callsign'];
					$station_list[$owner_station_callsign] = $station;
				}
			} // end if owner station callsign != ''
		} // end foreach(owner_station)
		unset($rel);
	} // end foreach(jArray)
	unset($callsign_map);
	return $station_list;
}
/** displays the available stations
*
*
*/
function displayAvailableStations($station_list) {
	global $arrLogFlagship, $arrLogRank, $arrLogConfidence, $arrLogStations;
	
	if ((isset($_GET['debugstationlist'])) && $_GET['debugstationlist'] == 'true') :
		debugArray($stations_list);
		echo '<br/><br/>';
	endif;

	echo 'The available stations for your ZIP are: <br/>';
	echo '<table border="1"><tr><td>Primary Callsign</td><td>Locator Service StationID</td><td>Rank</td><td>Confidence</td><td>Available Callsigns</td></tr>';

	foreach ($station_list as $station) {
		echo '<tr><td>'.$station['flagship'].'</td><td>'.$station['id'].'</td><td>'.$station['rank'].'</td><td>'.$station['confidence'].'</td><td>';
		
		for ($i=0; $i < count($station['callsigns']); $i++) {
			echo $station['callsigns'][$i];
			if ($i < count($station['callsigns']) - 1) {
				echo ',';
			}
		}
		echo '</td></tr>';
	}
	unset($station);
	echo '</table>';
	echo '<br/>';
	echo 'You information has been recorded and will be reviewed<br/>';
	echo 'Thank you for using the Station Locator Test Script<br/>';

	writeLogFile();
}

/** utility functions */
/**
* takes a known URL pattern from the JSON and pulls the station id for later use
*
* strrchr pulls the file name (e.g. 107.json)
* substr removes the '/'
* explode on the '.' to create an array
* extract the 0 level of the array
* 
* 
*/
function getIDFromURL($url) {
	// check to be sure it is a URL and if not, return 'NONE'
	if(filter_var($url, FILTER_VALIDATE_URL) === FALSE)	{
		echo('this is not a URL<br/>');
		return;
	} else {
		// if it is a URL, extract the ID
		// example http://services.pbs.org/station/107.json
		// get the characters between the last / and the .
		$rawStationID = explode('.', substr(strrchr($url, "/"), 1));
		$stationID = $rawStationID[0];
		return $stationID;
	}
}
/** attempts to get site visitors IP address to autolocalize them if possible
*
*
*
*/
function getIPAddress() {
	if (isSet($_SERVER)) {
		if (isSet($_SERVER["HTTP_X_FORWARDED_FOR"])) {
			$realip = $_SERVER["HTTP_X_FORWARDED_FOR"];
		} elseif (isSet($_SERVER["HTTP_CLIENT_IP"])) {
			$realip = $_SERVER["HTTP_CLIENT_IP"];
		} else {
			$realip = $_SERVER["REMOTE_ADDR"];
		}
	} else {
		if ( getenv( 'HTTP_X_FORWARDED_FOR' ) ) {
			$realip = getenv( 'HTTP_X_FORWARDED_FOR' );
		} elseif ( getenv( 'HTTP_CLIENT_IP' ) ) {
			$realip = getenv( 'HTTP_CLIENT_IP' );
		} else {
			$realip = getenv( 'REMOTE_ADDR' );
		}
	}
	return $realip;
}
/** attempts to get site visitors IP address to autolocalize them if possible
*
*  requires PHP >= 5.2.0
*
*/
function validateIPAddress($ip) {
	if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
		return TRUE;
	}
	else {
		return FALSE;	
	}
}
/**
* pretty output of the json array created during file import
*
* @return
*/
function debugArray($var = false) {
  echo "\n<pre style=\"background: #FFFF99; font-size: 10px;\">\n";
 
  $var = print_r($var, true);
  echo $var . "\n</pre>\n";
}
/**
* user regular expression to validate ZIP code
*
* @return $match
* 1 is match
* 0 is not a match
*/
function validateZIPCode($zipcode) {
	$match = preg_match("#[0-9]{5}#", $zipcode);
	return $match;
}

function writeLogFile() {
	global $strLogID, $strLogDate, $strLogTesting, $strLogError, $station_list, $arrLogFlagship, $userIP, $zipcode, $ipToFilter, $pathToLogFile;
//	echo "in the log file Date is $strLogDate, testing is $strLogTesting and error is $strLogError, zipcode is $zipcode and userip is $userIP";
//	echo '<br/><br/>';
	$stationCount = 0;
	// write the log file
	if ($userIP != $ipToFilter) {
		if ($strLogError == 'NONE' && $userIP != $ipToFilter) {
			foreach ($station_list as $station) {					
				for ($i=0; $i < count($station['callsigns']); $i++) {
					$strLogFile = $strLogID.'-'.$stationCount.'-'.$i.','.$strLogDate.','.$userIP.','.$zipcode.','.$strLogTesting.','.$strLogError.',';
					$strLogFile .= $station['flagship'].','.$station['rank'].','.$station['confidence'].',';
					$strLogFile .= $station['callsigns'][$i]."\n";
	//				echo $strLogFile.'<br/>';
					error_log($strLogFile, 3, $pathToLogFile);
				}
				$stationCount++;
			}
			unset($station);
		} else {
			$strLogFile = $strLogID.','.$strLogDate.','.$userIP.','.$zipcode.','.$strLogTesting.','.$strLogError.',,,,'."\n";
	//		echo $strLogFile.'<br/>';
			error_log($strLogFile, 3, $pathToLogFile);
		}
	} else {
		echo 'no log entry was written since we are filtering your IP address<br/>';
	}
	return;
}

?>
