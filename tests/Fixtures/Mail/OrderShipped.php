<?php

namespace Langsys\Laravel\Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A Mailable as an app writes one: its subject in the envelope, its body a Blade view. */
class OrderShipped extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your order has shipped'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.shipped');
    }
}
