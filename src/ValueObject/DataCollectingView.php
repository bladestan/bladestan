<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;

/**
 * A stand-in {@see View} handed to Laravel's view composers so the data they
 * inject can be captured instead of rendered.
 *
 * The compiler runs a template's composers against this object; each `with()`
 * call records data rather than binding it to a real view, and
 * {@see getData()} returns everything the composers contributed.
 */
class DataCollectingView implements View
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(
        private readonly string $viewName,
        private readonly ViewFactory $viewFactory,
    ) {
    }

    public function name(): string
    {
        return $this->viewName;
    }

    /**
     * Used by code that incorrectly assumes Illuminate\View\View
     */
    public function getName(): string
    {
        return $this->viewName;
    }

    /**
     * Used by code that incorrectly assumes Illuminate\View\View
     */
    public function getPath(): string
    {
        return $this->viewFactory->getFinder()
            ->find($this->viewName);
    }

    /**
     * @param string|array<string, mixed> $key
     * @param mixed $value
     */
    public function with($key, $value = null): self
    {
        if (is_array($key)) {
            $this->data = [...$this->data, ...$key];
        } else {
            $this->data[$key] = $value;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function render()
    {
        return '';
    }
}
