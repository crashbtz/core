<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Bridge\Doctrine\MongoDbOdm\Extension;

use ApiPlatform\Core\Bridge\Doctrine\MongoDbOdm\Paginator;
use ApiPlatform\Core\DataProvider\Pagination;
use ApiPlatform\Core\Exception\RuntimeException;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ODM\MongoDB\Aggregation\Builder;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;

/**
 * Applies pagination on the Doctrine aggregation for resource collection when enabled.
 *
 * @experimental
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 * @author Samuel ROZE <samuel.roze@gmail.com>
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
final class PaginationExtension implements AggregationResultCollectionExtensionInterface
{
    private $managerRegistry;
    private $pagination;

    public function __construct(ManagerRegistry $managerRegistry, Pagination $pagination)
    {
        $this->managerRegistry = $managerRegistry;
        $this->pagination = $pagination;
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException
     */
    public function applyToCollection(Builder $aggregationBuilder, string $resourceClass, string $operationName = null, array &$context = [])
    {
        if (!$this->pagination->isEnabled($resourceClass, $operationName)) {
            return;
        }

        [, $offset, $limit] = $this->pagination->getPagination($resourceClass, $operationName);

        $manager = $this->managerRegistry->getManagerForClass($resourceClass);
        if (!$manager instanceof DocumentManager) {
            throw new RuntimeException(sprintf('The manager for "%s" must be an instance of "%s" class.', $resourceClass, DocumentManager::class));
        }

        $repository = $manager->getRepository($resourceClass);
        if (!$repository instanceof DocumentRepository) {
            throw new RuntimeException(sprintf('The repository for "%s" must be an instance of "%s".', $resourceClass, DocumentRepository::class));
        }

        $aggregationBuilder
            ->facet()
            ->field('results')->pipeline(
                $repository->createAggregationBuilder()
                    ->skip($offset)
                    ->limit($limit)
            )
            ->field('count')->pipeline(
                $repository->createAggregationBuilder()
                    ->count('count')
            );
    }

    /**
     * {@inheritdoc}
     */
    public function supportsResult(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $this->pagination->isEnabled($resourceClass, $operationName);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException
     */
    public function getResult(Builder $aggregationBuilder, string $resourceClass, string $operationName = null, array $context = [])
    {
        $manager = $this->managerRegistry->getManagerForClass($resourceClass);
        if (!$manager instanceof DocumentManager) {
            throw new RuntimeException(sprintf('The manager for "%s" must be an instance of "%s" class.', $resourceClass, DocumentManager::class));
        }

        return new Paginator($aggregationBuilder->execute(), $manager->getUnitOfWork(), $resourceClass, $aggregationBuilder->getPipeline());
    }
}