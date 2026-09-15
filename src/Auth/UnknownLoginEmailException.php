<?php
declare(strict_types=1);
namespace CattoLearning\Auth;

use RuntimeException;

final class UnknownLoginEmailException extends RuntimeException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct('This email is not registered. Please complete registration first.');
    }
}
