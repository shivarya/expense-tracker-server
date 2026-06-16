<?php

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * GmailFetcher
 *
 * Thin helpers over the Gmail API for the server-side fetch worker: list
 * matching messages, read subject/body, download PDF attachments, and pull a
 * likely PDF password hint out of the email text.
 */
class GmailFetcher
{
    /** Return message IDs matching a Gmail search query (newest first). */
    public static function listMessageIds(\Google\Client $client, string $query, int $max = 25): array
    {
        $service = new \Google\Service\Gmail($client);
        $resp = $service->users_messages->listUsersMessages('me', [
            'q' => $query,
            'maxResults' => $max,
        ]);

        $ids = [];
        foreach (($resp->getMessages() ?? []) as $m) {
            $ids[] = $m->getId();
        }
        return $ids;
    }

    public static function getMessage(\Google\Client $client, string $messageId): \Google\Service\Gmail\Message
    {
        $service = new \Google\Service\Gmail($client);
        return $service->users_messages->get('me', $messageId, ['format' => 'full']);
    }

    public static function getHeader(\Google\Service\Gmail\Message $message, string $name): string
    {
        foreach ($message->getPayload()->getHeaders() as $header) {
            if (strcasecmp($header->getName(), $name) === 0) {
                return (string)$header->getValue();
            }
        }
        return '';
    }

    /** Decode the plaintext-ish body (top-level + parts) for password hints. */
    public static function getPlainText(\Google\Service\Gmail\Message $message): string
    {
        $payload = $message->getPayload();
        $body = '';

        if ($payload->getBody() && $payload->getBody()->getData()) {
            $body .= self::decodeBase64Url($payload->getBody()->getData());
        }

        foreach (($payload->getParts() ?? []) as $part) {
            $mime = (string)$part->getMimeType();
            if (str_starts_with($mime, 'text/') && $part->getBody() && $part->getBody()->getData()) {
                $body .= "\n" . self::decodeBase64Url($part->getBody()->getData());
            }
        }

        return $body;
    }

    /**
     * Download all PDF attachments of a message.
     * @return array<int, array{filename: string, bytes: string}>
     */
    public static function downloadPdfAttachments(\Google\Client $client, string $messageId, \Google\Service\Gmail\Message $message): array
    {
        $service = new \Google\Service\Gmail($client);
        $out = [];

        foreach (self::collectAttachmentParts($message->getPayload()) as $part) {
            $filename = (string)$part->getFilename();
            if ($filename === '' || stripos($filename, '.pdf') === false) {
                continue;
            }

            $attachmentId = $part->getBody() ? $part->getBody()->getAttachmentId() : null;
            if (!$attachmentId) {
                continue;
            }

            $attachment = $service->users_messages_attachments->get('me', $messageId, $attachmentId);
            $bytes = self::decodeBase64Url((string)$attachment->getData());
            if ($bytes !== '') {
                $out[] = ['filename' => $filename, 'bytes' => $bytes];
            }
        }

        return $out;
    }

    /** Flatten nested multipart parts. */
    private static function collectAttachmentParts($part): array
    {
        $result = [];
        if (!$part) {
            return $result;
        }

        $result[] = $part;
        foreach (($part->getParts() ?? []) as $child) {
            $result = array_merge($result, self::collectAttachmentParts($child));
        }
        return $result;
    }

    /** Best-effort password hint from email body/subject (CAMS/CDSL/NPS patterns). */
    public static function extractPasswordHint(string $body, string $subject): ?string
    {
        $patterns = [
            '/password\s*(?:is|:)\s*([A-Za-z0-9@._-]{4,})/i',
            '/pwd\s*(?:is|:)\s*([A-Za-z0-9@._-]{4,})/i',
            '/protected with\s*(?:the password)?\s*([A-Za-z0-9@._-]{4,})/i',
            '/open(?:ed)? (?:it )?with\s*([A-Za-z0-9@._-]{4,})/i',
        ];

        foreach ([$body, $subject] as $haystack) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $haystack, $m) && isset($m[1])) {
                    return trim($m[1]);
                }
            }
        }

        return null;
    }

    public static function decodeBase64Url(string $input): string
    {
        return (string)base64_decode(strtr($input, '-_', '+/'));
    }
}
