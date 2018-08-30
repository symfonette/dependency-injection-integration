Dependency Injection integration
================================

[![Packagist](https://img.shields.io/packagist/v/symfonette/dependency-injection-integration.svg?style=flat-square)](https://packagist.org/packages/symfonette/dependency-injection-integration)
[![Build Status](https://img.shields.io/travis/symfonette/dependency-injection-integration.svg?style=flat-square)](https://travis-ci.org/symfonette/dependency-injection-integration)
[![Scrutinizer Code Quality](https://img.shields.io/scrutinizer/g/symfonette/dependency-injection-integration.svg?style=flat-square)](https://scrutinizer-ci.com/g/symfonette/dependency-injection-integration)

Integration Symfony Dependency Injection with Nette DI and vice versa.

Installation
------------

This project can be installed via Composer:

    composer require symfonette/dependency-injection-integration

Usage
-----

#### Nette

```neon
extensions:
  symfony: Symfonette\DependencyInjectionIntegration\DI\SymfonyExtension(%appDir%/.., %debugMode%, prod)
```
