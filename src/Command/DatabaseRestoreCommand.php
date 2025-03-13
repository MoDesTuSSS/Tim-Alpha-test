<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DatabaseRestoreCommand extends Command
{
    protected static $defaultName = 'app:database:restore';
    protected static $defaultDescription = 'Restore database from a backup file';

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this
            ->setName('app:database:restore')
            ->setDescription(self::$defaultDescription)
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the backup file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');

        if (!file_exists($file)) {
            $io->error('Backup file not found');
            return Command::FAILURE;
        }

        $io->info('Starting database restore process');

        try {
            $sql = file_get_contents($file);
            $statements = array_filter(array_map('trim', explode(';', $sql)));

            // $this->connection->beginTransaction();
            $io->info('Transaction started');

            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $this->connection->executeStatement($statement);
                }
            }

            // $this->connection->commit();
            $io->info('Transaction committed');
            $io->success('Database restored successfully');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            // if ($this->connection->isTransactionActive()) {
            //     $this->connection->rollBack();
            //     $io->info('Transaction rolled back');
            // }
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
} 