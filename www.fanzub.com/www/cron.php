<?php // coding: latin1
/**
 * Fanzub - Cron
 *
 * Copyright 2009 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */
require_once('../lib/class.fanzub.php');

// Abort if load exceeds maximum threshold
#$loadavg = LoadAverage();
#if (isset($config['option']['cron']['loadlimit']) && ($loadavg !== false) && isset($loadavg[1]) && ($loadavg[1] > $config['option']['cron']['loadlimit']))
#	die('<b>Task aborted</b>: load average '.$loadavg[1].' exceeds threshold');

// Get parameters
if (isset($_SERVER['argc']) && isset($_SERVER['argv']) && ($_SERVER['argc'] >= 2))
{
	$object = (isset($_SERVER['argv'][1]) ? strtolower($_SERVER['argv'][1]) : '');
	$id = (isset($_SERVER['argv'][2]) ? intval($_SERVER['argv'][2]) : null);
}
elseif (isset($_REQUEST['worker']))
{
	$object = strtolower($_REQUEST['worker']);
	$id = (isset($_REQUEST['id']) ? intval($_REQUEST['id']) : null);
}
else
	throw new Exception('No worker specified');

// Load worker class
if (strpos($object,'.') !== false)
	throw new Exception('Invalid worker name: '.SafeHTML($object));
if (file_exists($config['path']['lib'].'/worker.'.$object.'.php'))
	require_once($config['path']['lib'].'/worker.'.$object.'.php');
if (!class_exists($object))
	throw new Exception('Unknown worker: '.SafeHTML($object));

// Execute
eval('$worker = new '.$object.'();');
$worker->Run($id);
unset($worker);
?>
