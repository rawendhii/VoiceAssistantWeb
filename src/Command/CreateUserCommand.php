<?php

namespace App\Command;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Create a new user with a role'
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private RoleRepository $roleRepository,
        private UserRepository $userRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $fullNameQuestion = new Question('Full name: ');
        $emailQuestion = new Question('Email: ');
        $passwordQuestion = new Question('Password: ');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);

        $roleQuestion = new ChoiceQuestion(
            'Choose role:',
            ['USER', 'ADMIN'],
            'USER'
        );

        $fullName = trim((string) $helper->ask($input, $output, $fullNameQuestion));
        $email = trim((string) $helper->ask($input, $output, $emailQuestion));
        $plainPassword = (string) $helper->ask($input, $output, $passwordQuestion);
        $roleName = strtoupper((string) $helper->ask($input, $output, $roleQuestion));

        if ($fullName === '' || strlen($fullName) < 3) {
            $output->writeln('<error>Full name must be at least 3 characters.</error>');
            return Command::FAILURE;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln('<error>Invalid email address.</error>');
            return Command::FAILURE;
        }

        if (strlen($plainPassword) < 6) {
            $output->writeln('<error>Password must be at least 6 characters.</error>');
            return Command::FAILURE;
        }

        if ($this->userRepository->findOneBy(['email' => $email])) {
            $output->writeln('<error>This email already exists.</error>');
            return Command::FAILURE;
        }

        $role = $this->roleRepository->findOneBy(['name' => $roleName]);

        if (!$role) {
            $role = new Role();
            $role->setName($roleName);
            $this->entityManager->persist($role);
        }

        $user = new User();
        $user->setFullName($fullName);
        $user->setEmail($email);
        $user->setRole($role);
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, $plainPassword)
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln('<info>User created successfully.</info>');
        $output->writeln(sprintf('Name: %s', $user->getFullName()));
        $output->writeln(sprintf('Email: %s', $user->getEmail()));
        $output->writeln(sprintf('Role: %s', $role->getName()));

        return Command::SUCCESS;
    }
}