<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

trait IncludesDgiEmailLogo
{
    protected function withDgiEmailLogo(MailMessage $message): MailMessage
    {
        return $message->withSymfonyMessage(static function (Email $email): void {
            $logo = DataPart::fromPath(public_path('logo-dgi.png'), 'logo-dgi.png', 'image/png')
                ->asInline()
                ->setContentId('logo-dgi@dgi-systeme-analyse');

            $email->addPart($logo);
        });
    }
}
