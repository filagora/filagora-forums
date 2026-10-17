<?php

declare(strict_types=1);

namespace Filagora\Forums\Enums;

/**
 * Why an attachment was refused.
 */
enum AttachmentRefusal: string
{
    case RATE_LIMITED = 'rate_limited';
    case UPLOAD_FAILED = 'upload_failed';
    case NOT_ALLOWED = 'not_allowed';
    case FILE_MISSING = 'file_missing';

    public function message(): string
    {
        return __("filagora-forums::attachments.refusals.{$this->value}");
    }
}
