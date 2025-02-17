<?php

declare(strict_types=1);

namespace LaravelMailableMethod;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;

class MyMailable extends Mailable
{
    /**
     * @return $this
     */
    public function build()
    {
        return $this->view('foo', [
            'foo' => 'bar',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'foo',
            with: [
                'foo' => 'bar',
            ],
        );
    }

    /**
     * @return $this
     */
    public function buildWith()
    {
        return $this->view('foo')->with([
            'foo' => 'bar',
        ]);
    }

    public function markdown(): Content
    {
        return new Content(
            markdown: 'foo',
            with: [
                'foo' => 'bar',
            ],
        );
    }

    public function text(): Content
    {
        return new Content(
            text: 'foo',
            with: [
                'foo' => 'bar',
            ],
        );
    }

    public function html(): Content
    {
        return new Content(
            html: 'foo',
            with: [
                'foo' => 'bar',
            ],
        );
    }

    public function htmlAndText(): Content
    {
        return new Content(
            markdown: 'foo',
            text: 'simple_variable',
            with: [
                'foo' => 'bar',
            ],
        );
    }
}
