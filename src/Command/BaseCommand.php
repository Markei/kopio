<?php

declare(strict_types=1);
namespace App\Command;

use Psr\Log\LoggerInterface;

use App\Backup\AbstractBackup;

use App\Backup\LocalBackup;
use App\Backup\MySqlBackup;
use App\Backup\PostgreSqlBackup;
use App\Backup\SCPBackup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

use App\MessageSender;
use App\MessageSenderFactory;

use Symfony\Component\Notifier\Channel\ChatChannel;
use Symfony\Component\Notifier\Bridge\MicrosoftTeams\MicrosoftTeamsTransport;

use Symfony\Component\Notifier\Notifier;
use Symfony\Component\Notifier\Notification\Notification;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;

abstract class BaseCommand extends Command
{
    protected $messageSenderFactory;
    protected $logger;
    protected $sleepTime;
    protected $summaryTeamsDsn;
    protected $summaryMailSender;
    protected $summaryMailReceiver;
    protected $mailer;
    protected $startTime;
    protected $endTime;
    protected $jobType;

    public function __construct(MessageSenderFactory $messageSenderFactory, LoggerInterface $logger, int $sleepTime, string $summaryTeamsDsn, string $summaryMailSender, string $summaryMailReceiver, MailerInterface $mailer)
    {
        parent::__construct();
        $this->messageSenderFactory = $messageSenderFactory;
        $this->logger = $logger;
        $this->sleepTime = $sleepTime;
        $this->summaryTeamsDsn = $summaryTeamsDsn;
        $this->summaryMailSender = $summaryMailSender;
        $this->summaryMailReceiver = $summaryMailReceiver;
        $this->mailer = $mailer;
        $this->startTime = null;
        $this->endTime = null;
        $this->jobType = null;
    }

    protected function configure()
    {
        $this
            ->addArgument('profilesDirectory', InputArgument::REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $finder = new Finder();
        $files = $finder->in($input->getArgument('profilesDirectory'))->files()->name(['*.yaml', '*.yml']);

        $totalJobs = 0;
        $failedJobs = [];
        $succeedJobs= [];

        $this->startTime = new \DateTime();

        foreach ($files as $file) {
            $totalJobs = $totalJobs + 1;

            $output->writeln(PHP_EOL .'Parsing file: ' . $file->getRealPath());
            $parsedFile = Yaml::parseFile($file->getRealPath());

            $backupJob = null;

            if (isset($parsedFile['MariaDB'])) {
                $profileName = $parsedFile['MariaDB']['name'];

                $output->writeln(
                    'Starting action for MySQL/MariaDB with profile: ' . $profileName
                );

                $source = $parsedFile['MariaDB']['source'];
                $target = $parsedFile['MariaDB']['target'];
                $retention = $parsedFile['MariaDB']['retention']['simple']['days'];

                $backupJob = new MySqlBackup($profileName, 'Backup', $source, $target, $target[array_key_first($target)] . DIRECTORY_SEPARATOR . $profileName, strval($retention));

                $messageSender = $this->messageSenderFactory->createMessageSender(
                    $parsedFile['MariaDB']['notifications'], $backupJob
                );
            } else if (isset($parsedFile['PostgreSQL'])) {
                $profileName = $parsedFile['PostgreSQL']['name'];

                $output->writeln(
                    'Starting action for PostgreSQL with profile: ' . $profileName
                );

                $source = $parsedFile['PostgreSQL']['source'];
                $target = $parsedFile['PostgreSQL']['target'];
                $retention = $parsedFile['PostgreSQL']['retention']['simple']['days'];

                $backupJob = new PostgreSqlBackup($profileName, 'Backup', $source, $target, $target[array_key_first($target)] . DIRECTORY_SEPARATOR . $profileName, strval($retention));

                $messageSender = $this->messageSenderFactory->createMessageSender(
                    $parsedFile['PostgreSQL']['notifications'], $backupJob
                );
            } else if (isset($parsedFile['local'])) {
                $profileName = $parsedFile['local']['name'];

                $output->writeln(
                    'Starting action for local backup with profile: ' . $profileName
                );
                
                $source = $parsedFile['local']['source'];
                $target = $parsedFile['local']['target'];
                $retention = $parsedFile['local']['retention']['simple']['days'];
                
                $backupJob = new LocalBackup($profileName, 'Backup', $source, $target, $target[array_key_first($target)] . DIRECTORY_SEPARATOR . $profileName, strval($retention));

                $messageSender = $this->messageSenderFactory->createMessageSender(
                    $parsedFile['local']['notifications'], $backupJob
                );
            } else if (isset($parsedFile['SCP'])) {
                $profileName = $parsedFile['SCP']['name'];

                $output->writeln(
                    'Starting action for SCP backup with profile: ' . $profileName
                );

                $source = $parsedFile['SCP']['source'];
                $target = $parsedFile['SCP']['target'];
                $retention = $parsedFile['SCP']['retention']['simple']['days'];

                $backupJob = new SCPBackup($profileName, 'Backup', $source, $target, $target[array_key_first($target)] . DIRECTORY_SEPARATOR . $profileName, strval($retention));

                $messageSender = $this->messageSenderFactory->createMessageSender(
                    $parsedFile['SCP']['notifications'], $backupJob
                );
            } else {
                throw new \InvalidArgumentException(
                    'Unknown backup type'
                );
            }

            $this->jobType = $backupJob->getType();

            if (!$this->doExecute($backupJob, $messageSender, $output, $this->logger)) {
                $failedJobs[] = $backupJob;
            } else {
                $succeedJobs[] = $backupJob;
            }

            sleep($this->sleepTime);
        }

        $this->endTime = new \DateTime();

        $message = PHP_EOL . 'Report Kopio run ' . $this->startTime->format('d-m-Y') . ' - ' . $this->endTime->format('d-m-Y') . ' on ' . gethostname() . PHP_EOL;
        $message .= PHP_EOL . 'run type: ' . $this->jobType . PHP_EOL;
        $message .= count($succeedJobs) . ' successfull ' . count($failedJobs) . ' failed' . PHP_EOL;
        $message .= PHP_EOL . 'Failed profiles:' . PHP_EOL;

        foreach ($failedJobs as $job) {
            $message .= PHP_EOL . $job->getName() . PHP_EOL;
        }

        $message .= PHP_EOL . 'Succeed profiles:' . PHP_EOL;

        foreach ($succeedJobs as $job) {
            $message .= PHP_EOL . $job->getName() . PHP_EOL;
        }

        if (!empty($failedJobs)) {
            $output->writeln(
                PHP_EOL . 'Failed to create ' . count($failedJobs) . ' of ' . $totalJobs .' backups:'
            );

            $count = 1;
            foreach ($failedJobs as $job) {
                $output->writeln(
                    $count . ' [' . $job->getType() . '] ' . $job->getName() . ' failed with exception: ' . $job->getException()->getMessage()
                );
                
                $count = $count + 1;
            }

            $this->sendTeamsMessage($message);
            $this->sendEmail($message);

            $output->write(PHP_EOL);

            return Command::FAILURE;
        }

        $this->sendTeamsMessage($message);
        $this->sendEmail($message);

        $output->write(PHP_EOL);

        return Command::SUCCESS;
    }

    protected abstract function doExecute(AbstractBackup $backupJob, MessageSender $messageSender, OutputInterface $output, LoggerInterface $logger): bool;

    protected function sendTeamsMessage(string $message): void
    {
        try {
            $microsoftTransport = new MicrosoftTeamsTransport($this->summaryTeamsDsn);

            $channel = new ChatChannel($microsoftTransport);
            $notifier = new Notifier(['chat' => $channel]);

            $notification = new Notification($message, ['chat']);

            $notifier->send($notification);
        } catch (\Exception $e) {
            $this->logger->notice($e->getMessage());
        }
    }

    protected function sendEmail($message): void
    {
        try {
            $email = (new Email())
                ->from($this->summaryMailSender)
                ->to($this->summaryMailReceiver)
                ->priority(Email::PRIORITY_HIGH)
                ->subject('Backup summary')
                ->text($message)
            ;

            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logger->notice($e->getMessage());
        }
    }
}