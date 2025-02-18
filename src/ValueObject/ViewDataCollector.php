<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

use Illuminate\Contracts\View\View;

class ViewDataCollector implements View
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(
        private readonly string $viewName,
    ) {
    }

    public function name(): string
    {
        return $this->viewName;
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
