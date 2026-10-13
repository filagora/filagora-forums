<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

use Filagora\Forums\Contracts\PostSignatureResolver;
use Illuminate\Contracts\Auth\Authenticatable;

final class NoPostSignature implements PostSignatureResolver
{
    public function signatureFor(Authenticatable $user): ?string
    {
        return null;
    }
}
