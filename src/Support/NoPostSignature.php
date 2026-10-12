<?php

declare(strict_types=1);

namespace ForumKit\Forums\Support;

use ForumKit\Forums\Contracts\PostSignatureResolver;
use Illuminate\Contracts\Auth\Authenticatable;

final class NoPostSignature implements PostSignatureResolver
{
    public function signatureFor(Authenticatable $user): ?string
    {
        return null;
    }
}
