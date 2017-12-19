<?php

namespace Anexia\BaseModel;

use Anexia\BaseModel\Traits\OpenSSLEncryption;
use Laravel\Passport\Token as PassportToken;

class Token extends PassportToken
{
    use OpenSSLEncryption;

    public function __construct(array $attributes = [])
    {
        $this->fillable[] = 'decryption_key';
        $this->casts['decryption_key'] = 'string';

        parent::__construct($attributes);
    }

    /**
     * @param string $decryptionKey
     * @param string $key
     */
    public function setDecryptionKey($decryptionKey)
    {
        $this->decryption_key = $decryptionKey;
    }

    /**
     * If $key is given, return the decrypted decryption key (= user's plaintext decryption key),
     * otherwise return the encrypted decryption key
     *
     * @param string|null $key
     * @return string
     */
    public function getDecryptionKey($key = null)
    {
        if ($key !== null) {
            return $this->openSslDecrypt($this->decryption_key, $key);
        }

        return $this->decryption_key;
    }
}