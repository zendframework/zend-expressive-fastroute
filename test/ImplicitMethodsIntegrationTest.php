<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-fastroute for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-fastroute/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ZendTest\Expressive\Router;

use Generator;
use Zend\Expressive\Router\FastRouteRouter;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Router\Test\ImplicitMethodsIntegrationTest as RouterIntegrationTest;

class ImplicitMethodsIntegrationTest extends RouterIntegrationTest
{
    public function getRouter() : RouterInterface
    {
        return new FastRouteRouter();
    }

    public function implicitRoutesAndRequests() : Generator
    {
        // @codingStandardsIgnoreStart
        //                  route                     route options, request       params
        yield 'static'  => ['/api/v1/me',             [],            '/api/v1/me', []];
        yield 'dynamic' => ['/api/v{version:\d+}/me', [],            '/api/v3/me', ['version' => '3']];
        // @codingStandardsIgnoreEnd
    }
}
