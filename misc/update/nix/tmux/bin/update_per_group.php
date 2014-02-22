<?php

require_once dirname(__FILE__) . '/../../../config.php';

$c = new ColorCLI();
if (!isset($argv[1])) {
	exit($c->error("This script is not intended to be run manually, it is called from update_threaded.py."));
}

$s = new Sites();
$site = $s->get();
$pieces = explode('  ', $argv[1]);
$groupid = $pieces[0];
$releases = new Releases(true);
$groups = new Groups();
$groupname = $groups->getByNameByID($groupid);
$group = $groups->getByName($groupname);
$consoletools = new ConsoleTools();
$binaries = new Binaries();
$backfill = new Backfill();
$db = new DB();
$path = nZEDb_MISC . 'testing' . DS . 'Dev' . DS;

// Create the connection here and pass, this is for post processing, so check for alternate
$nntp = new NNTP();
if (($site->alternate_nntp == 1 ? $nntp->doConnect(true, true) : $nntp->doConnect()) === false) {
	exit($c->error("Unable to connect to usenet."));
}
if ($site->nntpproxy === "1") {
	usleep(500000);
}

if ($releases->hashcheck == 0) {
	exit($c->error("You must run update_binaries.php to update your collectionhash."));
}

if ($pieces[0] != 'Stage7b') {
	// Update Binaries per group if group is active
	if ($pieces[1] === 1) {
		$binaries->updateGroup($group, $nntp);
	}

	// Backfill per group if group enabled for backfill
	if ($pieces[2] === 1) {
		$backfill->backfillPostAllGroups($nntp, $groupname, 20000, 'normal');
	}

	// Update Releases per group
	try {
		$test = $db->queryOneRow("SHOW TABLE STATUS WHERE name = 'collections_" . $groupid . "'");
		// Don't even process the group if no collections
		if (isset($test['name'])) {
			$releases->processReleasesStage1($groupid);
			$releases->processReleasesStage2($groupid);
			$releases->processReleasesStage3($groupid);
			$retcount = $releases->processReleasesStage4($groupid);
			$releases->processReleasesStage5($groupid);
			$releases->processReleasesStage7a($groupid);
		}
	} catch (PDOException $e) {
		//No collections available
		exit();
	}


	echo $c->header("Processing renametopre for group ${groupid}");
	passthru("php " . $path . "renametopre.php full " . $groupid . " false");
	$postprocess = new PostProcess(true);
	$postprocess->processAdditional(null, null, null, $groupid, $nntp);
	$nfopostprocess = new Nfo(true);
	$nfopostprocess->processNfoFiles(null, null, null, $groupid, $nntp);
	if ($site->nntpproxy != "1") {
		$nntp->doQuit();
	}
} else if ($pieces[0] === 'Stage7b') {
	// Runs functions that run on releases table after all others completed
	$groupid = '';
	$releases->processReleasesStage4dot5($groupid);
	$releases->processReleasesStage6(1, 0, $groupid, $nntp);
	$releases->processReleasesStage7b();
}
