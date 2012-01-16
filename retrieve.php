<?php
error_reporting(E_ALL & ~8192 & ~E_USER_WARNING);	# 8192 == E_DEPRECATED maar PHP < 5.3 heeft die niet

/*
 * If we are run from another directory, try to change the current
 * working directory to a directory the script is in
 */
if (@!file_exists(getcwd() . '/' . basename($argv[0]))) {
	chdir(dirname(__FILE__));
} # if

require_once "lib/SpotTranslation.php";
require_once "lib/SpotClassAutoload.php";
try {
	require_once "settings.php";
} 
catch(InvalidOwnSettingsSettingException $x) {
	echo "There is an error in your ownsettings.php" . PHP_EOL . PHP_EOL;
	echo $x->getMessage() . PHP_EOL;
	die();
} # InvalidOwnSettingsSetting

require_once "lib/SpotTiming.php";
require_once "lib/exceptions/ParseSpotXmlException.php";
require_once "lib/exceptions/NntpException.php";

/*
 * disable timing, all queries which are ran by retrieve this would make it use
 * large amounts of memory
 */
SpotTiming::disable();

# Initialize commandline arguments
SpotCommandline::initialize(array('force', 'debug', 'retro'), array('force' => false, 'debug' => false, 'retro' => false));

# Initialize translation to english 
SpotTranslation::initialize('en_US');

# in safe mode, max execution time cannot be set, warn the user
if (ini_get('safe_mode') ) {
	echo "WARNING: PHP safemode is enabled, maximum execution cannot be reset! Turn off safemode if this causes problems" . PHP_EOL . PHP_EOL;
} # if

try {
	$db = new SpotDb($settings['db']);
	$db->connect();
} 
catch(DatabaseConnectionException $x) {
	die("Unable to connect to database: " . $x->getMessage() . PHP_EOL);
} # catch

# Creer het settings object
$settings = SpotSettings::singleton($db, $settings);

# Controleer dat we niet een schema upgrade verwachten
if (!$settings->schemaValid()) {
	die("Database schema is gewijzigd, draai upgrade-db.php aub" . PHP_EOL);
} # if

# Controleer eerst of de settings versie nog wel geldig zijn
if (!$settings->settingsValid()) {
	die("Globale settings zijn gewijzigd, draai upgrade-db.php aub" . PHP_EOL);
} # if

$req = new SpotReq();
$req->initialize($settings);

# We willen alleen uitgevoerd worden door een user die dat mag als
# we via de browser aangeroepen worden. Via console halen we altijd
# het admin-account op
$spotUserSystem = new SpotUserSystem($db, $settings);
if (!SpotCommandline::isCommandline()) {
	# Vraag de API key op die de gebruiker opgegeven heeft
	$apiKey = $req->getDef('apikey', '');
	
	$userSession = $spotUserSystem->verifyApi($apiKey);

	if (($userSession == false) || (!$userSession['security']->allowed(SpotSecurity::spotsec_retrieve_spots, ''))) { 
		die("Access denied");
	} # if
	
	# Add the user's ip addres, we need it for sending notifications
	$userSession['session'] = array('ipaddr' => '');
} else {
	$userSession['user'] = $db->getUser(SPOTWEB_ADMIN_USERID);
	$userSession['security'] = new SpotSecurity($db, $settings, $userSession['user'], '');
	$userSession['session'] = array('ipaddr' => '');
} # if

if ($req->getDef('output', '') == 'xml') {
	echo "<xml>";
} # if

# We vragen de nntp_hdr settings alvast op
$settings_nntp_hdr = $settings->get('nntp_hdr');
if (empty($settings_nntp_hdr['host'])) {
	die("Unable to continue: You did not setup any newsserver yet." . PHP_EOL);
} # if
	
## Als we forceren om de "already running" check te bypassen, doe dat dan
if (SpotCommandline::get('force')) {
	$db->setRetrieverRunning($settings_nntp_hdr['host'], false);
} # if

## Moeten we debugloggen? Kan alleen als geen --force opgegeven wordt
$debugLog = SpotCommandline::get('debug');

## RETRO MODE! Hiermee kunnen we de fullspots, fullcomments en/of cache achteraf ophalen
$retroMode = SpotCommandline::get('retro');

## Retention cleanup
try {
	if ($settings->get('retention') > 0 && !$retroMode) {
		$db->deleteSpotsRetention($settings->get('retention'));
	} # if
} catch(Exception $x) {
	echo PHP_EOL . PHP_EOL;
	echo 'SpotWeb v' . SPOTWEB_VERSION . ' on PHP v' . PHP_VERSION . ' crashed' . PHP_EOL . PHP_EOL;
	echo "Fatal error occured while cleaning up messages due to retention:" . PHP_EOL;
	echo "  " . $x->getMessage() . PHP_EOL;
	echo PHP_EOL . PHP_EOL;
	echo $x->getTraceAsString();
	echo PHP_EOL . PHP_EOL;
	die();
} # catch

$newSpotCount = 0;
$newCommentCount = 0;
$newReportCount = 0;
$retriever = null;

## Spots
try {
	/*
	 * Actually retrieve spots from the server
	 */
	$retriever = new SpotRetriever_Spots($settings_nntp_hdr, 
										 $db, 
										 $settings,										 
										 $req->getDef('output', ''),
										 $debugLog,
										 $retroMode);
	$newSpotCount = $retriever->perform();

	## Creating filter counts
	if ($newSpotCount > 0) {
		$spotsOverview = new SpotsOverview($db, $settings);
		echo 'Calculating how many spots are new';
		$spotsOverview->cacheNewSpotCount();
		echo ', done.' . PHP_EOL;
	} # if

	/*
	 * Should we retrieve comments?
	 */
	if ($settings->get('retrieve_comments')) {
		$retriever = new SpotRetriever_Comments($settings_nntp_hdr, 
												$db,
												$settings,
												$req->getDef('output', ''),
												$debugLog,
												$retroMode);
		$newCommentCount = $retriever->perform();
	} # if

	/*
	 * Retrieval of reports
	 */
	if ($settings->get('retrieve_reports') && !$retroMode) {
		$retriever = new SpotRetriever_Reports($settings_nntp_hdr, 
												$db,
												$settings,
												$req->getDef('output', ''),
												$debugLog);
		$newReportCount = $retriever->perform();
	} # if
	
	/*
	 * SpotStateList cleanup
	 */
	$db->cleanSpotStateList();

	if (!$retroMode) {
		$db->cleanCache(30);
	} # if
}
catch(RetrieverRunningException $x) {
       echo PHP_EOL . PHP_EOL;
       die("retriever.php draait al, geef de parameter '--force' mee om te forceren." . PHP_EOL);
}
catch(NntpException $x) {
	echo PHP_EOL . PHP_EOL;
	echo 'SpotWeb v' . SPOTWEB_VERSION . ' on PHP v' . PHP_VERSION . ' crashed' . PHP_EOL . PHP_EOL;
	echo "Fatal error occured while connecting to the newsserver:" . PHP_EOL;
	echo "  (" . $x->getCode() . ") " . $x->getMessage() . PHP_EOL;
	echo PHP_EOL . PHP_EOL;
	echo $x->getTraceAsString();
	echo PHP_EOL . PHP_EOL;

	if (!empty($retriever)){
		echo "Updating retrieve status in the database" . PHP_EOL . PHP_EOL;
		$retriever->quit();
	}
	die();
}
catch(Exception $x) {
	echo PHP_EOL . PHP_EOL;
	echo 'SpotWeb v' . SPOTWEB_VERSION . ' on PHP v' . PHP_VERSION . ' crashed' . PHP_EOL . PHP_EOL;
	echo "Fatal error occured retrieving reports:" . PHP_EOL;
	echo "  " . $x->getMessage() . PHP_EOL . PHP_EOL;
	echo PHP_EOL . PHP_EOL;
	echo $x->getTraceAsString();
	echo PHP_EOL . PHP_EOL;
	die();
} # catch


## External blacklist
$settings_external_blacklist = $settings->get('external_blacklist');
if ($settings_external_blacklist) {
	try {
		$spotsOverview = new SpotsOverview($db, $settings);
		# haal de blacklist op
		list($http_code, $blacklist) = $spotsOverview->getFromWeb($settings->get('blacklist_url'), false, 30*60);

		if ($http_code == 304) {
			echo "Blacklist not modified, no need to update" . PHP_EOL;
		} elseif (strpos($blacklist['content'],">")) {
			echo "Error, blacklist does not have expected layout!" . PHP_EOL;
		} else {
			# update de blacklist
			$blacklistarray = explode(chr(10),$blacklist['content']);
			
			# Perform a very small snaity check on the blacklist
			if ((count($blacklistarray) > 5) && (strlen($blacklistarray[0]) < 10)) {
				$updateblacklist = $db->updateExternalBlacklist($blacklistarray);
				echo "Finished updating blacklist. Added " . $updateblacklist['added'] . ", removed " . $updateblacklist['removed'] . ", skipped " . $updateblacklist['skipped'] . " of " . count($blacklistarray) . " lines." . PHP_EOL;
			} else {
				echo "Blacklist is probably corrupt, skipping" . PHP_EOL;
			} # else				
		}
	} catch(Exception $x) {
		echo "Fatal error occured while updating blacklist:" . PHP_EOL;
		echo "  " . $x->getMessage() . PHP_EOL;
		echo PHP_EOL . PHP_EOL;
		echo $x->getTraceAsString();
		echo PHP_EOL . PHP_EOL;
	}
} # if

## Statistics
if ($settings->get('prepare_statistics') && $newSpotCount > 0) {
	$spotsOverview = new SpotsOverview($db, $settings);
	$spotImage = new SpotImage($db);
	$spotsOverview->setActiveRetriever(true);

	echo "Starting to create statistics " . PHP_EOL;
	foreach ($spotImage->getValidStatisticsLimits() as $limitValue => $limitName) {
		# Reset timelimit
		set_time_limit(60);

		foreach($settings->get('system_languages') as $language => $name) {
			foreach ($spotImage->getValidStatisticsGraphs() as $graphValue => $graphName) {
				$spotsOverview->getStatisticsImage($graphValue, $limitValue, $settings_nntp_hdr, $language);
			} # foreach graph
		} # foreach language
		echo "Finished creating statistics " . $limitName . PHP_EOL;
	} # foreach limit

	echo PHP_EOL;
} # if

# Verstuur notificaties
$spotsNotifications = new SpotNotifications($db, $settings, $userSession);
$spotsNotifications->sendRetrieverFinished($newSpotCount, $newCommentCount, $newReportCount);

if ($req->getDef('output', '') == 'xml') {
	echo "</xml>";
} # if
