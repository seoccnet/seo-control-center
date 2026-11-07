<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support;

use SeoControlCenter\Domain\Analytics\Ga4Credentials;

final class Ga4Fixture
{
    private static ?Ga4Credentials $credential = null;
    public static function credentials(): Ga4Credentials
    {
        if (self::$credential !== null) {
            return self::$credential;
        }
        $options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => __DIR__ . '/openssl-test.cnf'];
        $key = openssl_pkey_new($options);
        if ($key === false || !openssl_pkey_export($key, $pem, null, $options)) {
            throw new \RuntimeException('Could not create an in-memory test credential.');
        }
        return self::$credential = new Ga4Credentials('scc@test.iam.gserviceaccount.com', $pem);
    }
    public static function json(): string
    {
        $credential = self::credentials();
        return json_encode(['type' => 'service_account', 'client_email' => $credential->email, 'private_key' => $credential->privateKey], JSON_THROW_ON_ERROR);
    }
}
