<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Exception thrown when Twitter authentication fails.
 */
final class TwitterAuthException extends RuntimeException
{
} 