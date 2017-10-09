<?php

namespace Anexia\BaseModel\Exceptions\Validation;

use Exception;

class BulkValidationException extends Exception
{
    /** array $messages */
    protected $messages = [];

    /**
     * BulkValidationException constructor.
     * BulkValidationException constructor.
     * @param array $messages
     * @param int $code
     */
    public function __construct($messages = [], $code = 400) {
        parent::__construct('Error in bulk validation', $code);

        $this->messages = $messages;
    }

    public function getMessages()
    {
        return $this->messages;
    }
}