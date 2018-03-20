<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-fastroute for the canonical source repository
 * @copyright Copyright (c) 2015-2017 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-fastroute/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ZendTest\Expressive\Router;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ProphecyInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Zend\Diactoros\ServerRequest;
use Zend\Expressive\Router\Exception\InvalidCacheDirectoryException;
use Zend\Expressive\Router\Exception\InvalidCacheException;
use Zend\Expressive\Router\Exception\RuntimeException;
use Zend\Expressive\Router\FastRouteRouter;
use Zend\Expressive\Router\Route;
use Zend\Expressive\Router\RouteResult;

use function file_get_contents;
use function is_file;
use function unlink;

class FastRouteRouterTest extends TestCase
{
    /**
     * @var RouteCollector|ProphecyInterface
     */
    private $fastRouter;

    /**
     * @var Dispatcher|ProphecyInterface
     */
    private $dispatcher;

    /**
     * @var callable
     */
    private $dispatchCallback;

    protected function setUp()
    {
        $this->fastRouter = $this->prophesize(RouteCollector::class);
        $this->dispatcher = $this->prophesize(Dispatcher::class);
        $this->dispatchCallback = function () {
            return $this->dispatcher->reveal();
        };
    }

    private function getRouter() : FastRouteRouter
    {
        return new FastRouteRouter(
            $this->fastRouter->reveal(),
            $this->dispatchCallback
        );
    }

    private function getMiddleware() : MiddlewareInterface
    {
        return $this->prophesize(MiddlewareInterface::class)->reveal();
    }

    public function testWillLazyInstantiateAFastRouteCollectorIfNoneIsProvidedToConstructor()
    {
        $router = new FastRouteRouter();
        $this->assertAttributeInstanceOf(RouteCollector::class, 'router', $router);
    }

    public function testAddingRouteAggregatesRoute()
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET]);
        $router = $this->getRouter();
        $router->addRoute($route);
        $this->assertAttributeContains($route, 'routesToInject', $router);
    }

    /**
     * @depends testAddingRouteAggregatesRoute
     */
    public function testMatchingInjectsRouteIntoFastRoute()
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET]);
        $this->fastRouter->addRoute([RequestMethod::METHOD_GET], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();
        $this->dispatcher->dispatch(RequestMethod::METHOD_GET, '/foo')->willReturn([
            Dispatcher::NOT_FOUND,
        ]);

        $router = $this->getRouter();
        $router->addRoute($route);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->will(function () use ($uri) {
            return $uri->reveal();
        });
        $request->getMethod()->willReturn(RequestMethod::METHOD_GET);

        $router->match($request->reveal());
    }

    /**
     * @depends testAddingRouteAggregatesRoute
     */
    public function testGeneratingUriInjectsRouteIntoFastRoute()
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $this->fastRouter->addRoute([RequestMethod::METHOD_GET], '/foo', '/foo')->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        $this->assertEquals('/foo', $router->generateUri('foo'));
    }

    public function testIfRouteSpecifiesAnyHttpMethodFastRouteIsPassedHardCodedListOfMethods()
    {
        $route = new Route('/foo', $this->getMiddleware());
        $this->fastRouter
            ->addRoute(
                FastRouteRouter::HTTP_METHODS_STANDARD,
                '/foo',
                '/foo'
            )
            ->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        // routes are not injected until match or generateUri
        $router->generateUri($route->getName());
    }

    public function testMatchingRouteShouldReturnSuccessfulRouteResult()
    {
        $middleware = $this->getMiddleware();
        $route = new Route('/foo', $middleware, [RequestMethod::METHOD_GET]);

        $uri     = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_GET);

        $this->dispatcher->dispatch(RequestMethod::METHOD_GET, '/foo')->willReturn([
            Dispatcher::FOUND,
            '/foo',
            ['bar' => 'baz']
        ]);

        $this->fastRouter->addRoute([RequestMethod::METHOD_GET], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('/foo^GET', $result->getMatchedRouteName());
        $this->assertSame($middleware, $result->getMatchedRoute()->getMiddleware());
        $this->assertSame(['bar' => 'baz'], $result->getMatchedParams());

        return ['route' => $route, 'result' => $result];
    }

    /**
     * @depends testMatchingRouteShouldReturnSuccessfulRouteResult
     *
     * @param array $data
     */
    public function testMatchedRouteResultContainsRoute(array $data)
    {
        $route = $data['route'];
        $result = $data['result'];
        $this->assertSame($route, $result->getMatchedRoute());
    }

    public function idemPotentMethods()
    {
        return [
            RequestMethod::METHOD_GET => [RequestMethod::METHOD_GET],
            RequestMethod::METHOD_HEAD => [RequestMethod::METHOD_HEAD],
        ];
    }

    /**
     * @dataProvider idemPotentMethods
     */
    public function testRouteNotSpecifyingOptionsImpliesOptionsIsSupportedAndMatchesWhenGetOrHeadIsAllowed(
        string $method
    ) {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST, $method]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_OPTIONS);

        // This test needs to determine what the default dispatcher does with
        // OPTIONS requests when the route does not support them. As a result,
        // it does not mock the router or dispatcher.
        $router = new FastRouteRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->getMatchedRoute());
        $this->assertSame([RequestMethod::METHOD_POST, $method], $result->getAllowedMethods());
    }

    public function testRouteNotSpecifyingOptionsGetOrHeadMatchesOptions()
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_OPTIONS);

        // This test needs to determine what the default dispatcher does with
        // OPTIONS requests when the route does not support them. As a result,
        // it does not mock the router or dispatcher.
        $router = new FastRouteRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertSame([RequestMethod::METHOD_POST], $result->getAllowedMethods());
    }

    public function testRouteNotSpecifyingGetOrHeadDoesMatcheshHead()
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_HEAD);

        // This test needs to determine what the default dispatcher does with
        // HEAD requests when the route does not support them. As a result,
        // it does not mock the router or dispatcher.
        $router = new FastRouteRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertSame([RequestMethod::METHOD_POST], $result->getAllowedMethods());
    }

    /**
     * With GET provided explicitly, FastRoute will match a HEAD request.
     */
    public function testRouteSpecifyingGetDoesNotMatchHead()
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_HEAD);

        // This test needs to determine what the default dispatcher does with
        // HEAD requests when the route does not support them. As a result,
        // it does not mock the router or dispatcher.
        $router = new FastRouteRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
    }

    public function testMatchFailureDueToHttpMethodReturnsRouteResultWithAllowedMethods()
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST]);

        $uri     = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_GET);

        $this->dispatcher->dispatch(RequestMethod::METHOD_GET, '/foo')->willReturn([
            Dispatcher::METHOD_NOT_ALLOWED,
            [RequestMethod::METHOD_POST]
        ]);

        $this->fastRouter->addRoute([RequestMethod::METHOD_POST], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isMethodFailure());
        $this->assertSame([RequestMethod::METHOD_POST], $result->getAllowedMethods());
    }

    public function testMatchFailureNotDueToHttpMethodReturnsGenericRouteFailureResult()
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/bar');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_GET);

        $this->dispatcher->dispatch(RequestMethod::METHOD_GET, '/bar')->willReturn([
            Dispatcher::NOT_FOUND,
        ]);

        $this->fastRouter->addRoute([RequestMethod::METHOD_GET], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isMethodFailure());
        $this->assertSame(Route::HTTP_METHOD_ANY, $result->getAllowedMethods());
    }

    public function generatedUriProvider()
    {
        // @codingStandardsIgnoreStart
        $routes = [
            new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST], 'foo-create'),
            new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo-list'),
            new Route('/foo/{id:\d+}', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo'),
            new Route('/bar/{baz}', $this->getMiddleware(), Route::HTTP_METHOD_ANY, 'bar'),
            new Route('/index[/{page:\d+}]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'index'),
            new Route('/extra[/{page:\d+}[/optional-{extra:\w+}]]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'extra'),
            new Route('/page[/{page:\d+}/{locale:[a-z]{2}}[/optional-{extra:\w+}]]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'limit'),
            new Route('/api/{res:[a-z]+}[/{resId:\d+}[/{rel:[a-z]+}[/{relId:\d+}]]]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'api'),
            new Route('/optional-regex[/{optional:prefix-[a-z]+}]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'optional-regex'),
        ];

        return [
            // Test case                 routes   expected URI                   generateUri arguments
            'foo-create'             => [$routes, '/foo',                        ['foo-create']],
            'foo-list'               => [$routes, '/foo',                        ['foo-list']],
            'foo'                    => [$routes, '/foo/42',                     ['foo', ['id' => 42]]],
            'bar'                    => [$routes, '/bar/BAZ',                    ['bar', ['baz' => 'BAZ']]],
            'index'                  => [$routes, '/index',                      ['index']],
            'index-page'             => [$routes, '/index/42',                   ['index', ['page' => 42]]],
            'extra-42'               => [$routes, '/extra/42',                   ['extra', ['page' => 42]]],
            'extra-optional-segment' => [$routes, '/extra/42/optional-segment',  ['extra', ['page' => 42, 'extra' => 'segment']]],
            'limit'                  => [$routes, '/page/2/en/optional-segment', ['limit', ['locale' => 'en', 'page' => 2, 'extra' => 'segment']]],
            'api-optional-regex'     => [$routes, '/api/foo',                    ['api', ['res' => 'foo']]],
            'api-resource-id'        => [$routes, '/api/foo/1',                  ['api', ['res' => 'foo', 'resId' => 1]]],
            'api-relation'           => [$routes, '/api/foo/1/bar',              ['api', ['res' => 'foo', 'resId' => 1, 'rel' => 'bar']]],
            'api-relation-id'        => [$routes, '/api/foo/1/bar/2',            ['api', ['res' => 'foo', 'resId' => 1, 'rel' => 'bar', 'relId' => 2]]],
            'optional-regex'         => [$routes, '/optional-regex',             ['optional-regex']],
        ];
        // @codingStandardsIgnoreEnd
    }

    /**
     * @group zendframework/zend-expressive#53
     * @group 8
     * @dataProvider generatedUriProvider
     *
     * @param array $routes
     * @param string $expected
     * @param array $generateArgs
     */
    public function testCanGenerateUriFromRoutes(array $routes, $expected, array $generateArgs)
    {
        $router = new FastRouteRouter();
        foreach ($routes as $route) {
            $router->addRoute($route);
        }

        $uri = $router->generateUri(... $generateArgs);
        $this->assertEquals($expected, $uri);
    }

    public function testOptionsPassedToGenerateUriOverrideThoseFromRoute()
    {
        $route  = new Route(
            '/page[/{page:\d+}/{locale:[a-z]{2}}[/optional-{extra:\w+}]]',
            $this->getMiddleware(),
            [RequestMethod::METHOD_GET],
            'limit'
        );
        $route->setOptions(['defaults' => [
            'page'   => 1,
            'locale' => 'en',
            'extra'  => 'tag',
        ]]);

        $router = new FastRouteRouter();
        $router->addRoute($route);

        $uri = $router->generateUri('limit', [], ['defaults' => [
            'page'   => 5,
            'locale' => 'de',
            'extra'  => 'sort',
        ]]);
        $this->assertEquals('/page/5/de/optional-sort', $uri);
    }

    public function testReturnedRouteResultShouldContainRouteName()
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo-route');

        $uri     = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_GET);

        $this->dispatcher->dispatch(RequestMethod::METHOD_GET, '/foo')->willReturn([
            Dispatcher::FOUND,
            '/foo',
            ['bar' => 'baz']
        ]);

        $this->fastRouter->addRoute([RequestMethod::METHOD_GET], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('foo-route', $result->getMatchedRouteName());
    }

    public function uriGeneratorDataProvider()
    {
        return [
            // both param1 and params2 are missing => use route defaults
            ['/foo/abc/def', []],

            // param1 is passed to the uri generator => use it
            // param2 is missing => use route default
            ['/foo/123/def', ['param1' => '123']],

            // param1 is missing => use route default
            // param2 is passed to the uri generator => use it
            ['/foo/abc/456', ['param2' => '456']],

            // both param1 and param2 are passed to the uri generator
            ['/foo/123/456', ['param1' => '123', 'param2' => '456']],
        ];
    }

    /**
     * @dataProvider uriGeneratorDataProvider
     *
     * @param string $expectedUri
     * @param array $params
     */
    public function testUriGenerationSubstitutionsWithDefaultOptions($expectedUri, array $params)
    {
        $router = new FastRouteRouter();

        $route = new Route('/foo/{param1}/{param2}', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $route->setOptions([
            'defaults' => [
                'param1' => 'abc',
                'param2' => 'def',
            ],
        ]);

        $router->addRoute($route);

        $this->assertEquals($expectedUri, $router->generateUri('foo', $params));
    }

    /**
     * @dataProvider uriGeneratorDataProvider
     *
     * @param string $expectedUri
     * @param array $params
     */
    public function testUriGenerationSubstitutionsWithDefaultsAndOptionalParameters($expectedUri, array $params)
    {
        $router = new FastRouteRouter();

        $route = new Route('/foo/{param1}/{param2}', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $route->setOptions([
            'defaults' => [
                'param1' => 'abc',
                'param2' => 'def',
            ],
        ]);

        $router->addRoute($route);

        $this->assertEquals($expectedUri, $router->generateUri('foo', $params));
    }

    public function uriGeneratorWithPartialDefaultsDataProvider()
    {
        return [
            // required param1 is missing => use route default
            // optional param2 is missing and no route default => skip it
            ['/foo/abc', []],

            // required param1 is passed to the uri generator => use it
            // optional param2 is missing and no route default => skip it
            ['/foo/123', ['param1' => '123']],

            // required param1 is missing => use default
            // optional param2 is passed to the uri generator => use it
            ['/foo/abc/456', ['param2' => '456']],

            // both param1 and param2 are passed to the uri generator
            ['/foo/123/456', ['param1' => '123', 'param2' => '456']],
        ];
    }

    /**
     * @dataProvider uriGeneratorWithPartialDefaultsDataProvider
     *
     * @param string $expectedUri
     * @param array $params
     */
    public function testUriGenerationSubstitutionsWithPartialDefaultsAndOptionalParameters($expectedUri, array $params)
    {
        $router = new FastRouteRouter();

        $route = new Route('/foo/{param1}[/{param2}]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $route->setOptions([
            'defaults' => [
                'param1' => 'abc',
            ],
        ]);

        $router->addRoute($route);

        $this->assertEquals($expectedUri, $router->generateUri('foo', $params));
    }

    public function createCachingRouter(array $config, Route $route)
    {
        $router = new FastRouteRouter(null, null, $config);
        $router->addRoute($route);

        return $router;
    }

    public function createServerRequestProphecy($path, $method = RequestMethod::METHOD_GET)
    {
        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn($path);

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->will(function () use ($uri) {
            return $uri->reveal();
        });

        $request->getMethod()->willReturn($method);

        return $request;
    }

    public function testFastRouteCache()
    {
        $cache_file = __DIR__ . '/fastroute.cache';

        $config = [
            FastRouteRouter::CONFIG_CACHE_ENABLED => true,
            FastRouteRouter::CONFIG_CACHE_FILE    => $cache_file,
        ];

        $request = $this->createServerRequestProphecy('/foo', RequestMethod::METHOD_GET);

        $middleware = $this->getMiddleware();
        $route = new Route('/foo', $middleware, [RequestMethod::METHOD_GET], 'foo');

        $router1 = $this->createCachingRouter($config, $route);
        $router1->match($request->reveal());

        // cache file has been created with the specified path
        $this->assertTrue(is_file($cache_file));

        $cache1 = file_get_contents($cache_file);

        $router2 = $this->createCachingRouter($config, $route);

        $result = $router2->match($request->reveal());

        $this->assertTrue(is_file($cache_file));

        // reload the cache file content to check for changes
        $cache2 = file_get_contents($cache_file);

        $this->assertEquals($cache1, $cache2);

        // check that the routes defined and cached by $router1 are seen by
        // $router2
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('foo', $result->getMatchedRouteName());
        $this->assertSame($middleware, $result->getMatchedRoute()->getMiddleware());

        unlink($cache_file);
    }

    /**
     * Test for issue #30
     */
    public function testGenerateUriRaisesExceptionForMissingMandatoryParameters()
    {
        $router = new FastRouteRouter();
        $route = new Route('/foo/{id}', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $router->addRoute($route);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects at least parameter values for');

        $router->generateUri('foo');
    }

    public function testGenerateUriRaisesExceptionForNotFoundRoute()
    {
        $router = new FastRouteRouter();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('route not found');
        $router->generateUri('foo');
    }

    public function testRouteResultContainsDefaultAndMatchedParams()
    {
        $route = new Route('/foo/{id}', $this->getMiddleware());
        $route->setOptions(['defaults' => ['bar' => 'baz']]);

        $router = new FastRouteRouter();
        $router->addRoute($route);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo/my-id',
            RequestMethod::METHOD_GET
        );

        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(['bar' => 'baz', 'id' => 'my-id'], $result->getMatchedParams());
    }

    public function testMatchedRouteParamsOverrideDefaultParams()
    {
        $route = new Route('/foo/{bar}', $this->getMiddleware());
        $route->setOptions(['defaults' => ['bar' => 'baz']]);

        $router = new FastRouteRouter();
        $router->addRoute($route);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo/var',
            RequestMethod::METHOD_GET
        );

        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(['bar' => 'var'], $result->getMatchedParams());
    }

    public function testMatchedCorrectRoute()
    {
        $route1 = new Route('/foo', $this->getMiddleware());
        $route2 = new Route('/bar', $this->getMiddleware());

        $router = new FastRouteRouter();
        $router->addRoute($route1);
        $router->addRoute($route2);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/bar',
            RequestMethod::METHOD_GET
        );

        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame($route2, $result->getMatchedRoute());
    }

    public function testExceptionWhenCacheDirectoryDoesNotExist()
    {
        vfsStream::setup('root');

        $router = new FastRouteRouter(null, null, [
            FastRouteRouter::CONFIG_CACHE_ENABLED => true,
            FastRouteRouter::CONFIG_CACHE_FILE => vfsStream::url('root/dir/cache-file'),
        ]);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo',
            RequestMethod::METHOD_GET
        );

        $this->expectException(InvalidCacheDirectoryException::class);
        $this->expectExceptionMessage('does not exist');
        $router->match($request);
    }

    public function testExceptionWhenCacheDirectoryIsNotWritable()
    {
        $root = vfsStream::setup('root');
        vfsStream::newDirectory('dir', 0)->at($root);

        $router = new FastRouteRouter(null, null, [
            FastRouteRouter::CONFIG_CACHE_ENABLED => true,
            FastRouteRouter::CONFIG_CACHE_FILE => vfsStream::url('root/dir/cache-file'),
        ]);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo',
            RequestMethod::METHOD_GET
        );

        $this->expectException(InvalidCacheDirectoryException::class);
        $this->expectExceptionMessage('is not writable');
        $router->match($request);
    }

    public function testExceptionWhenCacheFileExistsButIsNotWritable()
    {
        $root = vfsStream::setup('root');
        $file = vfsStream::newFile('cache-file', 0)->at($root);

        $router = new FastRouteRouter(null, null, [
            FastRouteRouter::CONFIG_CACHE_ENABLED => true,
            FastRouteRouter::CONFIG_CACHE_FILE => $file->url(),
        ]);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo',
            RequestMethod::METHOD_GET
        );

        $this->expectException(InvalidCacheException::class);
        $this->expectExceptionMessage('is not writable');
        $router->match($request);
    }

    public function testExceptionWhenCacheFileExistsAndIsWritableButContainsNotAnArray()
    {
        $root = vfsStream::setup('root');
        $file = vfsStream::newFile('cache-file')->at($root);
        $file->setContent('<?php return "hello";');

        $this->expectException(InvalidCacheException::class);
        $this->expectExceptionMessage('MUST return an array');
        new FastRouteRouter(null, null, [
            FastRouteRouter::CONFIG_CACHE_ENABLED => true,
            FastRouteRouter::CONFIG_CACHE_FILE => $file->url(),
        ]);
    }

    public function testGetAllAllowedMethods()
    {
        $route1 = new Route('/foo', $this->getMiddleware());
        $route2 = new Route('/bar', $this->getMiddleware(), [RequestMethod::METHOD_GET, RequestMethod::METHOD_POST]);
        $route3 = new Route('/bar', $this->getMiddleware(), [RequestMethod::METHOD_DELETE]);

        $router = new FastRouteRouter();
        $router->addRoute($route1);
        $router->addRoute($route2);
        $router->addRoute($route3);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_HEAD],
            [],
            '/bar',
            RequestMethod::METHOD_HEAD
        );

        $result = $router->match($request);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
        $this->assertSame(
            [RequestMethod::METHOD_GET, RequestMethod::METHOD_POST, RequestMethod::METHOD_DELETE],
            $result->getAllowedMethods()
        );
    }

    public function testCustomDispatcherCallback()
    {
        $route1 = new Route('/foo', $this->getMiddleware());
        $dispatcher = $this->prophesize(Dispatcher::class);
        $dispatcher->dispatch(RequestMethod::METHOD_GET, '/foo')
            ->shouldBeCalled()
            ->willReturn([
                Dispatcher::FOUND,
                '/foo',
                []
            ]);

        $router = new FastRouteRouter(null, [$dispatcher, 'reveal']);
        $router->addRoute($route1);

        $request = new ServerRequest([], [], '/foo');
        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
    }
}
