<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-fastroute for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-fastroute/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ZendTest\Expressive\Router;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Interop\Http\Server\MiddlewareInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ProphecyInterface;
use Zend\Expressive\Router\Exception\InvalidArgumentException;
use Zend\Expressive\Router\FastRouteRouter;
use Zend\Expressive\Router\Route;

class UriGeneratorTest extends TestCase
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

    /**
     * @var FastRouteRouter
     */
    private $router;

    /**
     * Test routes taken from https://github.com/nikic/FastRoute/blob/master/test/RouteParser/StdTest.php
     *
     * @return array
     */
    public function provideRoutes()
    {
        return [
            'test_param_regex'       => '/test/{param:\d+}',
            'test_param_regex_limit' => '/test/{ param : \d{1,9} }',
            'test_optional'          => '/test[opt]',
            'test_optional_param'    => '/test[/{param}]',
            'param_and_opt'          => '/{param}[opt]',
            'test_double_opt'        => '/test[/{param}[/{id:[0-9]+}]]',
            'empty'                  => '',
            'optional_text'          => '[test]',
            'root_and_text'          => '/{foo-bar}',
            'root_and_regex'         => '/{_foo:.*}',
        ];
    }

    /**
     * @return array
     */
    public function provideRouteTests()
    {
        return [
            // path // substitutions[] // expected // exception
            ['/test', [], '/test', null],

            ['/test/{param}', ['param' => 'foo'], '/test/foo', null],
            [
                '/test/{param}',
                ['id' => 'foo'],
                InvalidArgumentException::class,
                'expects at least parameter values for',
            ],

            ['/te{ param }st', ['param' => 'foo'], '/tefoost', null],

            ['/test/{param1}/test2/{param2}', ['param1' => 'foo', 'param2' => 'bar'], '/test/foo/test2/bar', null],

            ['/test/{param:\d+}', ['param' => 1], '/test/1', null],
            //['/test/{param:\d+}', ['param' => 'foo'], 'exception', null],

            ['/test/{ param : \d{1,9} }', ['param' => 1], '/test/1', null],
            ['/test/{ param : \d{1,9} }', ['param' => 123456789], '/test/123456789', null],
            ['/test/{ param : \d{1,9} }', ['param' => 0], '/test/0', null],
            [
                '/test/{ param : \d{1,9} }',
                ['param' => 1234567890],
                InvalidArgumentException::class,
                'Parameter value for [param] did not match the regex `\d{1,9}`',
            ],

            ['/test[opt]', [], '/testopt', null],

            ['/test[/{param}]', [], '/test', null],
            ['/test[/{param}]', ['param' => 'foo'], '/test/foo', null],

            ['/{param}[opt]', ['param' => 'foo'], '/fooopt', null],

            ['/test[/{param}[/{id:[0-9]+}]]', [], '/test', null],
            ['/test[/{param}[/{id:[0-9]+}]]', ['param' => 'foo'], '/test/foo', null],
            ['/test[/{param}[/{id:[0-9]+}]]', ['param' => 'foo', 'id' => 1], '/test/foo/1', null],
            ['/test[/{param}[/{id:[0-9]+}]]', ['id' => 1], '/test', null],
            [
                '/test[/{param}[/{id:[0-9]+}]]',
                ['param' => 'foo', 'id' => 'foo'],
                InvalidArgumentException::class,
                'Parameter value for [id] did not match the regex `[0-9]+`',
            ],

            ['', [], '', null],

            ['[test]', [], 'test', null],

            ['/{foo-bar}', ['foo-bar' => 'bar'], '/bar', null],

            ['/{_foo:.*}', ['_foo' => 'bar'], '/bar', null],
        ];
    }

    protected function setUp()
    {
        $this->fastRouter       = $this->prophesize(RouteCollector::class);
        $this->dispatcher       = $this->prophesize(Dispatcher::class);
        $this->dispatchCallback = function () {
            return $this->dispatcher->reveal();
        };

        $this->router = new FastRouteRouter(
            $this->fastRouter->reveal(),
            $this->dispatchCallback
        );
    }

    private function getMiddleware() : MiddlewareInterface
    {
        return $this->prophesize(MiddlewareInterface::class)->reveal();
    }

    /**
     * @param $path
     * @param $substitutions
     * @param $expected
     * @param $message
     *
     * @dataProvider provideRouteTests
     */
    public function testRoutes($path, $substitutions, $expected, $message)
    {
        $this->router->addRoute(new Route($path, $this->getMiddleware(), ['GET'], 'foo'));

        if ($message !== null) {
            // Test exceptions
            $this->expectException($expected);
            $this->expectExceptionMessage($message);

            $this->router->generateUri('foo', $substitutions);

            return;
        }

        $this->assertEquals($expected, $this->router->generateUri('foo', $substitutions));

        // Test with extra parameter
        $substitutions['extra'] = 'parameter';
        $this->assertEquals($expected, $this->router->generateUri('foo', $substitutions));
    }
}
