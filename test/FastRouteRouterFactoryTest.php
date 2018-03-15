<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-fastroute for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-fastroute/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ZendTest\Expressive\Router;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Zend\Expressive\Router\FastRouteRouter;
use Zend\Expressive\Router\FastRouteRouterFactory;

class FastRouteRouterFactoryTest extends TestCase
{
    /** @var FastRouteRouterFactory */
    private $factory;

    private $container;

    protected function setUp()
    {
        $this->factory = new FastRouteRouterFactory();
        $this->container = $this->prophesize(ContainerInterface::class);
    }

    public function testCreatesRouterWithEmptyConfig()
    {
        $this->container->has('config')->willReturn(false);

        $router = ($this->factory)($this->container->reveal());

        $this->assertInstanceOf(FastRouteRouter::class, $router);
        $this->assertAttributeSame(false, 'cacheEnabled', $router);
        $this->assertAttributeSame('data/cache/fastroute.php.cache', 'cacheFile', $router);
    }

    public function testCreatesRouterWithConfig()
    {
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn([
            'router' => [
                'fastroute' => [
                    FastRouteRouter::CONFIG_CACHE_ENABLED => true,
                    FastRouteRouter::CONFIG_CACHE_FILE => '/foo/bar/file-cache',
                ],
            ],
        ]);

        $router = ($this->factory)($this->container->reveal());

        $this->assertInstanceOf(FastRouteRouter::class, $router);
        $this->assertAttributeSame(true, 'cacheEnabled', $router);
        $this->assertAttributeSame('/foo/bar/file-cache', 'cacheFile', $router);
    }
}
