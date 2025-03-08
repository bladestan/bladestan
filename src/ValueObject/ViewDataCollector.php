<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;

class ViewDataCollector implements View
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
            $this->data = array_merge($this->data, $key);
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
