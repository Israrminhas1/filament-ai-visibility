<?php

namespace IsrarMinhas\FilamentAiVisibility\Exceptions;

use RuntimeException;

/**
 * A run was refused before anything was spent; the message says why.
 */
class RunNotStarted extends RuntimeException {}
