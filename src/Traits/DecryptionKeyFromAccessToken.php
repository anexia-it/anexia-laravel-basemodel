<?php

namespace Anexia\BaseModel\Traits;

use Anexia\BaseModel\Token;
use Lcobucci\JWT\Parser;

trait DecryptionKeyFromAccessToken
{
    /**
     * Return the plaintext decryption key of currently authenticated user
     *
     * @return string
     */
    protected function getDecryptionKeyFromAccessToken()
    {
        // get the access token from the 'Authorization' header
        $bearerToken = request()->bearerToken();
        // get the decryption token from the 'X-Encryption' header
        $decryptionToken = request()->header('X-Encryption');

        if ($bearerToken && $decryptionToken) {
            // extract the token id from the bearer request parameter
            $jwt = trim(preg_replace('/^(?:\s+)?Bearer\s/', '', $bearerToken));
            $jwtToken = (new Parser())->parse($jwt);
            $tokenId = $jwtToken->getClaim('jti');
            $token = Token::find($tokenId);

            // decrypt the decryption key from the access token
            return $token->getDecryptionKey($decryptionToken);
        }

        return null;
    }
}