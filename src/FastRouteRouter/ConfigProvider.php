<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-fastroute for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-fastroute/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Zend\Expressive\Router\FastRouteRouter;

use Zend\Expressive\Router\FastRouteRouter;
use Zend\Expressive\Router\FastRouteRouterFactory;
use Zend\Expressive\Router\RouterInterface;

class ConfigProvider
{
    public function __invoke() : array
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    public function getDependencies() : array
    {
        return [
            'aliases' => [
                RouterInterface::class => FastRouteRouter::class,
            ],
            'factories' => [
                FastRouteRouter::class => FastRouteRouterFactory::class,
            ],
        ];
    }
}
