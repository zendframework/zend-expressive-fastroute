<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @see       https://github.com/zendframework/zend-expressive for the canonical source repository
 * @copyright Copyright (c) 2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive/blob/master/LICENSE.md New BSD License
 */

namespace Zend\Expressive\Router;

use FastRoute\DataGenerator\GroupCountBased as RouteGenerator;
use FastRoute\Dispatcher\GroupCountBased as Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as RouteParser;
use Psr\Http\Message\ServerRequestInterface as Request;
use Zend\Expressive\Router\Exception;

/**
 * Router implementation bridging nikic/fast-route.
 */
class FastRouteRouter implements RouterInterface
{
    /**
     * Regular expression pattern for identifying a variable subsititution.
     *
     * This is an sprintf pattern; the variable name will be substituted for
     * the final pattern.
     *
     * @see \FastRoute\RouteParser\Std for mirror, generic representation.
     */
    const VARIABLE_REGEX = <<<'REGEX'
\{
    \s* %s \s*
    (?:
        : \s* ([^{}]*(?:\{(?-1)\}[^{}]*)*)
    )?
\}
REGEX;

    /**
     * @var callable A factory callback that can return a dispatcher.
     */
    private $dispatcherCallback;

    /**
     * FastRoute router
     *
     * @var RouteCollector
     */
    private $router;

    /**
     * All attached routes as Route instances
     *
     * @var Route[]
     */
    private $routes;

    /**
     * Routes to inject into the underlying RouteCollector.
     *
     * @var Route[]
     */
    private $routesToInject = [];

    /**
     * Cache generated route data?
     *
     * @var bool
     */
    private $cacheEnabled = false;

    /**
     * Cache file path relative to the project directory.
     *
     * @var string
     */
    private $cacheFile = 'data/cache/fastroute.php.cache';

    /**
     * Cached data used by the dispatcher.
     *
     * @var array
     */
    private $dispatchData = [];

    /**
     * True if cache is enabled and valid dispatch data has been loaded from
     * cache.
     *
     * @var bool
     */
    private $hasCache = false;

    /**
     * @const string Configuration key used to enable/disable fastroute caching
     */
    const CONFIG_CACHE_ENABLED = 'cache_enabled';

    /**
     * @const string Configuration key used to set the cache file path
     */
    const CONFIG_CACHE_FILE    = 'cache_file';

    /**
     * Constructor
     *
     * Accepts optionally a FastRoute RouteCollector and a callable factory
     * that can return a FastRoute dispatcher.
     *
     * If either is not provided defaults will be used:
     *
     * - A RouteCollector instance will be created composing a RouteParser and
     *   RouteGenerator.
     * - A callable that returns a GroupCountBased dispatcher will be created.
     *
     * @param null|RouteCollector $router If not provided, a default
     *     implementation will be used.
     * @param null|callable $dispatcherFactory Callable that will return a
     *     FastRoute dispatcher.
     * @param array $config Array of custom configuration options.
     */
    public function __construct(
        RouteCollector $router = null,
        callable $dispatcherFactory = null,
        array $config = null
    ) {
        if (null === $router) {
            $router = $this->createRouter();
        }

        $this->router = $router;
        $this->dispatcherCallback = $dispatcherFactory;

        $this->loadConfig($config);
    }

    /**
     * Load configuration parameters
     *
     * @param array $config Array of custom configuration options.
     * @return void
     */
    private function loadConfig(array $config = null)
    {
        if (null === $config) {
            return;
        }

        if (isset($config[self::CONFIG_CACHE_ENABLED])) {
            $this->cacheEnabled = (bool) $config[self::CONFIG_CACHE_ENABLED];
        }

        if (isset($config[self::CONFIG_CACHE_FILE])) {
            $this->cacheFile = (string) $config[self::CONFIG_CACHE_FILE];
        }

        if ($this->cacheEnabled) {
            $this->loadDispatchData();
        }
    }

    /**
     * Add a route to the collection.
     *
     * Uses the HTTP methods associated (creating sane defaults for an empty
     * list or Route::HTTP_METHOD_ANY) and the path, and uses the path as
     * the name (to allow later lookup of the middleware).
     *
     * @param Route $route
     */
    public function addRoute(Route $route)
    {
        $this->routesToInject[] = $route;
    }

    /**
     * @param  Request $request
     * @return RouteResult
     */
    public function match(Request $request)
    {
        // Inject any pending routes
        $this->injectRoutes();

        $dispatchData = $this->getDispatchData();

        $path       = $request->getUri()->getPath();
        $method     = $request->getMethod();
        $dispatcher = $this->getDispatcher($dispatchData);
        $result     = $dispatcher->dispatch($method, $path);

        if ($result[0] !== Dispatcher::FOUND) {
            return $this->marshalFailedRoute($result);
        }

        return $this->marshalMatchedRoute($result, $method);
    }

    /**
     * Generate a URI based on a given route.
     *
     * Replacements in FastRoute are written as `{name}` or `{name:<pattern>}`;
     * this method uses a regular expression to search for substitutions that
     * match, and replaces them with the value provided.
     *
     * It does *not* use the pattern to validate that the substitution value is
     * valid beforehand, however.
     *
     * @param string $name Route name.
     * @param array $substitutions Key/value pairs to substitute into the route
     *     pattern.
     * @return string URI path generated.
     * @throws Exception\InvalidArgumentException if the route name is not
     *     known.
     */
    public function generateUri($name, array $substitutions = [])
    {
        // Inject any pending routes
        $this->injectRoutes();

        if (! array_key_exists($name, $this->routes)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Cannot generate URI for route "%s"; route not found',
                $name
            ));
        }

        $route   = $this->routes[$name];
        $path    = $route->getPath();
        $options = $route->getOptions();

        if (! empty($options['defaults'])) {
            $substitutions = array_merge($options['defaults'], $substitutions);
        }

        foreach ($substitutions as $key => $value) {
            $pattern = sprintf(
                '~%s~x',
                sprintf(self::VARIABLE_REGEX, preg_quote($key))
            );
            $path = preg_replace($pattern, $value, $path);
        }

        // 1. remove optional segments' ending delimiters
        //    and remove leftover regex char classes like `:[` and `:prefix-[` (issue #18)
        // 2. split path into an array of optional segments and remove those
        //    containing unsubstituted parameters starting from the last segment
        $path = preg_replace('/\]|:.*\[/', '', $path);
        $segs = array_reverse(explode('[', $path));
        foreach ($segs as $n => $seg) {
            if (strpos($seg, '{') !== false) {
                if (isset($segs[$n - 1])) {
                    throw new Exception\InvalidArgumentException(
                        'Optional segments with unsubstituted parameters cannot '
                        . 'contain segments with substituted parameters when using FastRoute'
                    );
                }
                unset($segs[$n]);
            }
        }
        $path = implode('', array_reverse($segs));

        return $path;
    }

    /**
     * Create a default FastRoute Collector instance
     *
     * @return RouteCollector
     */
    private function createRouter()
    {
        return new RouteCollector(new RouteParser, new RouteGenerator);
    }

    /**
     * Retrieve the dispatcher instance.
     *
     * Uses the callable factory in $dispatcherCallback, passing it $data
     * (which should be derived from the router's getData() method); this
     * approach is done to allow testing against the dispatcher.
     *
     * @param  array|object $data Data from RouteCollection::getData()
     * @return Dispatcher
     */
    private function getDispatcher($data)
    {
        if (! $this->dispatcherCallback) {
            $this->dispatcherCallback = $this->createDispatcherCallback();
        }

        $factory = $this->dispatcherCallback;
        return $factory($data);
    }

    /**
     * Return a default implementation of a callback that can return a Dispatcher.
     *
     * @return callable
     */
    private function createDispatcherCallback()
    {
        return function ($data) {
            return new Dispatcher($data);
        };
    }

    /**
     * Marshal a routing failure result.
     *
     * If the failure was due to the HTTP method, passes the allowed HTTP
     * methods to the factory.
     *
     * @return RouteResult
     */
    private function marshalFailedRoute(array $result)
    {
        if ($result[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return RouteResult::fromRouteFailure($result[1]);
        }
        return RouteResult::fromRouteFailure();
    }

    /**
     * Marshals a route result based on the results of matching and the current HTTP method.
     *
     * @param array $result
     * @param string $method
     * @return RouteResult
     */
    private function marshalMatchedRoute(array $result, $method)
    {
        $path  = $result[1];
        $route = array_reduce($this->routes, function ($matched, $route) use ($path, $method) {
            if ($matched) {
                return $matched;
            }

            if ($path !== $route->getPath()) {
                return $matched;
            }

            if (! $route->allowsMethod($method)) {
                return $matched;
            }

            return $route;
        }, false);

        if (false === $route) {
            // This likely should never occur, but is present for completeness.
            return RouteResult::fromRouteFailure();
        }

        $params = $result[2];

        $options = $route->getOptions();
        if (! empty($options['defaults'])) {
            $params = array_merge($options['defaults'], $params);
        }

        return RouteResult::fromRouteMatch(
            $route->getName(),
            $route->getMiddleware(),
            $params
        );
    }

    /**
     * Inject queued Route instances into the underlying router.
     *
     * @param Route $route
     */
    private function injectRoutes()
    {
        foreach ($this->routesToInject as $index => $route) {
            $this->injectRoute($route);
            unset($this->routesToInject[$index]);
        }
    }

    /**
     * Inject a Route instance into the underlying router.
     *
     * @param Route $route
     */
    private function injectRoute(Route $route)
    {
        // Filling the routes' hash-map is required by the `generateUri` method
        $this->routes[$route->getName()] = $route;

        // skip feeding FastRoute collector if valid cached data was already loaded
        if ($this->hasCache) {
            return;
        }

        $methods = $route->getAllowedMethods();

        if ($methods === Route::HTTP_METHOD_ANY) {
            $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE'];
        }

        if (empty($methods)) {
            $methods = ['GET', 'HEAD', 'OPTIONS'];
        }

        $this->router->addRoute($methods, $route->getPath(), $route->getPath());
    }

    /**
     * Get the dispatch data either from cache or freshly generated by the
     * FastRoute data generator. If caching is enabled store the freshly
     * generated data to file.
     *
     * @return array
     */
    private function getDispatchData()
    {
        if ($this->hasCache) {
            return $this->dispatchData;
        }

        $dispatchData = $this->router->getData();

        if ($this->cacheEnabled) {
            $this->cacheDispatchData($dispatchData);
        }

        return $dispatchData;
    }

    /**
     * Load dispatchData from cache
     *
     * @return void
     * @throws Exception\RuntimeException If the cache file contains invalid data
     */
    private function loadDispatchData()
    {
        set_error_handler(
            function () {
            },
            E_WARNING
        ); // suppress php warnings
        $dispatchData = include $this->cacheFile;
        restore_error_handler();

        // cache file does not exists; return empty array as dispatch data
        if (false === $dispatchData) {
            return [];
        }

        if (! is_array($dispatchData)) {
            throw new Exception\RuntimeException(
                'Invalid cache file "' . $this->cacheFile . '". '
                . 'The cache file MUST return an array.'
            );
        }

        $this->hasCache = true;
        $this->dispatchData = $dispatchData;
    }

    /**
     * Save dispatchData to cache
     *
     * @param array $dispatchData
     * @return int|false bytes written to file or false if error
     * @throws Exception\RuntimeException
     */
    private function cacheDispatchData(array $dispatchData)
    {
        $cacheDir = dirname($this->cacheFile);

        if (! is_dir($cacheDir)) {
            throw new Exception\RuntimeException(
                'The cache directory does not exists'
            );
        }

        if (! is_writable($cacheDir)) {
            throw new Exception\RuntimeException(
                'The cache directory is not writable'
            );
        }

        return file_put_contents(
            $this->cacheFile,
            '<?php return ' . var_export($dispatchData, true) . ';'
        );
    }
}
