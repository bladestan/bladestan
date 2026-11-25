<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\NodeAnalyzer\ValueResolver;
use Bladestan\ValueObject\DataCollectingView;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ViewErrorBag;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Throwable;

/**
 * The types of the data Laravel puts into a template's scope on its own: the
 * data shared with every view, and what a view's composers add.
 *
 * The compiler declares these types in a compiled template, and the result
 * cache compares them between runs to know when a template has to be analysed
 * again. Both ask here so they cannot disagree.
 *
 * Only usable once the application has booted.
 */
final class ViewDataResolver
{
    public function __construct(
        private readonly ValueResolver $valueResolver,
    ) {
    }

    /**
     * The data shared with every view, including the error bag Laravel always
     * shares.
     *
     * @return array<string, Type>
     */
    public function shared(): array
    {
        $shared = [
            'errors' => new ObjectType(ViewErrorBag::class),
        ];
        foreach ($this->viewFactory()->getShared() as $name => $value) {
            $shared[(string) $name] = $this->valueResolver->resolve($value);
        }

        return $shared;
    }

    /**
     * The data the view's composers add.
     *
     * @return array<string, Type>
     * @throws Throwable when a composer throws
     */
    public function composed(string $viewName): array
    {
        $viewFactory = $this->viewFactory();
        $dataCollectingView = new DataCollectingView($viewName, $viewFactory);

        /** @throws Throwable */
        $viewFactory->callComposer($dataCollectingView);

        $viewData = [];
        foreach ($dataCollectingView->getData() as $name => $value) {
            $viewData[(string) $name] = $this->valueResolver->resolve($value);
        }

        return $viewData;
    }

    private function viewFactory(): ViewFactory
    {
        return resolve(ViewFactory::class);
    }
}
