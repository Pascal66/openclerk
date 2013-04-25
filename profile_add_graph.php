<?php

require("inc/global.php");
require("layout/graphs.php");
require_login();

// adding a new graph type?
$graph_type = require_post("type");
$width = require_post("width");
$height = require_post("height");
$page_id = require_post("page");

// make sure this is actually our page
$q = db()->prepare("SELECT * FROM graph_pages WHERE user_id=? AND id=?");
$q->execute(array(user_id(), $page_id));
if (!$q->fetch()) {
	throw new Exception("Cannot find page " . htmlspecialchars($page_id));
}

// only permit valid values
$graph_types = graph_types();
if (!isset($graph_types[$graph_type])) {
	throw new Exception("Invalid graph type '" . htmlspecialchars($graph_type) . "'");
} else if (!is_numeric($width) || $width < 1 || $width > 16) {
	throw new Exception("Invalid width '" . htmlspecialchars($width) . "'");
} else if (!is_numeric($height) || $height < 1 || $height > 16) {
	throw new Exception("Invalid height '" . htmlspecialchars($height) . "'");
} else {
	// it's OK - let's add a new one
	// first get the highest page order graph so far on this page
	$q = db()->prepare("SELECT * FROM graphs WHERE page_id=? ORDER BY page_order DESC LIMIT 1");	// including is_removed (in case of restore)
	$q->execute(array($page_id));
	$highest = $q->fetch();
	$new_order = $highest ? ($highest['page_order'] + 1) : 1;

	// now insert it
	$q = db()->prepare("INSERT INTO graphs SET page_id=:page_id, page_order=:page_order, graph_type=:graph_type, width=:width, height=:height");
	$q->execute(array(
		'page_id' => $page_id,
		'page_order' => $new_order,
		'graph_type' => $graph_type,
		'width' => $width,
		'height' => $height,
	));

	// redirect
	redirect(url_for('profile', array('page' => $page_id)));
}