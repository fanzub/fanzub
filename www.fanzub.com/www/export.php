<?php // coding: latin1
/**
 * Fanzub - Export
 *
 * Copyright 2011 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */
require_once('../lib/class.fanzub.php');
require_once('../lib/class.render.php');

class ExportController extends Controller
{
  public function ActionDefault()
  {
    header('Content-Type: text/plain; charset=utf-8');
    ob_start('ob_gzhandler');
    $render = new ExportRender($GLOBALS['config']['url']['base']);
    echo $render->View();
  }
}

// Display
$controller = new ExportController();
echo $controller->Run();
?>