<?php

declare(strict_types=1);

namespace KidsNotices\AI;

use KidsNotices\AI\Privacy\Pseudonymizer;

/**
 * Who is calling and for what: the authenticated client (platform), the school,
 * the feature, the hashed end user, and the active pseudonymizer if any.
 */
final class RequestContext
{
    public function __construct(
        public readonly int $clientId,
        public readonly string $schoolId,
        public readonly string $feature,
        public readonly ?string $endUser,
        public readonly ?Pseudonymizer $privacy,
    ) {
    }
}
