<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-fastroute for the canonical source repository
 * @copyright Copyright (c) 2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-fastroute/blob/master/LICENSE.md New BSD License
 */

namespace Zend\Expressive\Router;

use Interop\Container\ContainerInterface;

/**
 * Create and return an instance of FastRouteRouter.
 *
 * Configuration should look like the following:
 *
 * <code>
 * 'router' => [
 *     'fastroute' => [
 *         'cache_enabled' => true, // true|false
 *         'cache_file'   => '(/absolute/)path/to/cache/file', // optional
 *     ],
 * ]
 * </code>
 */
class FastRouteRouterFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $config = $container->has('config')
            ? $container->get('config')
            : [];

        $config = isset($config['router']['fastroute'])
            ? $config['router']['fastroute']
            : [];

        return new FastRouteRouter(null, null, $config);
    }
}
