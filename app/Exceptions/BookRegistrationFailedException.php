<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class BookRegistrationFailedException extends RuntimeException
{
    /**
     * 登録失敗の原因を保持する。
     */
    public function __construct(Throwable $previous)
    {
        parent::__construct('登録に失敗しました。', 0, $previous);
    }
}
