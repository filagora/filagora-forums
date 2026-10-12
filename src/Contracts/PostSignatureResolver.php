<?php

declare(strict_types=1);

namespace ForumKit\Forums\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The signature copied onto each new post (the post keeps its own copy, so changing a signature
 * later does not rewrite old posts). Markdown. The default is none.
 */
interface PostSignatureResolver
{
    public function signatureFor(Authenticatable $user): ?string;
}
