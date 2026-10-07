<?php

declare(strict_types=1);

namespace Veylora\SitePilotConnector\Security;

final class NonceGenerator
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
