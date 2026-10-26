<?php

declare(strict_types=1);

namespace Orion\Testing;

use Orion\Tenancy\NoTenant;
use Orion\Tenancy\TenantResolver;
use Orion\Tenancy\TenantScope;

/**
 * A tenant resolver a test switches by hand, as a request switches organizations; with no tenant set it fails, as a
 * request without one must.
 */
final class SettableResolver implements TenantResolver
{
    public function __construct(public ?string $tenant = null)
    {
    }

    public function resolve(): TenantScope
    {
        return new TenantScope($this->tenant ?? throw new NoTenant('No tenant in this context.'));
    }
}
