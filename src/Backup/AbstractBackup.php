<?php

declare(strict_types=1);
namespace App\Backup;

use InvalidArgumentException;
use App\Exception\CleanUpFailedException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

abstract class AbstractBackup
{
    protected $name;
    protected $type;
    protected $source;
    protected $destination;
    protected $keysToCheck;
    protected $retention;
    protected $exception;

    public function __construct(string $name, string $type, array $source, array $target, string $destination, string $retention)
    {
        $this->name = $name;
        $this->type = $type;
        $this->source = $source;
        $this->target = $target;
        $this->destination = rtrim($destination, '/');
        $this->retention = $retention;
    }

    public function prepareBackup(): void 
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->destination);
    }

    public function verifyConfig(): void 
    {
        $allowedTargets = ['filesystem', 'azure', 'aws'];

        if (count($this->target) !== 1) {
            throw new InvalidArgumentException(
                'You can only specify one target!'
            );
        }

        $targetKey = array_key_first($this->target);
        if (!in_array($targetKey, $allowedTargets)) {
            throw new InvalidArgumentException(
                "The specified target key is not allowed! Allowed keys are 'filesystem', 'azure', 'aws'"
            );
        }

        foreach($this->keysToCheck as $key) {
            if (!array_key_exists($key, $this->source)) {
                throw new InvalidArgumentException(
                    'The key ' . "'" . $key . "'" . ' is not defined'
                );
            }
        }
    }

    public function generateRandomString($length = 8): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType($type): void
    {
        $this->type = $type;
    }

    public function getException(): \Exception
    {
        return $this->exception;
    }

    public function setException(\Exception $exception): void
    {
        $this->exception = $exception;
    }

    abstract public function checkSource(): void;

    abstract public function executeBackup(): void;

    public function cleanUp(): void
    {
        $finder = new Finder();
        $files = $finder->in($this->destination)->files()->name('*');
        
        $filesystem = new Filesystem();
        
        foreach ($files as $file) {
            $fileDate = $file->getFilenameWithoutExtension();
            $fileDateObject = \DateTime::createFromFormat('YmdHis', $fileDate);

            $currentDate = new \DateTime('now');
            
            $interval = $fileDateObject->diff($currentDate);

            if ($this->retention < $interval->days) {
                try {
                     $filesystem->remove($file->getRealPath());
                } catch (IOException $e) {
                    throw new CleanUpFailedException(
                        '[ERROR] Failed to create backup for file: ' . $file->getRealPath()
                    );
                }
            }
        }
    }
}