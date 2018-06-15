<?php
  /**
   * @copyright  Copyright (C) Clay Freeman. All rights reserved.
   * @license    GNU Lesser General Public License version 3 or later.
   */

  // Prevent unauthorized access to this file outside of the context of a
  // Joomla application
  defined('_JEXEC') or die;

  use \Joomla\CMS\Installer\InstallerScript;

  /**
   * The HTTP/2 Push automated Joomla! system plugin installer script.
   */
  final class plgSystemHttp2PushInstallerScript extends InstallerScript {
    /**
     * Define a minimum acceptable PHP version for this plugin.
     *
     * @var  string
     */
    protected $minimumPhp = '7.1.0';
  }
