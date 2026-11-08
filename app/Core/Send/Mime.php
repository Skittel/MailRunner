<?php

namespace App\Core\Send;

use App\Core\Projects\ProjectService;
use App\Core\Support\ConfigException;

/**
 * Small MIME helpers for the send role: address parsing, building a message from structured API input and
 * preparing a raw message (CRLF, no Bcc header, Date and Message-ID added when missing). UTF-8 throughout;
 * addresses must be ASCII in the local part (no SMTPUTF8 yet), IDN domains are converted to their ASCII form.
 */
final class Mime
{
    /** Headers the structured API sets itself; custom headers must not use them. */
    private const RESERVED = ['from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'mime-version', 'sender',
        'return-path', 'received', 'dkim-signature', 'content-type', 'content-transfer-encoding', 'content-disposition'];

    /**
     * An address in canonical form (domain lower case and ASCII), or null if invalid.
     */
    public static function normalizeAddress(string $addr): ?string
    {
        $addr = trim($addr);
        $at   = strrpos($addr, '@');
        if ($at === false || $at === 0 || strlen($addr) > 320) {
            return null;
        }
        $local  = substr($addr, 0, $at);
        $domain = SendDomainService::normalize(substr($addr, $at + 1));
        if (strlen($local) > 64 || preg_match('/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/', $local) !== 1
            || str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..') || ! ProjectService::isDomainName($domain)) {
            return null;
        }

        return $local . '@' . $domain;
    }

    /**
     * Parses one mailbox ("Name <a@b>", "a@b", "<a@b>") into [display name, address]; null if it is not exactly one.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseMailbox(string $value): ?array
    {
        $list = self::parseAddressList($value);

        return count($list) === 1 ? $list[0] : null;
    }

    /**
     * Parses an address list header value (comma-separated, quoted names and groups tolerated).
     *
     * @return list<array{0: string, 1: string}> [display name, normalized address]; invalid entries make the result empty
     */
    public static function parseAddressList(string $value): array
    {
        $value = self::decodeHeader($value);
        $parts = [];
        $buf   = '';
        $quote = false;
        $angle = 0;
        $len   = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            if ($c === '\\' && $quote && $i + 1 < $len) {
                $buf .= $c . $value[++$i];

                continue;
            }
            if ($c === '"') {
                $quote = ! $quote;
            } elseif (! $quote && $c === '<') {
                $angle++;
            } elseif (! $quote && $c === '>') {
                $angle = max(0, $angle - 1);
            } elseif (! $quote && $angle === 0 && ($c === ',' || $c === ';')) {
                $parts[] = $buf;
                $buf     = '';

                continue;
            } elseif (! $quote && $angle === 0 && $c === ':') {
                $buf = ''; // group name "team: a@b, c@d;"

                continue;
            }
            $buf .= $c;
        }
        $parts[] = $buf;
        $out     = [];
        foreach ($parts as $p) {
            $p = trim(preg_replace('/\([^()]*\)/', '', $p) ?? $p); // comments
            if ($p === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^<>]*)>$/s', $p, $m) === 1) {
                $name = trim(trim($m[1]), '"');
                $addr = self::normalizeAddress($m[2]);
            } else {
                $name = '';
                $addr = self::normalizeAddress($p);
            }
            if ($addr === null) {
                return [];
            }
            $out[] = [stripslashes($name), $addr];
        }

        return $out;
    }

    /** RFC 2047 encoded words decoded to UTF-8. */
    public static function decodeHeader(string $value): string
    {
        $value = preg_replace('/\r?\n[ \t]+/', ' ', $value) ?? $value;
        if (! str_contains($value, '=?')) {
            return trim($value);
        }
        $decoded = function_exists('iconv_mime_decode') ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;

        return trim($decoded !== false ? $decoded : $value);
    }

    /**
     * Splits a raw message into header fields (unfolded name => list of raw field blocks) and the body.
     *
     * @return array{0: list<array{name: string, raw: string}>, 1: string}
     */
    public static function split(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");
        [$head, $body] = $pos === false ? [$raw, ''] : [substr($raw, 0, $pos), substr($raw, $pos + 4)];
        $fields = [];
        foreach (explode("\r\n", $head) as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $fields !== []) {
                $fields[count($fields) - 1]['raw'] .= "\r\n" . $line;

                continue;
            }
            $colon    = strpos($line, ':');
            $fields[] = ['name' => strtolower(trim($colon === false ? $line : substr($line, 0, $colon))), 'raw' => $line];
        }

        return [$fields, $body];
    }

    /** First value of a header (unfolded, not decoded), or null. */
    public static function header(array $fields, string $name): ?string
    {
        foreach ($fields as $f) {
            if ($f['name'] === $name) {
                $v = substr($f['raw'], strpos($f['raw'], ':') + 1);

                return trim(preg_replace('/\r\n[ \t]+/', ' ', $v) ?? $v);
            }
        }

        return null;
    }

    public static function count(array $fields, string $name): int
    {
        return count(array_filter($fields, static fn ($f) => $f['name'] === $name));
    }

    /**
     * Prepares a raw message for the queue: CRLF line ends, Bcc removed, Date and Message-ID added when missing.
     *
     * @return array{raw: string, fields: list<array{name: string, raw: string}>, message_id: string}
     */
    public static function prepare(string $raw, string $idDomain): array
    {
        $raw = preg_replace("/\r?\n|\r/", "\r\n", $raw) ?? $raw;
        [$fields, $body] = self::split($raw);
        $fields = array_values(array_filter($fields, static fn ($f) => $f['name'] !== 'bcc'));
        if (self::header($fields, 'date') === null) {
            $fields[] = ['name' => 'date', 'raw' => 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000'];
        }
        $mid = self::header($fields, 'message-id');
        if ($mid === null || $mid === '') {
            $mid      = '<' . bin2hex(random_bytes(12)) . '@' . $idDomain . '>';
            $fields[] = ['name' => 'message-id', 'raw' => 'Message-ID: ' . $mid];
        }
        $raw = implode("\r\n", array_column($fields, 'raw')) . "\r\n\r\n" . $body;

        return ['raw' => $raw, 'fields' => $fields, 'message_id' => $mid];
    }

    /**
     * Builds a message from structured input.
     *
     * @param array<string, mixed> $in {from, to?, cc?, reply_to?, subject, text?, html?, headers?, attachments?}
     *
     * @return string raw message (CRLF)
     */
    public static function build(array $in, string $fromName, string $fromAddr, array $to, array $cc): string
    {
        $h   = [];
        $h[] = 'From: ' . self::mailbox($fromName, $fromAddr);
        if ($to !== []) {
            $h[] = self::fold('To: ' . implode(', ', array_map(static fn ($a) => self::mailbox($a[0], $a[1]), $to)));
        }
        if ($cc !== []) {
            $h[] = self::fold('Cc: ' . implode(', ', array_map(static fn ($a) => self::mailbox($a[0], $a[1]), $cc)));
        }
        if (isset($in['reply_to'])) {
            $rt = is_string($in['reply_to']) ? self::parseMailbox($in['reply_to']) : null;
            if ($rt === null) {
                throw new ConfigException('INVALID_MESSAGE', 'reply_to must be one address');
            }
            $h[] = 'Reply-To: ' . self::mailbox($rt[0], $rt[1]);
        }
        $subject = $in['subject'] ?? null;
        if (! is_string($subject) || strlen($subject) > 998 || preg_match('/[\r\n]/', $subject) === 1) {
            throw new ConfigException('INVALID_MESSAGE', 'subject is required (one line, at most 998 bytes)');
        }
        $h[] = self::fold('Subject: ' . self::encodeWord($subject));
        $h[] = 'MIME-Version: 1.0';
        foreach ((array) ($in['headers'] ?? []) as $name => $value) {
            if (! is_string($name) || preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,75}$/', $name) !== 1 || in_array(strtolower($name), self::RESERVED, true)
                || ! is_string($value) || preg_match('/[\r\n]/', $value) === 1 || strlen($value) > 998) {
                throw new ConfigException('INVALID_MESSAGE', 'headers: invalid or reserved header "' . (is_string($name) ? $name : '?') . '"');
            }
            $h[] = self::fold($name . ': ' . self::encodeWord($value));
        }

        $text = $in['text'] ?? null;
        $html = $in['html'] ?? null;
        if (($text !== null && ! is_string($text)) || ($html !== null && ! is_string($html)) || ($text === null && $html === null)) {
            throw new ConfigException('INVALID_MESSAGE', 'text and/or html is required');
        }
        $atts = $in['attachments'] ?? [];
        if (! is_array($atts) || ! array_is_list($atts) || count($atts) > 50) {
            throw new ConfigException('INVALID_MESSAGE', 'attachments must be a list (at most 50)');
        }
        $inline = [];
        $files  = [];
        foreach ($atts as $a) {
            $part = self::attachment($a);
            if (($a['inline'] ?? false) === true) {
                $inline[] = $part;
            } else {
                $files[] = $part;
            }
        }

        $alt = [];
        if ($text !== null) {
            $alt[] = self::textPart('text/plain', $text);
        }
        if ($html !== null) {
            $htmlPart = self::textPart('text/html', $html);
            $alt[]    = $inline !== [] ? self::multipart('related', [$htmlPart, ...$inline]) : $htmlPart;
        }
        $content = count($alt) === 1 ? $alt[0] : self::multipart('alternative', $alt);
        if ($files !== []) {
            $content = self::multipart('mixed', [$content, ...$files]);
        }

        return implode("\r\n", $h) . "\r\n" . $content;
    }

    /** Header field with display name encoded as needed. */
    public static function mailbox(string $name, string $addr): string
    {
        if ($name === '') {
            return $addr;
        }
        $enc = preg_match('/[^\x20-\x7e]/', $name) === 1 ? self::encodeWord($name) : '"' . addcslashes($name, '"\\') . '"';

        return $enc . ' <' . $addr . '>';
    }

    public static function encodeWord(string $text): string
    {
        if (preg_match('/[^\x20-\x7e]/', $text) !== 1) {
            return $text;
        }
        $out = [];
        foreach (mb_str_split($text, 15, 'UTF-8') as $chunk) {
            $out[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $out);
    }

    /** Folds an ASCII header at spaces to lines of at most 78 characters where possible. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 78 || str_contains($line, "\r\n")) {
            return $line;
        }
        $out = '';
        $cur = '';
        foreach (explode(' ', $line) as $i => $word) {
            if ($i > 0 && strlen($cur) + 1 + strlen($word) > 78) {
                $out .= $cur . "\r\n";
                $cur  = ' ' . $word;
            } else {
                $cur .= ($i > 0 ? ' ' : '') . $word;
            }
        }

        return $out . $cur;
    }

    private static function textPart(string $type, string $content): string
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new ConfigException('INVALID_MESSAGE', 'text and html must be UTF-8');
        }
        $content = preg_replace("/\r?\n|\r/", "\r\n", $content) ?? $content;

        return 'Content-Type: ' . $type . '; charset=utf-8' . "\r\n" . 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n"
            . quoted_printable_encode($content) . "\r\n";
    }

    /** @param array<string, mixed>|mixed $a */
    private static function attachment(mixed $a): string
    {
        if (! is_array($a) || ! is_string($a['filename'] ?? null) || ! is_string($a['content'] ?? null)) {
            throw new ConfigException('INVALID_MESSAGE', 'every attachment needs filename and content (base64)');
        }
        $data = base64_decode($a['content'], true);
        if ($data === false) {
            throw new ConfigException('INVALID_MESSAGE', 'attachment "' . $a['filename'] . '": content is not base64');
        }
        $name = str_replace(['"', "\r", "\n", '\\'], '', $a['filename']);
        $type = is_string($a['content_type'] ?? null) && preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $a['content_type']) === 1 ? strtolower($a['content_type']) : 'application/octet-stream';
        $inline = ($a['inline'] ?? false) === true;
        $cid    = is_string($a['content_id'] ?? null) ? preg_replace('/[^A-Za-z0-9.@_-]/', '', $a['content_id']) : '';
        if ($inline && $cid === '') {
            throw new ConfigException('INVALID_MESSAGE', 'inline attachment "' . $name . '" needs content_id');
        }
        $encName = preg_match('/[^\x20-\x7e]/', $name) === 1 ? "filename*=UTF-8''" . rawurlencode($name) : 'filename="' . $name . '"';

        return 'Content-Type: ' . $type . "\r\n" . 'Content-Transfer-Encoding: base64' . "\r\n"
            . 'Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; ' . $encName . "\r\n"
            . ($inline ? 'Content-ID: <' . $cid . ">\r\n" : '')
            . "\r\n" . rtrim(chunk_split(base64_encode($data), 76, "\r\n")) . "\r\n";
    }

    /** @param list<string> $parts */
    private static function multipart(string $subtype, array $parts): string
    {
        $b = '=_mr' . bin2hex(random_bytes(12));

        return 'Content-Type: multipart/' . $subtype . '; boundary="' . $b . '"' . "\r\n\r\n"
            . implode('', array_map(static fn ($p) => '--' . $b . "\r\n" . $p, $parts)) . '--' . $b . "--\r\n";
    }
}
