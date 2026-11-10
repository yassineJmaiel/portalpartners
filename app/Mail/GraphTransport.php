<?php

namespace App\Mail;

use App\Support\GraphMail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/* Laravel's mailer over Microsoft 365: MAIL_MAILER=graph sends every Mail:: message through
   App\Support\GraphMail (Microsoft Graph, Mail.Send), with its recipients, HTML or text body and attachments. */
class GraphTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $list = fn (array $a) => array_map(fn (Address $x) => $x->getAddress(), $a);
        $html = $email->getHtmlBody();
        $out = GraphMail::send([
            'to' => $list($email->getTo()), 'cc' => $list($email->getCc()), 'bcc' => $list($email->getBcc()),
            'from' => ($email->getFrom()[0] ?? null)?->getAddress(), 'replyTo' => ($email->getReplyTo()[0] ?? null)?->getAddress(),
            'subject' => (string) $email->getSubject(), 'html' => $html !== null, 'body' => (string) ($html ?? $email->getTextBody() ?? ''),
            'attachments' => array_map(fn ($part) => ['name' => $part->getFilename() ?: 'attachment', 'contentType' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'content' => $part->getBody()], $email->getAttachments()),
        ]);
        if (! $out['ok']) {
            throw new TransportException($out['error'] ?? 'Microsoft 365 did not accept the message.');
        }
    }

    public function __toString(): string
    {
        return 'graph';
    }
}
