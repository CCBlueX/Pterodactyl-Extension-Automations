<?php

declare(strict_types=1);

namespace Automations\Support;

use InvalidArgumentException;

/** A step that cannot be saved; Steps adds where it is. */
final class StepProblem extends InvalidArgumentException {}
