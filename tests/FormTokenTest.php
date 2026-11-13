<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments;

use PHPUnit\Framework\TestCase;
use Plugins\Comments\Application\Spam\FormToken;

final class FormTokenTest extends TestCase
{
    public function test_a_minted_token_verifies_back_to_the_same_timestamp(): void
    {
        $token = new FormToken('secret');

        $minted = $token->mint(1_700_000_000);

        self::assertSame(1_700_000_000, $token->verify($minted));
    }

    public function test_a_tampered_payload_fails_verification(): void
    {
        $token  = new FormToken('secret');
        $minted = $token->mint(1_700_000_000);

        [$payload, $signature] = explode('.', $minted, 2);
        $tampered               = ((int) $payload + 1) . '.' . $signature;

        self::assertNull($token->verify($tampered));
    }

    public function test_a_token_signed_with_a_different_secret_fails(): void
    {
        $minted = (new FormToken('secret-a'))->mint(1_700_000_000);

        self::assertNull((new FormToken('secret-b'))->verify($minted));
    }

    public function test_malformed_tokens_fail_closed(): void
    {
        $token = new FormToken('secret');

        self::assertNull($token->verify(''));
        self::assertNull($token->verify('not-a-token'));
        self::assertNull($token->verify('abc.def'));
        self::assertNull($token->verify('123'));
    }
}
