<?php

declare(strict_types=1);

namespace ZephyrPHP\Module;

use Exception;

/**
 * Thrown when trying to use a module that is not enabled
 */
class ModuleNotEnabledException extends Exception
{
}
