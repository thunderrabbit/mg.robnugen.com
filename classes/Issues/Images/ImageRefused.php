<?php

declare(strict_types=1);

namespace Issues\Images;

/**
 * A file that may not be stored as an issue picture. The message is the
 * reason, worded for the person who uploaded it.
 */
final class ImageRefused extends \RuntimeException
{
}
