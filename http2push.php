<?php declare(strict_types = 1);
/**
 * This file is responsible for facilitating HTTP/2 preloads.
 *
 * This plugin generates preload/preconnect information for the 'Link' header so
 * that the web server can push resources to the client using HTTP/2.
 *
 * @author     Clay Freeman <git@clayfreeman.com>
 * @copyright  2018 Bluewall, LLC. All rights reserved.
 * @license    GNU General Public License v3 (GPL-3.0).
 */

use \Joomla\CMS\Factory;
use \Joomla\CMS\Plugin\CMSPlugin;

/**
 * The HTTP/2 Push automated Joomla! system plugin.
 */
final class plgSystemHttp2Push extends CMSPlugin {
  /**
   * A reference to Joomla's application instance.
   *
   * @var  \Joomla\CMS\Application\CMSApplication
   */
  protected $app;

  /**
   * A mapping of element type to an associated 'Link' header clause renderer.
   *
   * This array is keyed by the element type (tag name; e.g. 'img' or 'link')
   * and the value consists of a `callable` referring to the method handler.
   *
   * @var  array
   */
  protected $methods;

  /**
   * Fetches a reference to Joomla's application instance and calls the
   * constructor of the parent class.
   */
  public function __construct(&$subject, $config = array()) {
    // Fetch a reference to Joomla's application instance
    $this->app = Factory::getApplication();
    // Initialize the class method mapping
    $this->methods = [
      'img'        => \Closure::fromCallable([$this, 'processImage']),
      'link'       => \Closure::fromCallable([$this, 'processStyle']),
      'preconnect' => \Closure::fromCallable([$this, 'processPreconnect']),
      'script'     => \Closure::fromCallable([$this, 'processScript'])
    ];
    // Call the parent class constructor to finish initializing the plugin
    parent::__construct($subject, $config);
  }

  /**
   * Builds a canonicalized file path (with query, if provided).
   *
   * This method is useful for generating absolute file paths for the 'Link'
   * header where the hostname is not required.
   *
   * @param   array   $cpts  An array of URL components from `parse_url(...)`.
   *
   * @return  string         A localized path-only absolute URL.
   */
  public function buildFilePath(array $cpts): ?string {
    return (isset($cpts['path']) ? $cpts['path'].
      (isset($cpts['query']) ? '?'.$cpts['query'] : '') : null);
  }

  /**
   * Builds a canonicalized host-only URL.
   *
   * This method will omit any path related information so that only
   * connectivity information is conveyed in the resulting URL.
   *
   * @param   array   $cpts  An array of URL components from `parse_url(...)`.
   *
   * @return  string         A canonicalized host-only URL.
   */
  public function buildHostURL(array $cpts): ?string {
    // Create a substring representing any available login information
    $user  = ($cpts['user'] ?? '');
    $pass  = ($cpts['pass'] ?? '');
    $auth  = $user.(\strlen($pass) > 0 ? ':'.$pass : '');
    $auth .= (\strlen($auth) > 0 ? '@' : '');
    // Attempt to determine whether the default scheme should be HTTPS
    $default_scheme = (!empty($_SERVER['HTTPS']) &&
      $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
    // Determine the scheme of the resulting URL
    $scheme = ($cpts['scheme'] ?? $default_scheme);
    // Determine the port of the resulting URL
    $port = ($cpts['port'] ?? '');
    $port = (($scheme === 'http'  && $port ==  80) ? '' :
            (($scheme === 'https' && $port == 443) ? '' : $port));
    $port = (\strlen($port) > 0 ? ':'.$port : '');
    // Assemble the constituent components of this URL
    return (isset($cpts['host']) ? $scheme.'://'.$auth.
      $cpts['host'].$port : null);
  }

  /**
   * Builds a 'Link' header value from an array of resource clauses.
   *
   * If `$limit` is true, any clauses that cause the full 'Link' header to
   * overrun 8 KiB will be ignored.
   *
   * @param   array   $clauses  An array of pre-rendered 'Link' clauses.
   * @param   bool    $limit    Whether to limit the size of the result.
   *
   * @return  string            A 'Link' header value.
   */
  protected function buildLinkHeader(array $clauses, bool $limit): string {
    // Calculate the max value length of the Link header (just short of 8 KiB)
    $max = (8192 - \strlen("Link: \r\n"));
    // Reduce the clause list until the header size is under max if desired
    while (\strlen($link = \implode(', ', $clauses)) > $max && $limit) {
      // Attempt to remove a clause and try again
      \array_pop($clauses);
    }
    // Return the resulting 'Link' header value
    return $link;
  }

  /**
   * Extract resource candidate descriptors from the document body.
   *
   * The document body is searched with XPath to find all resources which may be
   * applicable for preload/preconnect. '<link>' tags are not filtered at this
   * stage for `rel='stylesheet'` equivalence.
   *
   * Once a result set is obtained using XPath, each resource candidate is
   * reduced to a simplified descriptor that details how the resource should be
   * handled when rendering its clause for the 'Link' header.
   *
   * @param   SimpleXMLElement  $body  A document containing preload/preconnect
   *                                   resource candidates.
   *
   * @return  array                    An array of objects describing how each
   *                                   resource should be handled.
   */
  protected function extractResources(\SimpleXMLElement $body): array {
    // First, locate all possible resources for preload/preconnect
    $res = $body->xpath('//img[@src]|//link[@href and @rel]|//script[@src]');
    // Reduce the array of resource candidates to a list of item descriptors
    return \array_map(function(\SimpleXMLElement $item): object {
      // Fetch the name of the element node later used to satisfy
      // element-specific preconditions
      $type = \strtolower($item->getName());
      // Canonicalize the 'rel' attribute to the best of our ability
      $rel = \strtolower(\strval($item['rel'] ?? ''));
      // Attempt to determine the name of the attribute holding the URL
      $attr = ($type === 'link' ? 'href' : 'src');
      // Verify that the source URL can be found before continuing
      if (isset($item[$attr]) && $item[$attr] !== '') {
        // Fetch the URL from the item and sanitize it
        $url = $this->sanitizeURL(\strval($item[$attr]));
      }
      // Create an item descriptor to describe how it should be rendered
      return (object)['rel' => $rel, 'type' => $type, 'url' => $url ?? ''];
    }, $res);
  }

  /**
   * Determines whether the host portion of a URL matches this server.
   *
   * This method canonicalizes the host-only portion of the provided URL and
   * compares it to a host URL for this server. If the two URL's match, then the
   * provided URL represents a "self-hosted" resource.
   *
   * If there is no host component in the provided argument, then the URL is
   * self-hosted.
   *
   * @param   array  $cpts  An array of URL components for a possible
   *                        self-hosted resource.
   *
   * @return  bool          Whether the provided URL is self-hosted.
   */
  public function isSelfHosted(array $cpts): bool {
    if (isset($cpts['host'])) {
      // Build a host URL from the provided URL components
      $target = $this->buildHostURL($cpts);
      // Build a host URL using the server environment for this request
      $server = $this->buildHostURL([
        'host' => ($_SERVER['SERVER_NAME']   ?? ''),
        'port' => ($_SERVER['SERVER_PORT']   ?? ''),
        'user' => ($_SERVER['PHP_AUTH_USER'] ?? ''),
        'pass' => ($_SERVER['PHP_AUTH_PW']   ?? '')
      ]);
      // If the target and server host URL's match, the provided URL
      // is self-hosted
      return (isset($target, $server) && $target === $server);
    }
    // Assume that the URL is self hosted if there is no host component
    return true;
  }

  /**
   * Analyzes the rendered HTTP response body for possible HTTP/2 push items.
   *
   * This method is triggered on the 'onAfterRender' Joomla! system plugin
   * event. Once triggered, the application's HTTP response body should be
   * available for parsing.
   *
   * The response is parsed to find external script tags, stylesheets, and
   * images that can be preloaded (or preconnected) using HTTP/2 server push.
   *
   * After finding all applicable resources, their URL's are parsed for validity
   * and a 'Link' header is generated to inform the web server of the
   * push-capable resources.
   */
  public function onAfterRender(): void {
    // Don't execute this plugin on the back end; we don't want the site to
    // accidentally become administratively inaccessible due to this plugin
    if (!$this->app->isAdmin()) {
      // Attempt to parse the document body into a `SimpleXMLElement` instance
      $document = $this->parseDocumentBody($this->app->getBody());
      // Ensure that the document body was successfully parsed before running
      if ($document instanceof \SimpleXMLElement) {
        // Extract and prepare all applicable resources from the parsed
        // document body
        $resources = $this->extractResources($document);
        $resources = $this->prepareResources($resources);
        // Build the 'Link' header, keeping the configured size limit in check
        $limit  = \boolval($this->params->get('header_limit', false));
        $header = $this->buildLinkHeader($resources, $limit);
        // Set the header using the Joomla! application framework
        $this->app->setHeader('Link', $header, false);
      }
    }
  }

  /**
   * Attempt to parse the provided HTML document body into a SimpleXMLElement.
   *
   * If the provided document body is non-null and non-empty, this method will
   * attempt to silently parse it using `DOMDocument::loadHTML()`. Error
   * reporting is disabled to prevent overflow of `stderr` in FPM-based hosting
   * environments.
   *
   * Once the `DOMDocument` instance has successfully parsed the document body,
   * it is then converted to a `SimpleXMLElement` instance so that XPath is
   * supported.
   *
   * @param   string            $body  An HTML document body to be parsed.
   *
   * @return  SimpleXMLElement         `SimpleXMLElement` instance on success,
   *                                   `NULL` on failure.
   */
  protected function parseDocumentBody(?string $body): ?\SimpleXMLElement {
    // Create a DOMDocument instance to facilitate parsing the document body and
    // subsequent conversion to a SimpleXMLElement instance
    $document = new \DOMDocument();
    // Ensure that the document body is a non-empty string before parsing it
    if (\is_string($body) && \strlen($body) > 0) {
      // Configure libxml to use its internal logging mechanism and preserve the
      // current libxml logging preference for later restoration
      $logging = \libxml_use_internal_errors(true);
      // Attempt to parse the document body for conversion
      if ($document->loadHTML($body) === TRUE) {
        // Attempt to import the DOMDocument tree into a SimpleXMLElement
        // instance (so that XPath can be used)
        $document = \simplexml_import_dom($document);
      }
      // Restore the previous logging preference for libxml
      \libxml_use_internal_errors($logging);
    }
    // Check if the document body was parsed and converted successfully
    return $document instanceof \SimpleXMLElement ? $document : NULL;
  }

  /**
   * Create a unique array of 'Link' header clauses for each given resource.
   *
   * This method filters non-applicable '<link>' tags from the provided array.
   *
   * Resources with URL's that are not self hosted are converted to preconnect
   * items since remote resources cannot be preloaded.
   *
   * @param   array  $resources  An array of resource descriptors.
   *
   * @return  array              An array of strings representing 'Link'
   *                             header clauses.
   */
  protected function prepareResources(array $resources): array {
    // Map each applicable resource to a 'Link' header clause
    return \array_unique(\array_map(function(object $item): string {
      // Parse the URL into its constituent components
      $url = \parse_url($item->url);
      // Determine whether this resource should be converted to a preconnect
      $type = $this->isSelfHosted($url) ? $item->type : 'preconnect';
      // Render this item using the appropriate method handler
      return $this->methods[$type]($url);
    }, \array_filter($resources, function(object $item): bool {
      // Ensure that this item contains a valid URL
      $valid  = \is_string($item->url) && \strlen($item->url) > 0;
      // Ensure that the item type has a method handler mapping
      $valid &= (\array_key_exists($item->type, $this->methods));
      // Verify that '<link>' tags are stylesheets before continuing
      $valid &= ($item->type !== 'link' || $item->rel === 'stylesheet');
      return \boolval($valid);
    })));
  }

  /**
   * Formats the provided URL as a single 'Link' header image preload.
   *
   * @param   array   $cpts  A URL referring to an image.
   *
   * @return  string         A 'Link' header clause.
   */
  public function processImage(array $cpts): string {
    // Convert the URL to a path-only URL
    $url = $this->buildFilePath($cpts);
    return '<'.$url.'>; rel=preload; as=image';
  }

  /**
   * Formats the provided host as a single 'Link' header preconnect.
   *
   * @param   array   $cpts  A URL referring to a resource.
   *
   * @return  string         A 'Link' header clause.
   */
  public function processPreconnect(array $cpts): string {
    // Convert the URL to a host-only URL
    $url = $this->buildHostURL($cpts);
    return '<'.$url.'>; rel=preconnect';
  }

  /**
   * Formats the provided URL as a single 'Link' header script preload.
   *
   * @param   array   $cpts  A URL referring to a script.
   *
   * @return  string         A 'Link' header clause.
   */
  public function processScript(array $cpts): string {
    // Convert the URL to a path-only URL
    $url = $this->buildFilePath($cpts);
    return '<'.$url.'>; rel=preload; as=script';
  }

  /**
   * Formats the provided URL as a single 'Link' header style preload.
   *
   * @param   array   $cpts  A URL referring to a stylesheet.
   *
   * @return  string         A 'Link' header clause.
   */
  public function processStyle(array $cpts): string {
    // Convert the URL to a path-only URL
    $url = $this->buildFilePath($cpts);
    return '<'.$url.'>; rel=preload; as=style';
  }

  /**
   * Attempts to sanitize the provided URL for consistency.
   *
   * If the provided URL fails sanitization, `null` is returned instead.
   *
   * @param   string  $url  The input URL to be sanitized.
   *
   * @return  string        The resulting sanitized URL.
   */
  public function sanitizeURL(string $url): ?string {
    return \filter_var($url, \FILTER_SANITIZE_URL) ?: null;
  }
}
