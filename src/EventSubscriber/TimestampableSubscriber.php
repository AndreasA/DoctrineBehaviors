<?php

declare(strict_types=1);

namespace Knp\DoctrineBehaviors\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;

final class TimestampableSubscriber implements EventSubscriber
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $loadClassMetadataEventArgs): void
    {
        $classMetadata = $loadClassMetadataEventArgs->getClassMetadata();
        if ($classMetadata->reflClass === null) {
            // Class has not yet been fully built, ignore this event
            return;
        }

        if (! is_a($classMetadata->reflClass->getName(), TimestampableInterface::class, true)) {
            return;
        }

        $createdAtProperties = call_user_func([$classMetadata->reflClass->getName(), 'getCreatedAtProperties']);
        $updatedAtProperties = call_user_func([$classMetadata->reflClass->getName(), 'getUpdatedAtProperties']);
        // Merge properties and ensure they are correctly merged in case associative arrays are returned.
        $properties = array_merge(array_values($createdAtProperties), array_values($updatedAtProperties));

        // If there are no timestampable properties, there is no need to register the the events.
        if (empty($properties)) {
            return;
        }

        $classMetadata->addLifecycleCallback('updateTimestamps', Events::prePersist);
        $classMetadata->addLifecycleCallback('updateTimestamps', Events::preUpdate);

        foreach ($properties as $field) {
            if (! $classMetadata->hasField($field)) {
                $classMetadata->mapField([
                    'fieldName' => $field,
                    'type' => $this->getFieldType(),
                    'nullable' => true,
                ]);
            }
        }
    }

    /**
     * @return string[]
     */
    public function getSubscribedEvents(): array
    {
        return [Events::loadClassMetadata];
    }

    private function getFieldType(): string
    {
        return $this->isPostgreSqlPlatform() ? 'datetimetz' : 'datetime';
    }

    private function isPostgreSqlPlatform(): bool
    {
        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();

        return $connection->getDatabasePlatform() instanceof PostgreSqlPlatform;
    }
}
