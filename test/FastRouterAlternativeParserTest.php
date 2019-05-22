<?php


namespace ZendTest\Expressive\Router;


use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ProphecyInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Zend\Expressive\Router\FastRouteRouter;
use Zend\Expressive\Router\FastRouteRouterFactory;
use Zend\Expressive\Router\Route;
use ZendTest\Expressive\Router\Parser\CustomParser;

class FastRouterAlternativeParserTest extends TestCase
{

    private function getMiddleware() : MiddlewareInterface
    {
        return $this->prophesize(MiddlewareInterface::class)->reveal();
    }

    public function testCustomRouteParser()
    {
        $sampleRoute = new Route('/custom/{id:uuid}',$this->getMiddleware(),[RequestMethod::METHOD_GET],'custom');
        $sampleRoute->setOptions([
            'defaults' => [
                'id' => 'e027aa0f-1dcc-4c7b-96fc-bd401e9feb77'
            ]
        ]);

        $router = new FastRouteRouter(
            new RouteCollector(
                new CustomParser(),
                new GroupCountBased()
            )
        );

        $router->addRoute($sampleRoute);

        $uri = $router->generateUri('custom',[],['defaults' => [
            'id' => 'e027aa0f-1dcc-4c7b-96fc-bd401e9feb77'
        ]]);

        $this->assertEquals('/custom/e027aa0f-1dcc-4c7b-96fc-bd401e9feb77',$uri);
    }




}