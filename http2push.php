<?php
  /**
   * @copyright  Copyright (C) Clay Freeman. All rights reserved.
   * @license    GNU Lesser General Public License version 3 or later.
   */

  // Prevent unauthorized access to this file outside of the context of a
  // Joomla application
  defined('_JEXEC') or die;

  use \Joomla\CMS\Factory;
  use \Joomla\CMS\Plugin\CMSPlugin;

  /**
   * The HTTP/2 Push automated Joomla! system plugin.
   */
  class plgSystemHttp2Push extends CMSPlugin {
    /**
     * A reference to Joomla's application instance.
     *
     * @var  \Joomla\CMS\Application\CMSApplication
     */
    protected $app;

    /**
     * Fetches a reference to Joomla's application instance and calls the
     * constructor of the parent class.
     */
    public function __construct(&$subject, $config = array()) {
      // Fetch a reference to Joomla's application instance
      $this->app = Factory::getApplication();
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
      $auth  = $user.(strlen($pass) > 0 ? ':'.$pass : '');
      $auth .= (strlen($auth) > 0 ? '@' : '');
      // Attempt to determine whether the default scheme should be HTTPS
      $default_scheme = (!empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
      // Determine the scheme of the resulting URL
      $scheme = ($cpts['scheme'] ?? $default_scheme);
      // Determine the port of the resulting URL
      $port = ($cpts['port'] ?? '');
      $port = (($scheme === 'http'  && $port ==  80) ? '' :
              (($scheme === 'https' && $port == 443) ? '' : $port));
      $port = (strlen($port) > 0 ? ':'.$port : '');
      // Assemble the constituent components of this URL
      return (isset($cpts['host']) ? $scheme.'://'.$auth.
        $cpts['host'].$port : null);
    }


    /**
     * Determines whether the host portion of a URL matches this server.
     *
     * This method canonicalizes the host-only portion of the provided URL and
     * compares it to a host URL for this server. If the two URL's match, then
     * the provided URL represents a "self-hosted" resource.
     *
     * @param   string  $url  A URL for a possible self-hosted resource.
     *
     * @return  bool          Whether the provided URL is self-hosted.
     */
    public function isSelfHosted(string $url): bool {
      $target = $this->buildHostURL(parse_url($url));
      $server = $this->buildHostURL([
        'host' => ($_SERVER['SERVER_NAME']   ?? ''),
        'port' => ($_SERVER['SERVER_PORT']   ?? ''),
        'user' => ($_SERVER['PHP_AUTH_USER'] ?? ''),
        'pass' => ($_SERVER['PHP_AUTH_PW']   ?? '')
      ]);
      return isset($target, $server) && $target === $server;
    }

    /**
     * Analyzes the rendered HTTP response body for possible HTTP/2 push items.
     *
     * This method is triggered on the 'onAfterRender' Joomla! system plugin
     * event. Once triggered, the application's HTTP response body should be
     * available for parsing.
     *
     * The response is parsed to find external script tags, stylesheets, and
     * images as resources that can be preloaded or preconnected using HTTP/2
     * server push.
     *
     * After finding all applicable resources, their URL's are parsed for
     * validity and a 'Link' header is generated to inform the web server of
     * the push-capable resources.
     */
    public function onAfterRender(): void {
      if (!$this->app->isAdmin()) {
        // Create an array of ready-to-use 'Link' header clauses
        $resources = [];
        // Fetch the rendered application response body
        $response  = new \DOMDocument();
        $response->loadHTML($this->app->getBody());
        $response  = simplexml_import_dom($response);
        // Extract all applicable external resources from the DOM
        $search    = $response->xpath('//script[@src]|'.
          '//link[@href and @rel]|//img[@src]');
        foreach ($search as $item) {
          // Process each item based on its element name
          if (strtolower($item->getName()) === 'img') {
            // Sanitize the URL so that it is formatted in a predictable manner
            $url = $this->sanitizeURL($item['src']);
            $callback = [$this, 'processImage'];
          } else if (strtolower($item->getName()) === 'link') {
            // Skip link elements that aren't stylesheets
            if (strtolower($item['rel']) !== 'stylesheet') continue;
            // Sanitize the URL so that it is formatted in a predictable manner
            $url = $this->sanitizeURL($item['href']);
            $callback = [$this, 'processStyle'];
          } else if (strtolower($item->getName()) === 'script') {
            // Sanitize the URL so that it is formatted in a predictable manner
            $url = $this->sanitizeURL($item['src']);
            $callback = [$this, 'processScript'];
          }
          // Attempt to parse the URL into its constituent components
          if (($url_components = parse_url($url ?? '')) !== FALSE) {
            // Check if this URL should be treated as a preconnect ...
            if (isset($url_components['host']) && !$this->isSelfHosted($url)) {
              // Add this URL to the resource list
              $resources[] = $this->processPreconnect(
                $this->buildHostURL($url_components));
            // ... or a preloaded file
            } else {
              // Add this URL to the resource list
              $resources[] = $callback(
                $this->buildFilePath($url_components));
            }
          }
        }
        $link = implode(', ', $resources);
        // Determine whether we should limit the size of the 'Link' header
        if ($this->params->get('header_limit', false)) {
          // Reduce the resource list until the header size is <= 8 KiB
          while (strlen($link = implode(', ', $resources)) > 8184) {
            array_pop($resources);
          }
        }
        // Update the list of HTTP/2 push resources via the 'Link' header
        $this->app->setHeader('Link', $link, false);
      }
    }

    /**
     * Formats the provided path as a single 'Link' header image preload.
     *
     * @param   string  $path  A path to an image.
     *
     * @return  string         A 'Link' header clause.
     */
    public function processImage(string $path): string {
      return '<'.$path.'>; rel=preload; as=image';
    }

    /**
     * Formats the provided host as a single 'Link' header preconnect.
     *
     * @param   string  $host  A host-only URL.
     *
     * @return  string         A 'Link' header clause.
     */
    public function processPreconnect(string $host): string {
      return '<'.$host.'>; rel=preconnect';
    }

    /**
     * Formats the provided path as a single 'Link' header script preload.
     *
     * @param   string  $path  A path to a script.
     *
     * @return  string         A 'Link' header clause.
     */
    public function processScript(string $path): string {
      return '<'.$path.'>; rel=preload; as=script';
    }

    /**
     * Formats the provided path as a single 'Link' header style preload.
     *
     * @param   string  $path  A path to a stylesheet.
     *
     * @return  string         A 'Link' header clause.
     */
    public function processStyle(string $path): string {
      return '<'.$path.'>; rel=preload; as=style';
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
      return filter_var($url, FILTER_SANITIZE_URL) ?: null;
    }
  }
