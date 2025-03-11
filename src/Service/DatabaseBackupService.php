<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class DatabaseBackupService
{
    private EntityManagerInterface $entityManager;
    private string $projectDir;

    public function __construct(
        EntityManagerInterface $entityManager,
        ParameterBagInterface $params
    ) {
        $this->entityManager = $entityManager;
        $this->projectDir = $params->get('kernel.project_dir');
    }

    public function backup(): string
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();
        $schemaManager = $connection->createSchemaManager();

        // Get current schema
        $schema = $schemaManager->introspectSchema();

        // Generate SQL for complete schema
        $sql = [];
        
        // Create schema SQL
        $sql[] = "-- Schema backup created at " . date('Y-m-d H:i:s');
        foreach ($platform->getCreateTablesSQL($schema->getTables()) as $query) {
            $sql[] = $query . ';';
        }

        // Backup data
        foreach ($schema->getTables() as $table) {
            $sql[] = "\n-- Data for table `{$table->getName()}`";
            $rows = $connection->fetchAllAssociative("SELECT * FROM {$table->getName()}");
            
            foreach ($rows as $row) {
                $values = array_map(function ($value) use ($connection) {
                    if ($value === null) {
                        return 'NULL';
                    }
                    return $connection->quote($value);
                }, $row);
                
                $sql[] = "INSERT INTO {$table->getName()} (" . 
                    implode(', ', array_keys($row)) . 
                    ") VALUES (" . implode(', ', $values) . ");";
            }
        }

        $backupContent = implode("\n", $sql);
        $filename = sprintf('backup_%s.sql', date('Y-m-d_H-i-s'));
        $backupPath = sprintf('%s/var/backup/%s', $this->projectDir, $filename);

        // Create backup directory if it doesn't exist
        if (!is_dir(dirname($backupPath))) {
            mkdir(dirname($backupPath), 0750, true);
        }

        file_put_contents($backupPath, $backupContent);
        return $backupPath;
    }

    public function restore(string $sqlContent): void
    {
        $connection = $this->entityManager->getConnection();
        
        // Begin transaction
        $connection->beginTransaction();
        
        try {
            // Split SQL into individual statements
            $statements = array_filter(
                array_map(
                    'trim',
                    explode(';', $sqlContent)
                )
            );

            // Execute each statement
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $connection->executeStatement($statement);
                }
            }
            
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }
} 