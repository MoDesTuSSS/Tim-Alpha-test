<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class DatabaseBackupCommand extends Command
{
    protected static $defaultName = 'app:database:backup';
    protected static $defaultDescription = 'Create a database backup';

    private Connection $connection;
    private string $projectDir;

    public function __construct(Connection $connection, ParameterBagInterface $params)
    {
        parent::__construct();
        $this->connection = $connection;
        $this->projectDir = $params->get('kernel.project_dir');
    }

    protected function configure(): void
    {
        $this
            ->setName('app:database:backup')
            ->setDescription('Create a database backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $backupDir = sprintf('%s/var/backup', $this->projectDir);
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0750, true);
            }

            $filename = sprintf('backup_%s.sql', date('Y-m-d_H-i-s'));
            $backupPath = sprintf('%s/%s', $backupDir, $filename);

            // Use Doctrine's built-in schema tool
            $schemaManager = $this->connection->createSchemaManager();
            $schema = $schemaManager->introspectSchema();

            $sql = [];
            $platform = $this->connection->getDatabasePlatform();

            // Schema
            foreach ($platform->getCreateTablesSQL($schema->getTables()) as $query) {
                $sql[] = $query . ';';
            }

            // Data
            foreach ($schema->getTables() as $table) {
                $rows = $this->connection->fetchAllAssociative("SELECT * FROM {$table->getName()}");
                foreach ($rows as $row) {
                    $values = array_map(function ($value) {
                        return $value === null ? 'NULL' : $this->connection->quote($value);
                    }, $row);

                    $sql[] = sprintf(
                        "INSERT INTO %s (%s) VALUES (%s);",
                        $table->getName(),
                        implode(', ', array_keys($row)),
                        implode(', ', $values)
                    );
                }
            }

            file_put_contents($backupPath, implode("\n", $sql));

            $io->success(sprintf('Database backup created: %s', $filename));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
} 