<?php

namespace Langsys\Laravel\Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;

/** A Mailable whose `content()` translates, which runs before the mailer is reached. */
class PaymentDue extends Mailable
{
    public function content(): Content
    {
        return new Content(htmlString: '<p>' . e(__('Pay now')) . '</p>');
    }
}
