<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;

/**
 * A host SQL filter on the ledger's tenant scope (`scope`, or the list `scopes`), for the private entity manager's
 * filter mirroring tests.
 */
final class LedgerScopeFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getName() !== BalanceLedgerLine::class) {
            return '';
        }

        return $this->hasParameter('scopes')
            ? $targetTableAlias . '.scope_key IN (' . $this->getParameterList('scopes') . ')'
            : $targetTableAlias . '.scope_key = ' . $this->getParameter('scope');
    }
}
