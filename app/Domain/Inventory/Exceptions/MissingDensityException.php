<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

final class MissingDensityException extends RuntimeException
{
    public function __construct(string $message = 'ไม่สามารถแปลงหน่วยข้ามมิติได้ เนื่องจากไม่มีค่าความหนาแน่นของสารนี้')
    {
        parent::__construct($message);
    }
}
