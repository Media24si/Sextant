<?php

namespace App\Detection;

use InvalidArgumentException;

/** The input can't be a site URL; the message says why, for the admin. */
final class InvalidUrl extends InvalidArgumentException {}
