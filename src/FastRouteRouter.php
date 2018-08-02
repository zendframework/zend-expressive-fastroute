<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-fastroute for the canonical source repository
 * @copyright Copyright (c) 2015-2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-fastroute/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Zend\Expressive\Router;

use FastRoute\DataGenerator\GroupCountBased as RouteGenerator;
use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as RouteParser;
use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use Psr\Http\Message\ServerRequestInterface as Request;
use Zend\Stdlib\ArrayUtils;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_reduce;
use function array_reverse;
use function array_unique;
use function dirname;
use function file_exists;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_string;
use function is_writable;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function var_export;

use const E_WARNING;

/**
 * Router implementation bridging nikic/fast-route.
 */
class FastRouteRouter implements RouterInterface
{
    /**
     * Template used when generating the cache file.
     */
    public const CACHE_TEMPLATE = <<< 'EOT'
<?php
return %s;
EOT;

    /**
     * @const string Configuration key used to enable/disable fastroute caching
     */
    public const CONFIG_CACHE_ENABLED = 'cache_enabled';

    /**
     * @const string Configuration key used to set the cache file path
     */
    public const CONFIG_CACHE_FILE = 'cache_file';

    /**
     * Standard HTTP methods against which to test HEAD/OPTIONS requests.
     */
    public const HTTP_METHODS_STANDARD = [
        RequestMethod::METHOD_HEAD,
        RequestMethod::METHOD_GET,
        RequestMethod::METHOD_POST,
        RequestMethod::METHOD_PUT,
        RequestMethod::METHOD_PATCH,
        RequestMethod::METHOD_DELETE,
        RequestMethod::METHOD_OPTIONS,
        RequestMethod::METHOD_TRACE,
    ];

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
     * @var callable A factory callback that can return a dispatcher.
     */
    private $dispatcherCallback;

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
    private $routes = [];

    /**
     * Routes to inject into the underlying RouteCollector.
     *
     * @var Route[]
     */
    private $routesToInject = [];

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

        $this->router             = $router;
        $this->dispatcherCallback = $dispatcherFactory;

        $this->loadConfig($config);
    }

    /**
     * Load configuration parameters
     *
     * @param null|array $config Array of custom configuration options.
     */
    private function loadConfig(array $config = null) : void
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
     */
    public function addRoute(Route $route) : void
    {
        $this->routesToInject[] = $route;
    }

    public function match(Request $request) : RouteResult
    {
        // Inject any pending routes
        $this->injectRoutes();

        $dispatchData = $this->getDispatchData();

        $path       = rawurldecode($request->getUri()->getPath());
        $method     = $request->getMethod();
        $dispatcher = $this->getDispatcher($dispatchData);
        $result     = $dispatcher->dispatch($method, $path);

        return $result[0] !== Dispatcher::FOUND
            ? $this->marshalFailedRoute($result)
            : $this->marshalMatchedRoute($result, $method);
    }

    /**
     * Generate a URI based on a given route.
     *
     * Replacements in FastRoute are written as `{name}` or `{name:<pattern>}`;
     * this method uses `FastRoute\RouteParser\Std` to search for the best route
     * match based on the available substitutions and generates a uri.
     *
     * @param string $name Route name.
     * @param array $substitutions Key/value pairs to substitute into the route
     *     pattern.
     * @param array $options Key/value option pairs to pass to the router for
     *     purposes of generating a URI; takes precedence over options present
     *     in route used to generate URI.
     *
     * @return string URI path generated.
     * @throws Exception\RuntimeException if the route name is not known
     *     or a parameter value does not match its regex.
     */
    public function generateUri(string $name, array $substitutions = [], array $options = []) : string
    {
        // Inject any pending routes
        $this->injectRoutes();

        if (! array_key_exists($name, $this->routes)) {
            throw new Exception\RuntimeException(sprintf(
                'Cannot generate URI for route "%s"; route not found',
                $name
            ));
        }

        $route   = $this->routes[$name];
        $options = ArrayUtils::merge($route->getOptions(), $options);

        if (! empty($options['defaults'])) {
            $substitutions = array_merge($options['defaults'], $substitutions);
        }

        $routeParser       = new RouteParser();
        $routes            = array_reverse($routeParser->parse($route->getPath()));
        $missingParameters = [];

        // One route pattern can correspond to multiple routes if it has optional parts
        foreach ($routes as $parts) {
            // Check if all parameters can be substituted
            $missingParameters = $this->missingParameters($parts, $substitutions);

            // If not all parameters can be substituted, try the next route
            if (! empty($missingParameters)) {
                continue;
            }

            // Generate the path
            $path = '';
            foreach ($parts as $part) {
                if (is_string($part)) {
                    // Append the string
                    $path .= $part;
                    continue;
                }

                // Check substitute value with regex
                if (! preg_match('~^' . $part[1] . '$~', (string) $substitutions[$part[0]])) {
                    throw new Exception\RuntimeException(sprintf(
                        'Parameter value for [%s] did not match the regex `%s`',
                        $part[0],
                        $part[1]
                    ));
                }

                // Append the substituted value
                $path .= $substitutions[$part[0]];
            }

            // Return generated path
            return $path;
        }

        // No valid route was found: list minimal required parameters
        throw new Exception\RuntimeException(sprintf(
            'Route `%s` expects at least parameter values for [%s], but received [%s]',
            $name,
            implode(',', $missingParameters),
            implode(',', array_keys($substitutions))
        ));
    }

    /**
     * Checks for any missing route parameters
     *
     * @return array with minimum required parameters if any are missing or
     *     an empty array if none are missing
     */
    private function missingParameters(array $parts, array $substitutions) : array
    {
        $missingParameters = [];

        // Gather required parameters
        foreach ($parts as $part) {
            if (is_string($part)) {
                continue;
            }

            $missingParameters[] = $part[0];
        }

        // Check if all parameters exist
        foreach ($missingParameters as $param) {
            if (! isset($substitutions[$param])) {
                // Return the parameters so they can be used in an
                // exception if needed
                return $missingParameters;
            }
        }

        // All required parameters are available
        return [];
    }

    /**
     * Create a default FastRoute Collector instance
     */
    private function createRouter() : RouteCollector
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
     * @param array|object $data Data from RouteCollection::getData()
     *
     * @return Dispatcher
     */
    private function getDispatcher($data) : Dispatcher
    {
        if (! $this->dispatcherCallback) {
            $this->dispatcherCallback = $this->createDispatcherCallback();
        }

        $factory = $this->dispatcherCallback;

        return $factory($data);
    }

    /**
     * Return a default implementation of a callback that can return a Dispatcher.
     */
    private function createDispatcherCallback() : callable
    {
        return function ($data) {
            return new GroupCountBased($data);
        };
    }

    /**
     * Marshal a routing failure result.
     *
     * If the failure was due to the HTTP method, passes the allowed HTTP
     * methods to the factory.
     */
    private function marshalFailedRoute(array $result) : RouteResult
    {
        if ($result[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return RouteResult::fromRouteFailure($result[1]);
        }

        return RouteResult::fromRouteFailure(Route::HTTP_METHOD_ANY);
    }

    /**
     * Marshals a route result based on the results of matching and the current HTTP method.
     */
    private function marshalMatchedRoute(array $result, string $method) : RouteResult
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
            return $this->marshalMethodNotAllowedResult($result);
        }

        $params = $result[2];

        $options = $route->getOptions();
        if (! empty($options['defaults'])) {
            $params = array_merge($options['defaults'], $params);
        }

        return RouteResult::fromRoute($route, $params);
    }

    /**
     * Inject queued Route instances into the underlying router.
     */
    private function injectRoutes() : void
    {
        foreach ($this->routesToInject as $index => $route) {
            $this->injectRoute($route);
            unset($this->routesToInject[$index]);
        }
    }

    /**
     * Inject a Route instance into the underlying router.
     */
    private function injectRoute(Route $route) : void
    {
        // Filling the routes' hash-map is required by the `generateUri` method
        $this->routes[$route->getName()] = $route;

        // Skip feeding FastRoute collector if valid cached data was already loaded
        if ($this->hasCache) {
            return;
        }

        $methods = $route->getAllowedMethods();

        if ($methods === Route::HTTP_METHOD_ANY) {
            $methods = self::HTTP_METHODS_STANDARD;
        }

        $this->router->addRoute($methods, $route->getPath(), $route->getPath());
    }

    /**
     * Get the dispatch data either from cache or freshly generated by the
     * FastRoute data generator.
     *
     * If caching is enabled, store the freshly generated data to file.
     */
    private function getDispatchData() : array
    {
        if ($this->hasCache) {
            return $this->dispatchData;
        }

        $dispatchData = (array) $this->router->getData();

        if ($this->cacheEnabled) {
            $this->cacheDispatchData($dispatchData);
        }

        return $dispatchData;
    }

    /**
     * Load dispatch data from cache
     *
     * @throws Exception\InvalidCacheException If the cache file contains
     *     invalid data
     */
    private function loadDispatchData() : void
    {
        set_error_handler(function () {
        }, E_WARNING); // suppress php warnings
        $dispatchData = include $this->cacheFile;
        restore_error_handler();

        // Cache file does not exist
        if (false === $dispatchData) {
            return;
        }

        if (! is_array($dispatchData)) {
            throw new Exception\InvalidCacheException(sprintf(
                'Invalid cache file "%s"; cache file MUST return an array',
                $this->cacheFile
            ));
        }

        $this->hasCache     = true;
        $this->dispatchData = $dispatchData;
    }

    /**
     * Save dispatch data to cache
     *
     * @return int|false bytes written to file or false if error
     * @throws Exception\InvalidCacheDirectoryException If the cache directory
     *     does not exist.
     * @throws Exception\InvalidCacheDirectoryException If the cache directory
     *     is not writable.
     * @throws Exception\InvalidCacheException If the cache file exists but is
     *     not writable
     */
    private function cacheDispatchData(array $dispatchData)
    {
        $cacheDir = dirname($this->cacheFile);

        if (! is_dir($cacheDir)) {
            throw new Exception\InvalidCacheDirectoryException(sprintf(
                'The cache directory "%s" does not exist',
                $cacheDir
            ));
        }

        if (! is_writable($cacheDir)) {
            throw new Exception\InvalidCacheDirectoryException(sprintf(
                'The cache directory "%s" is not writable',
                $cacheDir
            ));
        }

        if (file_exists($this->cacheFile) && ! is_writable($this->cacheFile)) {
            throw new Exception\InvalidCacheException(sprintf(
                'The cache file %s is not writable',
                $this->cacheFile
            ));
        }

        return file_put_contents(
            $this->cacheFile,
            sprintf(self::CACHE_TEMPLATE, var_export($dispatchData, true))
        );
    }

    private function marshalMethodNotAllowedResult(array $result) : RouteResult
    {
        $path = $result[1];
        $allowedMethods = array_reduce($this->routes, function ($allowedMethods, $route) use ($path) {
            if ($path !== $route->getPath()) {
                return $allowedMethods;
            }

            return array_merge($allowedMethods, $route->getAllowedMethods());
        }, []);

        $allowedMethods = array_unique($allowedMethods);

        return RouteResult::fromRouteFailure($allowedMethods);
    }
}
