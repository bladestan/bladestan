<?php declare(strict_types=1);

use Illuminate\Mail\Mailable;

class ImplicitlyPassingPublicProperties extends Mailable
{
    public function __construct(
        public string $foo,
    ) {}

    public function build(): self
    {
        $this->view('foo', [
            'unused' => 'value',
        ]);
        $this->html('foo', [
            'unused' => 'value',
        ]);
        $this->markdown('foo', [
            'unused' => 'value',
        ]);
        $this->text('foo', [
            'unused' => 'value',
        ]);
    }
}
