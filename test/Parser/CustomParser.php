<?php

declare(strict_types=1);

namespace ZendTest\Expressive\Router\Parser;

use FastRoute\RouteParser\Std;

use function array_keys;
use function array_values;
use function preg_replace;


class CustomParser extends Std
{
    protected $patterns = [
        '/{(.+?):uuid}/'          => '{$1:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}+}',
    ];

    public function parse($route) : array
    {
        $route = $this->replaceAliasWithRegex($route);
        return parent::parse($route);
    }


    protected function replaceAliasWithRegex(string $path) : string
    {
        return preg_replace(array_keys($this->patterns), array_values($this->patterns), $path);
    }
}
