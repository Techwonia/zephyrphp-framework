<?php

declare(strict_types=1);

namespace ZephyrPHP\Module;

use Exception;

/**
 * Thrown when there's a module dependency conflict
 */
class ModuleDependencyException extends Exception
{
}
