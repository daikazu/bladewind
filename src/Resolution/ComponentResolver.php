<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Resolution;

use Illuminate\Contracts\Container\Container;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\ComponentTagCompiler;
use Throwable;

final class ComponentResolver
{
    public function __construct(
        private Container $container,
        private ViewResolver $views,
    ) {}

    public function resolve(string $name): Resolved
    {
        try {
            $target = $this->tagCompiler()->componentClass($name);
        } catch (Throwable) {
            return Resolved::unresolved();
        }

        if (class_exists($target)) {
            return Resolved::forClass($target);
        }

        return Resolved::forView($target, $this->views->path($target));
    }

    /**
     * Built the same way Illuminate\View\DynamicComponent builds its compiler,
     * so resolution order matches the framework exactly.
     */
    private function tagCompiler(): ComponentTagCompiler
    {
        /** @var BladeCompiler $blade */
        $blade = $this->container->make('blade.compiler');

        return new ComponentTagCompiler(
            $blade->getClassComponentAliases(),
            $blade->getClassComponentNamespaces(),
            $blade,
        );
    }
}
