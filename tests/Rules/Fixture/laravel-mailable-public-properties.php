<?php declare(strict_types=1);

use Illuminate\Mail\Mailable;

class ImplicitlyPassingPublicProperties extends Mailable
{
    public function __construct(
        public string $foo,
    ) {}

    /** @return $this */
    public function build(): self
    {
        return $this->view('foo', [
            'unused' => 'value',
        ]);
    }
}
