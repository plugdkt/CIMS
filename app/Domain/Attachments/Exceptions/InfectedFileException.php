<?php

declare(strict_types=1);

namespace App\Domain\Attachments\Exceptions;

use RuntimeException;

final class InfectedFileException extends RuntimeException
{
    public function __construct(string $message = 'ไฟล์นี้ตรวจพบไวรัส ระบบปฏิเสธการอัปโหลด')
    {
        parent::__construct($message);
    }
}
