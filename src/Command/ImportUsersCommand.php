<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ImportUsersCommand extends Command
{
    protected static $defaultName = 'app:database:import-users';
    protected static $defaultDescription = 'Import users from CSV file';

    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
    }

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription(self::$defaultDescription)
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');

        if (!file_exists($file)) {
            $io->error('CSV file not found');
            return Command::FAILURE;
        }

        $io->info('Starting users import process');

        try {
            $handle = fopen($file, 'r');
            $header = fgetcsv($handle);
            $count = 0;

            while (($data = fgetcsv($handle)) !== false) {
                $user = new User();
                $user->setName($data[0]);
                $user->setEmail($data[1]);
                $user->setUsername($data[2]);
                $user->setAddress($data[3]);
                $user->setRoles([$data[4]]);

                // Устанавливаем пароль по умолчанию
                $hashedPassword = $this->passwordHasher->hashPassword($user, 'password');
                $user->setPassword($hashedPassword);

                $this->entityManager->persist($user);
                $count++;
            }

            fclose($handle);
            $this->entityManager->flush();
            $io->success(sprintf('Successfully imported %d users', $count));
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
} 