<?php // coding: latin1
/**
 * Fanzub - Help
 *
 * Copyright 2009 Fanzub.com. All rights reserved.
 * Do not distribute this file whole or in part without permission.
 *
 * $Id$
 * @package Fanzub
 */
require_once('../lib/class.fanzub.php');

class HelpController extends Controller
{
  public function ActionDefault()
  {
    $template = new Template();
    switch ($this->action)
    {
      case 'guide':
        $template->title = 'Usenet Guide';
        $template->body = new Template('help_guide');
        break;

      case 'faq':
        $template->title = 'FAQ';
        $template->body = new Template('help_faq');
        break;

      case 'contact':
        $template->title = 'Contact';
        $template->body = new Template('help_contact');
        break;

      default:
        header('Location: '.static::$config['url']['base']);
        exit;
    }
    $template->title .= ' :: ';
    $template->rss = static::$config['url']['rss'];
    $template->searchbox = new Template('searchbox');
    $template->menu = new Template('menu');
    $template->Display('layout');
  }
}

// Display
$controller = new HelpController();
echo $controller->Run();
?>