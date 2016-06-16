# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 1.2.0 - 2016-06-16

### Added

- [#17](https://github.com/zendframework/zend-expressive-fastroute/pull/17) upgraded
  the dependency to [`nikic/fast-route`](https://github.com/nikic/FastRoute) to
  [`^1.0.0`](https://github.com/nikic/FastRoute/releases/tag/v1.0.0).

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.1.1 - 2015-05-03

### Added

- [#7](https://github.com/zendframework/zend-expressive-fastroute/pull/7) adds
  support for merging the `defaults` passed in route options with the matched
  parameters when returning a route result. As an example, if you define a route
  as follows:

  ```php
  use Zend\Expressive\Router\Route;

  $route = new Route(
      '/category/{category:[a-z]{3,12}[/resource/{resource:\d+}]',
      'CategoryResource',
      ['GET'],
      'category-resource'
  );
  $route->setOptions(['defaults' => [
      'resource' => 1,
  ]]);
  ```

  and match against the URL path `/category/foobar`, the route result returned
  will now also include a `resource` parameter with a value of `1`.

  This provides feature parity with other routing implementations.

- [#14](https://github.com/zendframework/zend-expressive-fastroute/pull/14) updates
  the FastRoute minimum version to `^0.8.0`. No BC break is expected by this change,
  but you should test your application to confirm.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- [#4](https://github.com/zendframework/zend-expressive-fastroute/pull/4) fixes
  URI generation when optional segments are in place, and ensures that if an
  optional segment with a placeholder is missing, but followed by one that is
  present, an exception is raised.
- [#8](https://github.com/zendframework/zend-expressive-fastroute/pull/8) fixes
  URI generation with variable substitution when the variable declaration in the
  route uses `{X,Y}` quantification.

## 1.1.0 - 2016-01-25

### Added

- [#6](https://github.com/zendframework/zend-expressive-fastroute/pull/6)
  updates the FastRoute minimum version to `^0.7.0`. No BC break is expected by
  this change, but you should test your application to confirm.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 1.0.1 - 2015-12-14

### Added

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- [#3](https://github.com/zendframework/zend-expressive-fastroute/pull/3) fixes
  an issue in how the `RouteResult` was marshaled on success. Previously, the
  path was used for the matched route name; now the route name is properly used.

## 1.0.0 - 2015-12-07

First stable release.

### Added

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.

## 0.3.0 - 2015-12-02

### Added

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Now depends on [zendframework/zend-expressive-router](https://github.com/zendframework/zend-expressive-router)
  instead of zendframework/zend-expressive.

## 0.2.0 - 2015-10-20

### Added

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Updated zend-expressive to RC1.
- Added branch alias for dev-master, pointing to 1.0-dev.

## 0.1.1 - 2015-10-10

### Added

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Moved nikic/fast-route from `require-dev` to `require` section.

## 0.1.0 - 2015-10-10

Initial release.

### Added

- Nothing.

### Deprecated

- Nothing.

### Removed

- Nothing.

### Fixed

- Nothing.
