<?php

namespace App\Libraries;

use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Safe display of an original mail (postmaster@/abuse@): headers, text part, HTML part only inside a
 * sandboxed iframe (srcdoc, no scripts, no forms, no external content), attachments as a list.
 */
class MailView
{
    /**
     * @return array<string, mixed>
     */
    public static function parse(string $raw): array
    {
        $msg = (new MailMimeParser())->parse($raw, false);
        $atts = [];
        foreach ($msg->getAllAttachmentParts() as $part) {
            $atts[] = [
                'name' => $part->getFilename() ?? '(no name)',
                'type' => $part->getContentType() ?? 'application/octet-stream',
                'size' => strlen((string) $part->getContent()),
            ];
        }
        $html = $msg->getHtmlContent();

        return [
            'from'        => (string) $msg->getHeaderValue(HeaderConsts::FROM),
            'from_full'   => (string) ($msg->getHeader(HeaderConsts::FROM)?->getRawValue() ?? ''),
            'to'          => (string) ($msg->getHeader(HeaderConsts::TO)?->getRawValue() ?? ''),
            'cc'          => (string) ($msg->getHeader(HeaderConsts::CC)?->getRawValue() ?? ''),
            'subject'     => (string) ($msg->getHeaderValue(HeaderConsts::SUBJECT) ?? ''),
            'date'        => (string) ($msg->getHeader(HeaderConsts::DATE)?->getRawValue() ?? ''),
            'text'        => $msg->getTextContent(),
            'html_srcdoc' => $html === null ? null : self::srcdoc($html),
            'attachments' => $atts,
        ];
    }

    /** HTML wrapped with a CSP that blocks every external load (tracking pixels, scripts, fonts). */
    private static function srcdoc(string $html): string
    {
        $csp = "<meta http-equiv=\"Content-Security-Policy\" content=\"default-src 'none'; style-src 'unsafe-inline'; img-src data:\">";

        return $csp . '<base target="_blank">' . $html;
    }
}
