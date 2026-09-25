<?php

namespace IsrarMinhas\FilamentAiVisibility\Exceptions;

use RuntimeException;

/**
 * A helper AI call could not be made or did not produce usable output; the message says why.
 */
class HelperUnavailable extends RuntimeException {}
