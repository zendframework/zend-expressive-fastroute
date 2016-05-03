<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @see       https://github.com/zendframework/zend-expressive for the canonical source repository
 * @copyright Copyright (c) 2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive/blob/master/LICENSE.md New BSD License
 */

namespace ZendTest\Expressive\Router;

use FastRoute\Dispatcher\GroupCountBased as Dispatcher;
use FastRoute\RouteCollector;
use PHPUnit_Framework_TestCase as TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Zend\Expressive\Router\Exception\InvalidArgumentException;
use Zend\Expressive\Router\FastRouteRouter;
use Zend\Expressive\Router\Route;
use Zend\Expressive\Router\RouteResult;

class FastRouteRouterTest extends TestCase
{
    public function setUp()
    {
        $this->fastRouter = $this->prophesize(RouteCollector::class);
        $this->dispatcher = $this->prophesize(Dispatcher::class);
        $this->dispatchCallback = function ($data) {
            return $this->dispatcher->reveal();
        };
    }

    public function getRouter()
    {
        return new FastRouteRouter(
            $this->fastRouter->reveal(),
            $this->dispatchCallback
        );
    }

    public function testWillLazyInstantiateAFastRouteCollectorIfNoneIsProvidedToConstructor()
    {
        $router = new FastRouteRouter();
        $this->assertAttributeInstanceOf(RouteCollector::class, 'router', $router);
    }

    public function testAddingRouteAggregatesRoute()
    {
        $route = new Route('/foo', 'foo', ['GET']);
        $router = $this->getRouter();
        $router->addRoute($route);
        $this->assertAttributeContains($route, 'routesToInject', $router);
    }

    /**
     * @depends testAddingRouteAggregatesRoute
     */
    public function testMatchingInjectsRouteIntoFastRoute()
    {
        $route = new Route('/foo', 'foo', ['GET']);
        $this->fastRouter->addRoute(['GET'], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();
        $this->dispatcher->dispatch('GET', '/foo')->willReturn([
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
        $request->getMethod()->willReturn('GET');

        $router->match($request->reveal());
    }

    /**
     * @depends testAddingRouteAggregatesRoute
     */
    public function testGeneratingUriInjectsRouteIntoFastRoute()
    {
        $route = new Route('/foo', 'foo', ['GET'], 'foo');
        $this->fastRouter->addRoute(['GET'], '/foo', '/foo')->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        $this->assertEquals('/foo', $router->generateUri('foo'));
    }

    public function testIfRouteSpecifiesAnyHttpMethodFastRouteIsPassedHardCodedListOfMethods()
    {
        $route = new Route('/foo', 'foo');
        $this->fastRouter->addRoute([
            'GET',
            'POST',
            'PUT',
            'PATCH',
            'DELETE',
            'HEAD',
            'OPTIONS',
            'TRACE'
        ], '/foo', '/foo')->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        // routes are not injected until match or generateUri
        $router->generateUri($route->getName());
    }

    public function testIfRouteSpecifiesNoHttpMethodsFastRouteIsPassedHardCodedListOfMethods()
    {
        $route = new Route('/foo', 'foo', []);
        $this->fastRouter->addRoute([
            'GET',
            'HEAD',
            'OPTIONS',
        ], '/foo', '/foo')->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        // routes are not injected until match or generateUri
        $router->generateUri($route->getName());
    }

    public function testMatchingRouteShouldReturnSuccessfulRouteResult()
    {
        $route = new Route('/foo', 'foo', ['GET']);

        $uri     = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('GET');

        $this->dispatcher->dispatch('GET', '/foo')->willReturn([
            Dispatcher::FOUND,
            '/foo',
            ['bar' => 'baz']
        ]);

        $this->fastRouter->addRoute(['GET'], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('/foo^GET', $result->getMatchedRouteName());
        $this->assertEquals('foo', $result->getMatchedMiddleware());
        $this->assertSame(['bar' => 'baz'], $result->getMatchedParams());
    }

    public function testMatchFailureDueToHttpMethodReturnsRouteResultWithAllowedMethods()
    {
        $route = new Route('/foo', 'foo', ['POST']);

        $uri     = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('GET');

        $this->dispatcher->dispatch('GET', '/foo')->willReturn([
            Dispatcher::METHOD_NOT_ALLOWED,
            ['POST']
        ]);

        $this->fastRouter->addRoute(['POST'], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isMethodFailure());
        $this->assertSame(['POST'], $result->getAllowedMethods());
    }

    public function testMatchFailureNotDueToHttpMethodReturnsGenericRouteFailureResult()
    {
        $route = new Route('/foo', 'foo', ['GET']);

        $uri     = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/bar');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('GET');

        $this->dispatcher->dispatch('GET', '/bar')->willReturn([
            Dispatcher::NOT_FOUND,
        ]);

        $this->fastRouter->addRoute(['GET'], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isMethodFailure());
        $this->assertSame([], $result->getAllowedMethods());
    }

    /**
     * @group 53
     */
    public function testCanGenerateUriFromRoutes()
    {
        $router = new FastRouteRouter();
        $route1 = new Route('/foo', 'foo', ['POST'], 'foo-create');
        $route2 = new Route('/foo', 'foo', ['GET'], 'foo-list');
        $route3 = new Route('/foo/{id:\d+}', 'foo', ['GET'], 'foo');
        $route4 = new Route('/bar/{baz}', 'bar', Route::HTTP_METHOD_ANY, 'bar');
        $route5 = new Route('/index[/{page:\d+}]', 'foo', ['GET'], 'index');
        $route6 = new Route('/extra[/{page:\d+}[/optional-{extra:\w+}]]', 'foo', ['GET'], 'extra');
        $route7 = new Route('/page[/{page:\d+}/{locale:[a-z]{2}}[/optional-{extra:\w+}]]', 'foo', ['GET'], 'limit');

        $router->addRoute($route1);
        $router->addRoute($route2);
        $router->addRoute($route3);
        $router->addRoute($route4);
        $router->addRoute($route5);
        $router->addRoute($route6);
        $router->addRoute($route7);

        $this->assertEquals('/foo', $router->generateUri('foo-create'));
        $this->assertEquals('/foo', $router->generateUri('foo-list'));
        $this->assertEquals('/foo/42', $router->generateUri('foo', ['id' => 42]));
        $this->assertEquals('/bar/BAZ', $router->generateUri('bar', ['baz' => 'BAZ']));
        $this->assertEquals('/index', $router->generateUri('index'));
        $this->assertEquals('/index/42', $router->generateUri('index', ['page' => 42]));
        $this->assertEquals('/extra/42', $router->generateUri('extra', ['page' => 42]));
        $this->assertEquals('/extra/42/optional-segment', $router->generateUri('extra', [
            'page'  => 42,
            'extra' => 'segment'
        ]));
        $this->assertEquals('/page/42/en/optional-segment', $router->generateUri('limit', [
            'locale'=> 'en',
            'page'  => 2,
            'extra' => 'segment'
        ]));
    }

    public function testReturnedRouteResultShouldContainRouteName()
    {
        $route = new Route('/foo', 'foo', ['GET'], 'foo-route');

        $uri     = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('GET');

        $this->dispatcher->dispatch('GET', '/foo')->willReturn([
            Dispatcher::FOUND,
            '/foo',
            ['bar' => 'baz']
        ]);

        $this->fastRouter->addRoute(['GET'], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('foo-route', $result->getMatchedRouteName());
    }

    public function testGenerateUriRaisesExceptionForIncompleteUriSubstitutions()
    {
        $router = new FastRouteRouter();
        $route = new Route('/foo[/{param}[/optional-{extra}]]', 'foo', ['GET'], 'foo');
        $router->addRoute($route);

        $this->setExpectedException(InvalidArgumentException::class, 'unsubstituted parameters');
        $router->generateUri('foo', ['extra' => 'segment']);
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
     */
    public function testUriGenerationSubstitutionsWithDefaultOptions($expectedUri, $params)
    {
        $router = new FastRouteRouter();

        $route = new Route('/foo/{param1}/{param2}', 'foo', ['GET'], 'foo');
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
     */
    public function testUriGenerationSubstitutionsWithDefaultsAndOptionalParameters($expectedUri, $params)
    {
        $router = new FastRouteRouter();

        $route = new Route('/foo/{param1}/{param2}', 'foo', ['GET'], 'foo');
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
     */
    public function testUriGenerationSubstitutionsWithPartialDefaultsAndOptionalParameters($expectedUri, $params)
    {
        $router = new FastRouteRouter();

        $route = new Route('/foo/{param1}[/{param2}]', 'foo', ['GET'], 'foo');
        $route->setOptions([
            'defaults' => [
                'param1' => 'abc',
            ],
        ]);

        $router->addRoute($route);

        $this->assertEquals($expectedUri, $router->generateUri('foo', $params));
    }
}
