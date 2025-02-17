<?php

declare(strict_types=1);

namespace LaravelMailMessageMethod;

use Illuminate\Notifications\Messages\MailMessage;

class MyMailMessage extends MailMessage
{
    public function build()
    {
        $this->view('foo', [
            'foo' => 'bar',
        ]);
        $this->markdown('foo', [
            'foo' => 'bar',
        ]);
        $this->text('foo', [
            'foo' => 'bar',
        ]);
    }
}
