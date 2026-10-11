<?php

declare(strict_types=1);

namespace Automations\Support;

use RuntimeException;

/** A step that could not be carried out; the message is safe to show to the server's users. */
final class StepFailed extends RuntimeException {}
