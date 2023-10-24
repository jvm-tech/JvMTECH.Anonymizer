<?php
namespace JvMTECH\Anonymizer\Domain\Repository;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;

/**
 * @Flow\Scope("singleton")
 */
class AnonymizationStatusRepository extends Repository
{
    public function findLastOneByName(string $name)
    {
        $query = $this->createQuery();

        $query->matching($query->equals('name', $name));
        $query->setOrderings(['toDateTime' => \Neos\Flow\Persistence\QueryInterface::ORDER_DESCENDING]);
        $query->setLimit(1);

        return $query->execute()->getFirst();
    }
}
