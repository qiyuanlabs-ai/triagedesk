<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Another worker resumed the same paused run first.
 */
class RunAlreadyClaimed extends RuntimeException {}
