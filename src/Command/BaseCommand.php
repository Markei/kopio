<?php

declare(strict_types=1);
namespace App\Command;

use App\Backup\AbstractBackup;
use App\Backup\LocalBackup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

use App\Backup\MySqlBackup;
use App\Backup\PostgreSqlBackup;
use App\Backup\SCPBackup;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;

use App\MessageSender;
use App\MessageSenderFactory;
use DateTime;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\Bridge\MicrosoftTeams\MicrosoftTeamsTransport;
use Symfony\Component\Notifier\Channel\ChatChannel;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Notifier;

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

        $this->startTime = new DateTime();

        foreach ($files as $file) {
            $totalJobs = $totalJobs + 1;
            $output->writeln(PHP_EOL .'Parsing file: ' . $file->getRealPath());
            $parsedFile = Yaml::parseFile($file->getRealPath());

            $backupJob = null;

            if (isset($parsedFile['mariadb'])) {
                $output->writeln('Starting action for MySQL/MariaDB with profile: ' . $parsedFile['mariadb']['name']);
                $backupJob = new MySqlBackup($parsedFile['mariadb']['name'], 'Backup', $parsedFile['mariadb']['source'], $parsedFile['mariadb']['target']['filesystem'] . DIRECTORY_SEPARATOR . $parsedFile['mariadb']['name'], strval($parsedFile['mariadb']['retention']['simple']['days']));
                $messageSender = $this->messageSenderFactory->createMessageSender($parsedFile['mariadb']['notifications'], $backupJob);
            } else if (isset($parsedFile['postgresql'])) {
                $output->writeln('Starting action for postreSql with profile: ' . $parsedFile['postgresql']['name']);
                $backupJob = new PostgreSqlBackup($parsedFile['postgresql']['name'], 'Backup', $parsedFile['postgresql']['source'], $parsedFile['postgresql']['target']['filesystem'] . DIRECTORY_SEPARATOR . $parsedFile['postgresql']['name'], strval($parsedFile['postgresql']['retention']['simple']['days']));
                $messageSender = $this->messageSenderFactory->createMessageSender($parsedFile['postgresql']['notifications'], $backupJob);
            } else if (isset($parsedFile['local'])) {
                $output->writeln('Starting action for local backup with profile: ' . $parsedFile['local']['name']);
                $backupJob = new LocalBackup($parsedFile['local']['name'], 'Backup',$parsedFile['local']['source'], $parsedFile['local']['destination']['filesystem'] . DIRECTORY_SEPARATOR . $parsedFile['local']['name'], strval($parsedFile['local']['retention']['simple']['days']));
                $messageSender = $this->messageSenderFactory->createMessageSender($parsedFile['local']['notifications'], $backupJob);
            } else if (isset($parsedFile['scp'])) {
                $output->writeln('Starting action for scp backup with profile: ' . $parsedFile['scp']['name']);
                $backupJob = new SCPBackup($parsedFile['scp']['name'], 'Backup', $parsedFile['scp']['source'], $parsedFile['scp']['destination']['filesystem'] . DIRECTORY_SEPARATOR . $parsedFile['scp']['name'], strval($parsedFile['scp']['retention']['simple']['days']));
                $messageSender = $this->messageSenderFactory->createMessageSender($parsedFile['scp']['notifications'], $backupJob);
            } else {
                throw new InvalidArgumentException('Unknown backup type');
            }

            $this->jobType = $backupJob->getType();

            if (!$this->doExecute($backupJob, $messageSender, $output, $this->logger)) {
                $failedJobs[] = $backupJob;
            } else {
                $succeedJobs[] = $backupJob;
            }

            sleep($this->sleepTime);
        }

        $this->endTime = new DateTime();

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
            $output->writeln(PHP_EOL . 'Failed to create ' . count($failedJobs) . ' of ' . $totalJobs .' backups:');

            $count = 1;
            foreach ($failedJobs as $job) {
                $output->writeln($count . ' [' . $job->getType() . '] ' . $job->getName() . ' failed with exception: ' . $job->getException()->getMessage());
                $count = $count + 1;
            }

            $this->sendTeamsMessage($message);
            $this->sendEmail($message);

            return Command::FAILURE;
        }

        $this->sendTeamsMessage($message);
        $this->sendEmail($message);

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