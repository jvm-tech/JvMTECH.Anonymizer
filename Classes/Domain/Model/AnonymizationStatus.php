<?php
namespace JvMTECH\Anonymizer\Domain\Model;

use Neos\Flow\Annotations as Flow;
use Doctrine\ORM\Mapping as ORM;

/**
 * @Flow\Entity
 */
class AnonymizationStatus
{
    /**
     * @var int
     *
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer")
     */
    protected int $id;

    /**
     * @var string
     */
    protected string $name;

    /**
     * @var \DateTime
     * @ORM\Column(nullable=true)
     */
    protected \DateTime $fromDateTime;

    /**
     * @var \DateTime
     * @ORM\Column(nullable=false)
     */
    protected \DateTime $toDateTime;

    /**
     * @var \DateTime
     * @ORM\Column(nullable=false)
     */
    protected \DateTime $executedDateTime;

    /**
     * @var int
     */
    protected int $anonymizedRecords;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getFromDateTime(): \DateTime
    {
        return $this->fromDateTime;
    }

    public function setFromDateTime(\DateTime $fromDateTime): void
    {
        $this->fromDateTime = $fromDateTime;
    }

    public function getToDateTime(): \DateTime
    {
        return $this->toDateTime;
    }

    public function setToDateTime(\DateTime $toDateTime): void
    {
        $this->toDateTime = $toDateTime;
    }

    public function getExecutedDateTime(): \DateTime
    {
        return $this->executedDateTime;
    }

    public function setExecutedDateTime(\DateTime $executedDateTime): void
    {
        $this->executedDateTime = $executedDateTime;
    }

    public function getAnonymizedRecords(): int
    {
        return $this->anonymizedRecords;
    }

    public function setAnonymizedRecords(int $anonymizedRecords): void
    {
        $this->anonymizedRecords = $anonymizedRecords;
    }

}
