<?php

namespace Anexia\BaseModel\Traits;

trait OpenSSLEncryption
{
    /**
     * @param string $value
     * @param string $key
     * @return string
     */
    protected function openSslEncrypt($value, $key)
    {
        $algo = config('app.database_encryption_algo');
        $iv = random_bytes(openssl_cipher_iv_length($algo));
        // encrypt the decryption key with user's password
        $encrypted = openssl_encrypt($value, $algo, $key, 0, $iv);
        return $encrypted . ':' . base64_encode($iv);
    }

    /**
     * @return string
     */
    protected function openSslDecrypt($encrypted, $key)
    {
        $algo = config('app.database_encryption_algo');
        $encryptedParts = explode(':', $encrypted);
        return openssl_decrypt($encryptedParts[0], $algo, $key, 0, base64_decode($encryptedParts[1]));
    }
}