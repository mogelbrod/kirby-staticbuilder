<?php
// vim: et:ts=4:sts=4:sw=4

namespace Kirby\StaticBuilder;

use A;
use C;
use Exception;
use F;
use Folder;
use Page;
use Pages;
use Response;
use Site;
use Str;
use Tpl;
use Url;

/**
 * Static HTML builder class for Kirby CMS
 * Exports page content as HTML files, and copies assets.
 */
class Builder
{
    // Used for building relative URLs
    const URLPREFIX = 'STATICBUILDER_URL_PREFIX';

    // Kirby instance
    protected $kirby;

    // Project root
    protected $root;

    // Language codes
    protected $langs = [];

    // Config (there is a 'staticbuilder.[key]' for each one)
    protected $outputdir     = 'static';
    protected $baseurl       = '/';
    protected $routes        = ['*'];
    protected $excluderoutes = ['staticbuilder*'];
    protected $assets        = ['assets', 'content', 'thumbs'];
    protected $extension     = '/index.html';
    protected $filter        = null;
    protected $uglyurls      = false;
    protected $withfiles     = false;
    protected $withredirects = false;
    protected $catcherror    = true;

    // Callable for PHP Errors
    public $shutdown;
    public $lastpage;

    // Storing results
    public $summary = [];
    public $lastmodified = null;

    // Callbacks to execute after an item has been built
    protected $onLogCallbacks = [];

    /**
     * Builder constructor.
     * Resolve config and stuff.
     * @throws Exception
     */
    public function __construct()
    {
        // Signal to templates & controllers that we're running a static build
        define('STATIC_BUILD', true);

        // Kirby instance with some hacks
        $kirby = $this->kirbyInstance();

        // Project root
        $this->root = $this->normalizeSlashes($kirby->roots()->index);

        // Multilingual
        if ($kirby->site()->multilang()) {
            foreach ($kirby->site()->languages() as $language) {
                $this->langs[] = $language->code();
            }
        }
        else $this->langs[] = null;

        // Ouptut directory
        $dir = $kirby->get('option', 'staticbuilder.outputdir', $this->outputdir);
        $dir = $this->isAbsolutePath($dir) ? $dir : $this->root . '/' . $dir;
        $folder = new Folder($this->normalizePath($dir));

        if ($folder->exists() === false) {
            $folder->create();
        }
        if ($folder->isWritable() === false) {
            throw new Exception('StaticBuilder: outputdir is not writeable.');
        }
        $this->outputdir = $folder->root();

        // URL root
        $this->baseurl = $kirby->get('option', 'staticbuilder.baseurl', $this->baseurl);

        $this->routes = c::get('staticbuilder.routes', $this->routes);
        $this->excluderoutes = array_merge(
            $this->excluderoutes,
            c::get('staticbuilder.excluderoutes', [])
        );

        // Normalize assets config
        $assets = $kirby->get('option', 'staticbuilder.assets', $this->assets);
        $this->assets = [];
        foreach ($assets as $key=>$dest) {
            if (!is_string($dest)) continue;
            $this->assets[ is_string($key) ? $key : $dest ] = $dest;
        }

        // Filter for pages to build or ignore
        if (is_callable($filter = $kirby->get('option', 'staticbuilder.filter'))) {
            $this->filter = $filter;
        }

        // File name or extension for output pages
        if ($ext = $kirby->get('option', 'staticbuilder.extension')) {
            $ext = trim(str_replace('\\', '/', $ext));
            if (in_array(substr($ext, 0, 1), ['/', '.'])) {
                $this->extension = $ext;
            } else {
                $this->extension = '.' . $ext;
            }
        }

        // Output ugly URLs (e.g. '/my/page/index.html')?
        $this->uglyurls = $kirby->get('option', 'staticbuilder.uglyurls', $this->uglyurls);

        // Copy page files to a folder named after the page URL?
        $withfiles = $kirby->get('option', 'staticbuilder.withfiles', false);
        if (is_bool($withfiles) || is_callable($withfiles)) {
            $this->withfiles = $withfiles;
        }

        // Generate redirect definition files?
        $this->withredirects = $kirby->get('option', 'staticbuilder.withredirects', false);

        // Catch PHP errors while generating pages?
        $this->catcherror = c::get('staticbuilder.catcherror', $this->catcherror);

        // Save Kirby instance
        $this->kirby = $kirby;
    }

    /**
     * Change some of Kirby’s settings to help us building HTML that
     * is a bit different from the live pages.
     * Side effects: changes Kirby Toolkit and Kirby instance URL config.
     * @return \Kirby
     */
    protected function kirbyInstance()
    {
        // This will retrieve the existing instance with stale settings
        $kirby = kirby();
        // Set toolkit config to static URL prefix
        // This helps making the js(), css() and url() helper functions work
        C::set('url', static::URLPREFIX);
        Url::$home = static::URLPREFIX;
        // Same for Kirby options
        $kirby->set('option', 'url', static::URLPREFIX);
        // Fix base URL for $kirby->urls->* API; not sure if it impacts something else
        $kirby->urls->index = static::URLPREFIX;
        // Finally, setting the base URL of the "site" page fixes site and page URLS
        $kirby->site->url = static::URLPREFIX;
        return $kirby;
    }

    /**
     * Figure out if a filesystem path is absolute or if we should treat
     * it as relative (to the project folder or output folder).
     * @param string $path
     * @return boolean
     */
    protected function isAbsolutePath($path)
    {
        $pattern = '/^([\/\\\]|[a-z]:)/i';
        return preg_match($pattern, $path) == 1;
    }

    /**
     * Normalize a file path string to remove ".." etc.
     * @param string $path
     * @param string $sep Path separator to use in output
     * @return string
     */
    protected function normalizePath($path, $sep='/')
    {
        $path = $this->normalizeSlashes($path, $sep);
        $out = [];
        foreach (explode($sep, $path) as $i => $fold) {
            if ($fold == '..' && $i > 0 && end($out) != '..') array_pop($out);
            $fold = preg_replace('/\.{2,}/', '.', $fold);
            if ($fold == '' || $fold == '.') continue;
            else $out[] = $fold;
        }
        return ($path[0] == $sep ? $sep : '') . join($sep, $out);
    }

    /**
     * Normalize slashes in a string to use forward slashes only
     * @param string $str
     * @param string $sep
     * @return string
     */
    function normalizeSlashes($str, $sep='/')
    {
        $result = preg_replace('/[\\/\\\]+/', $sep, $str);
        return $result === null ? '' : $result;
    }

    /**
     * Check that the destination path is somewhere we can write to
     * @param string $absolutePath
     * @return boolean
     */
    protected function filterPath($absolutePath)
    {
        // Unresolved paths with '..' are invalid
        if (Str::contains($absolutePath, '..')) return false;
        return Str::startsWith($absolutePath, $this->outputdir . '/');
    }

    protected function shouldBuildRoute($uri)
    {
        // Not handling routes with parameters
        if (strpos($uri, '(') !== false) return false;
        // Match against ignored routes
        foreach ($this->excluderoutes as $ignored) {
            if (str::endsWith($ignored, '*') && str::startsWith($uri, rtrim($ignored, '*'))) return false;
            if ($uri === $ignored) return false;
        }
        return true;
    }

    /**
     * Build a relative URL from one absolute path to another,
     * going back as many times as needed. Paths should be absolute
     * or are considered to be starting from the same root.
     * @param string $from
     * @param string $to
     * @return string
     */
    protected function relativeUrl($from='', $to='')
    {
        $from = explode('/', ltrim($from, '/'));
        $to   = explode('/', ltrim($to, '/'));
        $last = false;
        while (count($from) && count($to) && $from[0] === $to[0]) {
            $last = array_shift($from);
            array_shift($to);
        }
        if (count($from) == 0) {
            if ($last) array_unshift($to, $last);
            return './' . implode('/', $to);
        }
        else {
            return './' . str_repeat('../', count($from)-1) . implode('/', $to);
        }
    }

    /**
     * Rewrites URLs in the response body of a page
     * @param string $text Response text
     * @param string $pageUrl URL for the page
     * @return string
     */
    protected function rewriteUrls($text, $pageUrl)
    {
        $relative = $this->baseurl === './';
        if ($relative || $this->uglyurls) {
            // Match restrictively urls starting with prefix, and which are
            // correctly escaped (no whitespace or quotes).
            $find = preg_quote(static::URLPREFIX) . '(\/?[^\?\&<>{}"\'\s]*)';
            $text = preg_replace_callback(
                "!$find!",
                function($found) use ($pageUrl, $relative) {
                    $url = $found[0];
                    if ($this->uglyurls) {
                        $path = $found[1];
                        if (!$path || $path === '/') {
                            $url = rtrim($url, '/') . '/index.html';
                        }
                        elseif (!Str::endsWith($url, '/') && !pathinfo($url, PATHINFO_EXTENSION)) {
                            $url .= $this->extension;
                        }
                    }
                    if ($relative) {
                        $pageUrl .= $this->extension;
                        $pageUrl = str_replace(static::URLPREFIX, '', $pageUrl);
                        $url = str_replace(static::URLPREFIX, '', $url);
                        $url = $this->relativeUrl($pageUrl, $url);
                    }
                    return $url;
                },
                $text
            );
        }
        // Except if we have converted to relative URLs, we still have
        // the placeholder prefix in the text. Swap in the base URL.
        $pattern = '!' . preg_quote(static::URLPREFIX) . '\/?!';
        return preg_replace($pattern, $this->baseurl, $text);
    }

    /**
     * Generate the file path that a page should be written to
     * @param Page $page
     * @param string|null $lang
     * @return string
     * @throws Exception
     */
    protected function pageFilename(Page $page, $lang=null)
    {
        // We basically want the page $page->id(), but localized for multilang
        $url = $page->url($lang);
        // Strip the temporary URL prefix
        $url = trim(str_replace(static::URLPREFIX, '', $url), '/');
        // Page URL fragment should not contain a protocol or domain name at this point;
        // did we fail to override the base URL with static::URLPREFIX?
        if (Str::startsWith($url, 'http') || Str::contains($url, '://')) {
            throw new Exception("Cannot use '$url' as basis for page's file name.");
        }
        // Special case: home page
        if (!$url) {
            $file = $this->outputdir . '/index.html';
        }
        // Don’t add any extension if we already have one in the URL
        // (using a short whitelist for likely use cases).
        elseif (preg_match('/\.(js|json|css|txt|svg|xml|atom|rss)$/i', $url)) {
            $file = $this->outputdir . '/' . $url;
        }
        else {
            $file = $this->outputdir . '/' . $url . $this->extension;
        }
        $validPath = $this->normalizePath($file);
        if ($this->filterPath($validPath) == false) {
            throw new Exception('Output path for page goes outside of static directory: ' . $file);
        }
        return $validPath;
    }

    /**
     * Updates server environment variables and Kirby state to match a virtual
     * request to the given URI
     * @param string $uri URI to visit
     * @param string $lang Language to visit if multi-lang site
     * @param string $method HTTP method (GET by default)
     */
    protected function visitUri($uri, $lang = null, $method = 'GET') {
        // Update various variables read by different Kirby components
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        url::$current = 'http://localhost/' . ltrim($uri, '/');
        $site = $this->kirby->site();

        // Kirby doesn't unset the representation in site#visit()
        // The default Kirby route checks this against the $page value, and
        // forces a error page if there's a mismatch
        $site->representation = null;

        // From Kirby#constructor
        $this->kirby->path = implode('/', (array)url::fragments($uri));
        $site->visit($this->kirby->path, $lang);

        return $this->kirby->page = $site->page;
    }

    /**
     * Write the HTML for a page and copy its files
     * @param Page $page
     * @param bool $write Should we write files or just report info (dry-run).
     */
    protected function buildPage(Page $page, $write=false)
    {
        if (!$this->shouldBuildRoute($page->uri())) {
            return;
        }

        // Check if we will build this page and report why not.
        // Note: in 2.1 the page filtering API changed, the return value
        // can be a boolean or an array with a boolean + a string.
        if (is_callable($this->filter)) {
            $filterResult = call_user_func($this->filter, $page);
        } else {
            $filterResult = $this->defaultFilter($page);
        }
        if (!is_array($filterResult)) {
            $filterResult = [$filterResult];
        }
        if (A::get($filterResult, 0, false) == false) {
            $log = [
                'type'   => 'page',
                'source' => $page->diruri(),
                'status' => 'ignore',
                'reason' => A::get($filterResult, 1, 'Excluded by filter'),
                'dest'   => null,
                'size'   => null
            ];
            $this->summary[] = $log;
            return;
        }

        // Build the HTML for each language version of the page
        foreach ($this->langs as $lang) {
            $this->buildPageVersion(clone $page, $lang, $write);
        }
    }

    /**
     * Write the HTML for a page’s language version
     * @param Page $page
     * @param string $lang Page language code
     * @param bool $write Should we write files or just report info (dry-run).
     * @return array
     */
    protected function buildPageVersion(Page $page, $lang=null, $write=false)
    {
        // Clear the cached data (especially the $page->content object)
        // or we will end up with the first language's content for all pages
        $page->reset();

        // Update the current language and active page
        if ($lang) $page->site->language = $page->site->language($lang);
        $page->site()->visit($page->uri(), $lang);

        // Let's get some metadata
        $source = $this->normalizePath($page->textfile(null, $lang));
        $source = ltrim(str_replace($this->root, '', $source), '/');
        $file   = $this->pageFilename($page, $lang);
        // Store reference to this page in case there's a fatal error
        $this->lastpage = $source;

        $log = [
            'type'   => 'page',
            'status' => '',
            'source' => $source,
            'dest'   => str_replace($this->outputdir, 'static', $file),
            'size'   => null,
            'title'  => $page->title()->value,
            'uri'    => $page->uri(),
            'files'  => [],
        ];

        // Figure out if we have files to copy
        $files = [];
        if ($this->withfiles) {
            $files = $page->files();
            if (is_callable($this->withfiles)) {
                $files = $files->filter($this->withfiles);
            }
        }

        // If not writing, let's report on the existing target page
        if ($write == false) {
            $log = $this->logSetStatus($file, $page->modified(), $log);
            $log['files'] = count($files);
            return $this->log($log);
        }

        // Render page
        // TODO: Render when write==false unless dry-run
        $text = $this->kirby->render($page, [], false);
        $text = $this->rewriteUrls($text, $page->url($lang));
        $log = $this->writeFile($file, $text, $log);
        @header_remove();

        // Option: Copy page files in a folder
        if (count($files) > 0) {
            $dir = str_replace(static::URLPREFIX, '', $page->url($lang));
            $dir = $this->normalizeSlashes($this->outputdir . '/' . $dir);
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }
            foreach ($files as $f) {
                $dest = $dir . '/' . $f->filename();
                if ($f->copy($dest)) {
                    $log['files'][] = str_replace($this->outputdir, 'static', $dest);
                }
            }
        }

        return $this->log($log);
    }

    protected function buildRoute($uri, $write=false)
    {
        if (!is_string($uri) || !$this->shouldBuildRoute($uri)) {
            return false;
        }

        $this->lastpage = $uri;

        $log = [
            'type'     => 'route',
            'status'   => '',
            'reason'   => '',
            'source'   => $uri,
            'dest'     => $uri,
            'uri'      => $uri,
            'redirect' => null,
            'size'     => null,
        ];

        if ($uri == '*') {
            // Replace '*' entry with all GET routes
            foreach ($this->kirby->router->routes('GET') as $entry) {
                $this->buildRoute($entry->pattern, $write);
            }
            return;
        }

        if (preg_match('~^([^{}]+)(/[^/{}]*)\{([^/{}}]+)\}([^/{}]*)(.*)~', $uri, $parts)) {
            // Expand {param} in route pattern
            list($match, $id, $prefix, $param, $suffix, $trailing) = $parts;
            $page = page($id);
            if ($page) {
                $permutations = $page->children()->visible()->pluck($param, ',', true);
                foreach ($permutations as $perm) {
                    $this->buildRoute(join('', [$id, $prefix, $perm, $suffix, $trailing]), $write);
                }
                if ($write) $log['status'] = 'generated';
            } else {
                $log['status'] = 'invalid';
            }
            return $this->log($log);
        }

        // Colons are invalid in file names on OSX
        // Remove if https://github.com/getkirby/kirby/issues/494 is implemented
        $target = strtr($uri, ':', '=');
        if (pathinfo($target, PATHINFO_EXTENSION) == '') {
            $target = rtrim($target, '/') . $this->extension;
        }
        $target = $this->normalizePath($this->outputdir . DS . $target);
        $log['dest'] = $target;
        $log['uri'] = $uri;

        if ($this->filterPath($target) == false) {
            return $this->log($log, [
                'status' => 'ignore',
                'reason' => 'Output path for page goes outside of static directory',
            ]);
        }

        $this->lastpage = $log['source'];
        $this->visitUri($uri);
        // From Kirby#launch()
        $route = $this->kirby->router->run(trim($this->kirby->path, '/'));

        if (is_null($route)) {
            // Unmatched route
            return $this->log($log, [
                'status' => 'invalid',
            ]);
        }

        if (!empty($route->redirect)) {
            // Routes is a redirect
            $log['type'] = 'redirect';
            $log['redirect'] = $route->redirect;

            // Don't save file if building redirect maps
            if ($this->withredirects) {
                $log['status'] = 'included';
                return $this->log($log);
            }
        }

        if ($write) {
            // Grab route output (using output buffering if necessary)
            ob_start();
            $response = call($route->action(), $route->arguments());
            $text = $this->kirby->component('response')->make($response);
            if (empty($text)) {
                $text = ob_get_contents();
            }
            $text = $this->rewriteUrls($text, $uri);
            ob_end_clean();

            $log = $this->writeFile($target, $text, $log);
        } else {
            $log = $this->logSetStatus($target, $this->lastmodified, $log);
        }

        return $this->log($log);
    }

    /**
     * Copy a file or folder to the static directory
     * This function is responsible for normalizing paths and making sure
     * we don't write files outside of the static directory.
     *
     * @param string $from Source file or folder
     * @param string $to Destination path
     * @param bool $write Should we write files or just report info (dry-run).
     * @return array|boolean
     * @throws Exception
     */
    protected function copyAsset($from=null, $to=null, $write=false)
    {
        if (!is_string($from) or !is_string($to)) {
            return false;
        }
        $this->lastpage = $from;
        $log = [
            'type'   => 'asset',
            'status' => '',
            'reason' => '',
            // Use unnormalized, relative paths in log, because they
            // might help understand why a file was ignored
            'source' => $from,
            'dest'   => 'static/',
            'uri'    => $from,
            'size'   => null
        ];

        // Source can be absolute
        if ($this->isAbsolutePath($from)) {
            $source = $from;
        } else {
            $source = $this->normalizePath($this->root . '/' . $from);
        }

        // But target is always relative to static dir
        $target = $this->normalizePath($this->outputdir . '/' . $to);
        if ($this->filterPath($target) == false) {
            return $this->log($log, [
                'status' => 'ignore',
                'reason' => 'Cannot copy asset outside of the static folder',
            ]);
        }
        $log['dest'] .= str_replace($this->outputdir . '/', '', $target);

        // Get type of asset
        if (is_dir($source)) {
            $log['type'] = 'dir';
        } elseif (is_file($source)) {
            $log['type'] = 'file';
        } else {
            $log['status'] = 'ignore';
            $log['reason'] = 'Source file or folder not found';
        }

        // Copy a folder
        if ($write && $log['type'] == 'dir') {
            $source = new Folder($source);
            $existing = new Folder($target);
            if ($existing->exists() ) ;
            $log['status'] = $source->copy($target) ? 'copied' : 'failed';
        }

        // Copy a file
        if ($write && $log['type'] == 'file') {
            $log['status'] = copy($source, $target) ? 'copied' : 'failed';
        }

        return $this->log($log);
    }

    protected function generateRedirectsMap($format = "%s %s;", $path = null, $write = false)
    {
        $lines = [];
        foreach ($this->summary as $item) {
            if (!empty($item['redirect'])) {
                $lines[] = sprintf(
                    $format,
                    '/' . addslashes(ltrim($item['uri'], '/')),
                    addslashes($item['redirect'])
                );
            }
        }
        $text = join($lines, "\n");

        if (empty($path)) {
            return $text;
        }

        $target = $this->normalizePath($this->outputdir . DS . $path);
        $log = [
            'type'   => 'redirects-map',
            'reason' => '',
            'source' => $path,
            'dest'   => $target,
            'uri'    => $path,
        ];
        if ($write) {
            $log = $this->writeFile($target, $text, $log);
        } else {
            $log = $this->logSetStatus($target, $this->lastmodified, $log);
        }

        return $this->log($log);
    }

    // TODO: Combine with logSetStatus since either one is run
    protected function writeFile($path, $text, $log) {
        $log['status'] = F::write($path, $text) ? 'generated' : 'failed';
        $log['size'] = strlen($text);
        return $log;
    }

    protected function logSetStatus($path, $lastmodified, $log) {
        // Track last modification of the entire site
        if ($lastmodified > $this->lastmodified) {
            $this->lastmodified = $lastmodified;
        }

        if (is_file($path)) {
            $outdated = filemtime($path) < $lastmodified;
            $log['status'] = $outdated ? 'outdated' : 'uptodate';
            $log['size'] = filesize($path);
        } else {
            $log['status'] = 'missing';
        }

        return $log;
    }

    /**
     * Get a collection of pages to work with (collection may be empty)
     * @param Page|Pages|Site $content Content to write to the static folder
     * @return Pages
     */
    protected function getPages($content)
    {
        if ($content instanceof Pages) {
            return $content;
        }
        elseif ($content instanceof Site) {
            return $content->index();
        }
        else {
            $pages = new Pages([]);
            if ($content instanceof Page) $pages->add($content);
            return $pages;
        }
    }

    /**
     * Try to render any PHP Fatal Error in our own template
     * @return bool
     */
    protected function showFatalError()
    {
        $error = error_get_last();
        switch ($error['type']) {
        case E_ERROR:
        case E_CORE_ERROR:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_RECOVERABLE_ERROR:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
        case E_PARSE:
            ob_clean();
            echo $this->htmlReport([
                'mode' => 'fatal',
                'error' => 'Error while building pages',
                'summary' => $this->summary,
                'errorTitle' => 'Failed to build page <code>' . $this->lastpage . '</code>',
                'errorDetails' => $error['message'] . "<br>\n"
                . 'In ' . $error['file'] . ', line ' . $error['line']
            ]);
        }
    }

    /**
     * Append the item to the current summary and notify any callbacks.
     * @param array $item Metadata for item that was built
     * @return array $item
     */
    protected function log($item, $merge = null)
    {
        if (is_array($merge)) {
            $item = array_merge($item, $merge);
        }
        foreach ($this->onLogCallbacks as $cb) $cb($item);
        return $this->summary[] = $item;
    }

    /**
     * Register new log callback.
     * @param function $callback
     */
    public function onLog($callback)
    {
        $this->onLogCallbacks[] = $callback;
    }

    /**
     * Build or rebuild static content
     * @param Page|Pages|Site $content Content to write to the static folder
     * @param boolean $write Should we actually write files
     * @return array
     */
    public function run($content, $write=false)
    {
        $this->summary = [];
        $this->kirby->cache()->flush();
        $level = 1;

        if ($write) {
            // Kill PHP Error reporting when building pages, to "catch" PHP errors
            // from the pages or their controllers (and plugins etc.). We're going
            // to try to hande it ourselves
            $level = error_reporting();
            if ($this->catcherror) {
                if (!isset($this->shutdown)) {
                    $this->shutdown = function () {
                        $this->showFatalError();
                    };
                }
                register_shutdown_function($this->shutdown);
                error_reporting(0);
            }
        }

        // Empty folder on full site build
        if ($write && $content instanceof Site) {
            $folder = new Folder($this->outputdir);
            $folder->flush();
        }

        // Build each page (possibly several times for multilingual sites)
        foreach($this->getPages($content) as $page) {
            $this->buildPage($page, $write);
        }

        foreach ($this->routes as $route) {
            $this->buildRoute($route, $write);
        }

        // Generate redirect list if requested
        if ($this->withredirects) {
            $this->generateRedirectsMap('"%s" "%s";', '.redirects.nginx', $write);
            $this->generateRedirectsMap('%s %s', '.redirects.apache', $write);
        }

        // Copy assets after building pages (so that e.g. thumbs are ready)
        foreach ($this->assets as $from => $to) {
            $this->copyAsset($from, $to, $write);
        }

        // Restore error reporting if building pages worked
        if ($write && $this->catcherror) {
            error_reporting($level);
            $this->shutdown = function () {};
        }
    }

    /**
     * Render the HTML report page
     *
     * @param array $data
     * @return Response
     */
    public function htmlReport($data=[])
    {
        // Forcefully remove headers that might have been set by some
        // templates, controllers or plugins when rendering pages.
        header_remove();
        $root = dirname(__DIR__);
        $data['styles'] = file_get_contents($root . '/assets/report.css');
        $data['script'] = file_get_contents($root . '/assets/report.js');
        $body = Tpl::load(__DIR__ . '/report.php', $data);
        return new Response($body, 'html', $data['error'] ? 500 : 200);
    }

    /**
     * Standard filter used to exclude empty "page" directories
     * @param Page $page
     * @return bool|array
     */
    public static function defaultFilter($page)
    {
        // Exclude folders containing Kirby Modules
        // https://github.com/getkirby-plugins/modules-plugin
        $mod = C::get('modules.template.prefix', 'module.');
        if (Str::startsWith($page->intendedTemplate(), $mod)) {
            return [false, "Ignoring module pages (template prefix: \"$mod\")"];
        }
        // Exclude pages missing a content file
        // Note: $page->content()->exists() returns the wrong information,
        // so we use the inventory instead. For an empty directory, it can
        // be [] (single-language site) or ['code' => null] (multilang).
        if (array_shift($page->inventory()['content']) === null) {
            return [false, 'Page has no content file.'];
        }
        return true;
    }
}
