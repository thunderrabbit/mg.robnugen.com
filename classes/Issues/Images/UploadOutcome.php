<?php

declare(strict_types=1);

namespace Issues\Images;

/**
 * What became of one uploaded file. The value is the word shown for it.
 */
enum UploadOutcome: string
{
    case Stored = 'stored';
    case AlreadyThere = 'already there';
    case LinkedAgain = 'linked again';
    case Refused = 'refused';
}
