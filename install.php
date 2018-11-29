<?php
/**
 * This install script ensures that all dependencies are met.
 *
 * @author     Clay Freeman <git@clayfreeman.com>
 * @copyright  2018 Bluewall, LLC. All rights reserved.
 * @license    GNU General Public License v3 (GPL-3.0).
 */

use \Joomla\CMS\Factory;
use \Joomla\CMS\Installer\InstallerAdapter;
use \Joomla\CMS\Installer\InstallerScript;
use \Joomla\CMS\Language\Text;

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

  /**
   * Check that a class exists by name using the provided symbol.
   *
   * This method intentionally forbids autoloading since the symbol should be
   * provided by PHP core.
   *
   * @param   string  $symbol  A string containing the class name.
   *
   * @return  bool             `TRUE` if the class exists,
   *                           `FALSE` otherwise.
   */
  protected function classExists(string $symbol): bool {
    $exists = \class_exists($symbol, FALSE);
    if (!$exists) {
      Factory::getApplication()->enqueueMessage(
        Text::sprintf('PLG_SYSTEM_HTTP2PUSH_DEPENDENCY',
        \htmlentities($symbol)), 'error');
    }
    return $exists;
  }

  /**
   * Check that a function exists by name using the provided symbol.
   *
   * @param   string  $symbol  A string containing the function name.
   *
   * @return  bool             `TRUE` if the function exists,
   *                           `FALSE` otherwise.
   */
  protected function functionExists(string $symbol): bool {
    $exists = \function_exists($symbol);
    if (!$exists) {
      Factory::getApplication()->enqueueMessage(
        Text::sprintf('PLG_SYSTEM_HTTP2PUSH_DEPENDENCY',
        \htmlentities($symbol)), 'error');
    }
    return $exists;
  }

  /**
   * Checks that the required PHP API's are installed.
   *
   * This plugin depends on the `\DOMDocument` and `\SimpleXMLElement` classes
   * and the `\simplexml_import_dom()` and `\libxml_use_internal_errors()`
   * functions available in PHP's XML extension on many distributions or by
   * statically compiling PHP with the `--enable-dom` and
   * `--enable-simplexml` flags.
   *
   * @param   string            $type    The type of change (install,
   *                                     update, ...).
   * @param   InstallerAdapter  $parent  The class calling this method.
   *
   * @return  bool                       `TRUE` if all prerequisites are
   *                                     satisfied, `FALSE` otherwise
   */
  public function preflight(string $type, InstallerAdapter $parent): bool {
    // Check a list of classes that are required for this plugin to work
    $classes = \array_map([$this, 'classExists'], [
      '\\DOMDocument',
      '\\SimpleXMLElement'
    ]);
    // Check a list of functions that are required for this plugin to work
    $functions = \array_map([$this, 'functionExists'], [
      '\\simplexml_import_dom',
      '\\libxml_use_internal_errors'
    ]);
    // Ensure that all of the required symbols exist
    return !\in_array(FALSE, \array_merge($classes, $functions), TRUE);
  }
}
