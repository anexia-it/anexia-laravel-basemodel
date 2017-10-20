<?php

namespace Anexia\BaseModel\Exceptions\Query;

use Exception;
use Illuminate\Support\Facades\Lang;
use Throwable;

class SqlException extends Exception
{
    /**
     * SqlException constructor.
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct($message = '', $code = 400, Throwable $previous = null)
    {
        if (empty($message)) {
            $message = Lang::get('extended_model.errors.foreign_key_failure');
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * @param string $message
     */
    public function setMessage($message = '')
    {
        $this->message = $message;
    }

    /**
     * @param string|int $code
     */
    public function setMessageBySqlCode($code)
    {
        switch ($code) {
            case '23502':
                $this->message = Lang::get('extended_model.errors.not_null_condition_failure');
                break;
            case '23505':
                $this->message = Lang::get('extended_model.errors.foreign_key_failure');
                break;
            default:
                $this->message = Lang::get('extended_model.errors.sql_error');
                break;
        }
    }
}