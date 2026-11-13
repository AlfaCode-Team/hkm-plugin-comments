<?php

declare(strict_types=1);

namespace Plugins\Comments\Application\Spam;

/**
 * FormToken — a signed "this form was rendered at T" token.
 *
 * Not a CSRF token (the kernel's CsrfTokenLayer already covers that if the
 * project wires it): this one exists purely to measure elapsed fill time for
 * the minimum-seconds / token-age spam checks. The payload is a plain UNIX
 * timestamp; the signature is an HMAC-SHA256 over that payload, so a client
 * cannot forge an older or newer `formIssuedAt` without the secret.
 *
 * `hash_equals()` for the comparison — never `===` on anything derived from a
 * secret.
 */
final readonly class FormToken
{
    public function __construct(
        private string $secret,
    ) {
    }

    public function mint(int $issuedAt): string
    {
        return $issuedAt . '.' . $this->sign((string) $issuedAt);
    }

    /**
     * The issued-at timestamp, or null when the token is malformed or its
     * signature does not match (tampered, forged, or signed with a different
     * secret).
     */
    public function verify(string $token): ?int
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return null;
        }

        [$payload, $signature] = $parts;

        return hash_equals($this->sign($payload), $signature) ? (int) $payload : null;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
