<?php

namespace JvMTECH\Anonymizer\Command;

use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\PropertyValueCriteriaParser;
use Neos\ContentRepository\Core\Service\WorkspaceMaintenanceServiceFactory;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use JvMTECH\Anonymizer\Domain\Model\AnonymizationStatus;
use JvMTECH\Anonymizer\Domain\Repository\AnonymizationStatusRepository;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\Exception\InvalidQueryException;
use Neos\Flow\Persistence\RepositoryInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Flow\ResourceManagement\Exception;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Utility\Exception\PropertyNotAccessibleException;

/**
 * @Flow\Scope("singleton")
 */
class AnonymizeCommandController extends CommandController
{
    #[Flow\InjectConfiguration]
    protected array $settings;
    #[Flow\Inject]
    protected AssetRepository $assetRepository;
    #[Flow\Inject]
    protected ResourceManager $resourceManager;
    #[Flow\Inject]
    protected PersistenceManager $persistenceManager;
    #[Flow\Inject]
    protected ReflectionService $reflectionService;
    #[Flow\Inject]
    protected AnonymizationStatusRepository $anonymizationStatusRepository;
    #[Flow\Inject]
    protected SiteRepository $siteRepository;
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Anonymize configured NodeTypes
     *
     * Create a configuration and run this command to anonymize or shuffle and immediately persist properties of NodeTypes.
     *
     * @param string $only If set, only these NodeType names are processed - comma separated
     * @param bool $test If set, no changes will be persisted and output will be verbose
     * @param bool $verbose If set, output will be verbose
     * @param bool $force If set, changes will be persisted
     * @return void
     * @throws Exception
     * @throws IllegalObjectTypeException
     * @throws InvalidQueryException
     */
    public function nodeTypesCommand(string $only = '', bool $test = false, bool $verbose = false, bool $force = false, bool $rebaseWorkspaces = false): void
    {
        if (!array_key_exists('nodeTypes', $this->settings)) {
            $this->outputLine('No NodeTypes configured in Settings.yaml');
            return;
        }

        if (!$test && !$force) {
            $this->outputLine('Are you sure you want to anonymize or shuffle the configured NodeTypes and immediately persist the changes in the current database?!');
            $this->outputLine('Then use the --force option.');
            return;
        }

        if ($only) {
            $only = explode(',', $only);
            $only = array_map('trim', $only);
        } else {
            $only = [];
        }

        $site = $this->siteRepository->findFirstOnline();
        assert($site instanceof Site);

        $contentRepository = $this->contentRepositoryRegistry->get($site->getConfiguration()->contentRepositoryId);
        $liveWorkspace = $contentRepository->findWorkspaceByName(WorkspaceName::forLive());

        $rootNode = $contentRepository
            ->getContentGraph($liveWorkspace->workspaceName)
            ->findRootNodeAggregateByType(NodeTypeNameFactory::forSites());

        $count = 0;
        foreach ($this->settings['nodeTypes'] as $nodeTypeName => $nodeTypeSettings) {
            if (count($only) > 0 && !in_array($nodeTypeName, $only)) {
                continue;
            }

            $this->outputLine('');
            $this->outputLine('Process ' . $nodeTypeName . ' NodeType:');

            /** @var ?AnonymizationStatus $lastAnonymizationStatus */
            $lastAnonymizationStatus = $this->anonymizationStatusRepository->findLastOneByName('nodeType::' . $nodeTypeName);
            $lastAnonymizationDateTime = $lastAnonymizationStatus?->getToDateTime();
            $olderThanDateTime = null;

            $propertyValueCriteria = null;

            if (isset($nodeTypeSettings['dateTimeFilter']['propertyName']) && isset($nodeTypeSettings['dateTimeFilter']['olderThan'])) {
                $dateTimePropertyName = $nodeTypeSettings['dateTimeFilter']['propertyName'];
                if (is_numeric($nodeTypeSettings['dateTimeFilter']['olderThan'])) {
                    $olderThanDateTime = new \DateTime('now');
                    $olderThanDateTime->setTime(0, 0, 0, 0);
                    $olderThanDateTime->modify((string)$nodeTypeSettings['dateTimeFilter']['olderThan'] . ' days');
                } else {
                    $olderThanDateTime = new \DateTime($nodeTypeSettings['dateTimeFilter']['olderThan']);
                }
                if ($lastAnonymizationDateTime) {
                    $propertyValueCriteria = PropertyValueCriteriaParser::parse(
                        sprintf(
                            '%s >= %s AND %s < %s',
                            $dateTimePropertyName,
                            $lastAnonymizationDateTime->format('Y-m-d H:i:s'),
                            $dateTimePropertyName,
                            $olderThanDateTime->format('Y-m-d H:i:s')
                        )
                    );
                } else {
                    $propertyValueCriteria = PropertyValueCriteriaParser::parse(
                        sprintf(
                            '%s < %s',
                            $dateTimePropertyName,
                            $olderThanDateTime->format('Y-m-d H:i:s')
                        )
                    );
                }
            }

            foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
                $subgraph = $contentRepository
                    ->getContentGraph($liveWorkspace->workspaceName)
                    ->getSubgraph(
                        $dimensionSpacePoint,
                        NeosVisibilityConstraints::excludeRemoved(),
                    );
                $siteNode = $subgraph->findNodeByPath($site->getNodeName()->toNodeName(), $rootNode->nodeAggregateId);
                if ($siteNode === null) {
                    continue;
                }
                $nodes = $subgraph->findDescendantNodes(
                    $siteNode->aggregateId,
                    FindDescendantNodesFilter::create(
                        NodeTypeCriteria::createWithAllowedNodeTypeNames(NodeTypeNames::fromStringArray([$nodeTypeName])),
                        $propertyValueCriteria,
                    )
                );

                foreach ($nodes as $node) {
                    if ($test || $verbose) {
                        $this->outputLine($node->aggregateId->value . ':');
                    }
                    foreach ($nodeTypeSettings['properties'] as $propertyName => $propertySettings) {
                        $oldValue = $node->getProperty($propertyName);
                        $newValue = $this->processPropertyValue($propertyName, $propertySettings, $oldValue, $test, $verbose);
                        if ($test === false && $newValue) {
                            $contentRepository->handle(
                                SetNodeProperties::create(
                                    $liveWorkspace->workspaceName,
                                    $node->aggregateId,
                                    $node->originDimensionSpacePoint,
                                    PropertyValuesToWrite::fromArray(
                                        [$propertyName => $newValue],
                                    )
                                )
                            );
                            $count++;
                        }
                    }
                }
            }

            if (!$test) {
                $anonymizationStatus = new AnonymizationStatus();
                $anonymizationStatus->setName('nodeType::' . $nodeTypeName);
                if ($lastAnonymizationDateTime) {
                    $anonymizationStatus->setFromDateTime($lastAnonymizationDateTime);
                }
                $anonymizationStatus->setToDateTime($olderThanDateTime ?: new \DateTime());
                $anonymizationStatus->setExecutedDateTime(new \DateTime());
                $anonymizationStatus->setAnonymizedRecords($count);

                $this->anonymizationStatusRepository->add($anonymizationStatus);
            }

            if (!$test && $rebaseWorkspaces) {
                $workspaceMaintenanceService = $this->contentRepositoryRegistry->buildService(
                        $contentRepository->id,
                        new WorkspaceMaintenanceServiceFactory()
                );
                $workspaceMaintenanceService->rebaseOutdatedWorkspaces(
                    $force ? RebaseErrorHandlingStrategy::STRATEGY_FORCE : RebaseErrorHandlingStrategy::STRATEGY_FAIL
                );
            }
        }
    }

    /**
     * Anonymize configured Domain Models
     *
     * Create a configuration and run this command to anonymize or shuffle and immediately persist properties of Domain Models.
     *
     * @param string $only If set, only these repository class names are processed - comma separated
     * @param bool $test If set, no changes will be persisted and output will be verbose
     * @param bool $verbose If set, output will be verbose
     * @param bool $force If set, changes will be persisted
     * @return void
     * @throws Exception
     * @throws IllegalObjectTypeException
     * @throws PropertyNotAccessibleException
     */
    public function domainModelsCommand(string $only = '', bool $test = false, bool $verbose = false, bool $force = false): void
    {
        if (!array_key_exists('domainModels', $this->settings)) {
            $this->outputLine('No Domain Models configured in Settings.yaml');
            return;
        }

        if (!$test && !$force) {
            $this->outputLine('Are you sure you want to anonymize or shuffle the configured Domain Models and immediately persist the changes in the current database?!');
            $this->outputLine('Then use the --force option.');
            return;
        }

        if ($only) {
            $only = explode(',', $only);
            $only = array_map('trim', $only);
        } else {
            $only = [];
        }

        foreach ($this->settings['domainModels'] as $repositoryClass => $modelSettings) {
            if (count($only) > 0 && !in_array($repositoryClass, $only)) {
                continue;
            }

            $repository = $this->objectManager->get($repositoryClass);
            if (!$repository || !$this->reflectionService->isClassImplementationOf($repository::class,
                    RepositoryInterface::class)) {
                $this->outputLine($repositoryClass . ' is not a valid Domain Model.');
                continue;
            }

            /** @var ?AnonymizationStatus $lastAnonymizationStatus */
            $lastAnonymizationStatus = $this->anonymizationStatusRepository->findLastOneByName('domainModel::' . $repositoryClass);
            $lastAnonymizationDateTime = $lastAnonymizationStatus?->getToDateTime();
            $olderThanDateTime = null;

            $query = $repository->createQuery();
            $queries = [];

            $this->outputLine('');
            $this->outputLine('Process ' . $repositoryClass . ' Domain Model:');

            if (isset($modelSettings['dateTimeFilter']['propertyName']) && isset($modelSettings['dateTimeFilter']['olderThan'])) {
                $dateTimePropertyName = $modelSettings['dateTimeFilter']['propertyName'];
                if (is_numeric($modelSettings['dateTimeFilter']['olderThan'])) {
                    $olderThanDateTime = new \DateTime('now');
                    $olderThanDateTime->setTime(0, 0, 0, 0);
                    $olderThanDateTime->modify((string)$modelSettings['dateTimeFilter']['olderThan'] . ' days');
                } else {
                    $olderThanDateTime = new \DateTime($modelSettings['dateTimeFilter']['olderThan']);
                }

                $this->outputLine('- Filter by DateTime Property "' . $dateTimePropertyName . '" older than "' . $olderThanDateTime->format('Y-m-d H:i:s') . '"' . ($lastAnonymizationDateTime ? ' but newer than "' . $lastAnonymizationDateTime->format('Y-m-d H:i:s') . '"' : '') . '..');

                if ($lastAnonymizationDateTime) {
                    $queries[] = $query->greaterThanOrEqual($dateTimePropertyName, $lastAnonymizationDateTime);
                }

                $queries[] = $query->lessThan($dateTimePropertyName, $olderThanDateTime);
            }

            if ($queries) {
                $query->matching($query->logicalAnd($queries));
                $result = $query->execute();
            } else {
                $result = $repository->findAll();
            }

            $this->outputLine('- ' . $result->count() . ' entries to anonymize..');

            $modelProperties = $result->count() > 0 ? $this->reflectionService->getClassPropertyNames($result->getFirst()::class) : null;

            foreach ($result as $entry) {
                if ($test || $verbose) {
                    $this->outputLine('- ' . $this->persistenceManager->getIdentifierByObject($entry) . ':');
                }
                foreach ($modelSettings['properties'] as $propertyName => $propertySettings) {
                    if (!in_array($propertyName, $modelProperties)) {
                        $this->outputLine('- Property ' . $propertyName . ' not found in Domain Model ' . $repositoryClass . '.');
                        continue;
                    }

                    $oldValue = $entry->{'get' . ucfirst($propertyName)}();
                    $newValue = $this->processPropertyValue($propertyName, $propertySettings, $oldValue, $test, $verbose);

                    if ($test === false && $newValue) {
                        $entry->{'set' . ucfirst($propertyName)}($newValue);
                    }
                }

                $repository->update($entry);
            }

            $this->outputLine('Done.');
            $this->outputLine('');

            if (!$test) {
                $anonymizationStatus = new AnonymizationStatus();
                $anonymizationStatus->setName('domainModel::' . $repositoryClass);
                if ($lastAnonymizationDateTime) {
                    $anonymizationStatus->setFromDateTime($lastAnonymizationDateTime);
                }
                $anonymizationStatus->setToDateTime($olderThanDateTime ?: new \DateTime());
                $anonymizationStatus->setExecutedDateTime(new \DateTime());
                $anonymizationStatus->setAnonymizedRecords($result->count());

                $this->anonymizationStatusRepository->add($anonymizationStatus);
            }
        }
    }

    /**
     * @param $propertyName
     * @param $propertySettings
     * @param $oldValue
     * @param $test
     * @param $verbose
     * @return mixed
     * @throws IllegalObjectTypeException
     * @throws Exception
     */
    private function processPropertyValue(&$propertyName, &$propertySettings, &$oldValue, $test, $verbose): mixed
    {
        $newValue = null;

        if ($oldValue instanceof Asset) {
            if (array_key_exists($oldValue->getMediaType(), $this->settings['dummyAssets'])) {
                $newPersistentResource = $this->resourceManager->importResourceFromContent(
                    file_get_contents($this->settings['dummyAssets'][$oldValue->getMediaType()]),
                    $oldValue->getResource()->getFilename(),
                    $oldValue->getResource()->getCollectionName()
                );
                $oldValue->setResource($newPersistentResource);
                $newValue = $oldValue;
                $this->assetRepository->update($newValue);
                if ($test || $verbose) {
                    $this->outputLine('- Anonymizing property "' . $propertyName . '" from "Asset" to "DummyAsset"');
                }

            } else {
                if ($test || $verbose) {
                    $this->outputLine('- Anonymizing property "' . $propertyName . '" by deleting "Asset"');
                }
            }

        } else {
            if (array_key_exists('shuffle', $propertySettings) && $propertySettings['shuffle']) {
                if (is_string($oldValue)) {
                    $newValue = $this->mbStrShuffle($oldValue);

                    if ($test || $verbose) {
                        $this->outputLine('- Shuffling property "' . $propertyName . '" from "' . $oldValue . '" to "' . $newValue . '"');
                    }

                } else {
                    if ($oldValue instanceof \DateTime) {
                        $oldDateTimeString = $oldValue->format('YmdHis');
                        $newDateTimeString = $this->mbStrShuffle($oldDateTimeString);
                        $newValue = \DateTime::createFromFormat('YmdHis', $newDateTimeString);

                        if ($test || $verbose) {
                            $this->outputLine('- Shuffling property "' . $propertyName . '" from "' . $oldValue->format('Y-m-d H:i:s') . '" to "' . $newValue->format('Y-m-d H:i:s') . '"');
                        }
                    }
                }

            } else {
                if (array_key_exists('anonymize', $propertySettings) && $propertySettings['anonymize']) {
                    if (is_string($oldValue)) {
                        $newValue = preg_replace_callback('/./', function () {
                            return chr(mt_rand(97, 122));
                        }, $oldValue);

                        if ($test || $verbose) {
                            $this->outputLine('- Anonymizing property "' . $propertyName . '" from "' . $oldValue . '" to "' . $newValue . '"');
                        }

                    } else {
                        if ($oldValue instanceof \DateTime) {
                            $oldDateTimeString = 'YYYYmmddHHiiss';
                            $newDateTimeString = preg_replace_callback('/./', function () {
                                return mt_rand(0, 9);
                            }, $oldDateTimeString);
                            $newValue = \DateTime::createFromFormat('YmdHis', $newDateTimeString);

                            if ($test || $verbose) {
                                $this->outputLine('- Shuffling property "' . $propertyName . '" from "' . $oldValue->format('Y-m-d H:i:s') . '" to "' . $newValue->format('Y-m-d H:i:s') . '"');
                            }
                        }
                    }
                }
            }
        }

        return $newValue;
    }

    /**
     * @param $string
     * @return string
     */
    private function mbStrShuffle($string): string
    {
        $chars = $this->mbGetCharsArray($string);
        shuffle($chars);
        return implode('', $chars);
    }

    /**
     * @param $string
     * @return array
     */
    private function mbGetCharsArray($string): array
    {
        $chars = [];

        for ($i = 0, $length = mb_strlen($string); $i < $length; ++$i) {
            $chars[] = mb_substr($string, $i, 1, 'UTF-8');
        }

        return $chars;
    }
}
