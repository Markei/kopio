<?php

declare(strict_types=1);

namespace App\Profile;

class Profile
{
    public function __construct(
        public readonly string $name,
        public readonly AbstractSource $source,
        public readonly Tmp $tmp,
        public readonly AbstractTarget $target,
        public readonly AbstractRetention $retention,
        public readonly array $notifications,
        public readonly ?string $logFile
    )
    {
    }

    public function getNotifications(string $when): array
    {
        return array_filter($this->notifications, function (AbstractNotification $notification) use ($when) {
            return $notification->on === $when;
        });
    }

    public static function fromArray(array $data): self
    {
        $data['notifications'] = isset($data['notifications']) ? $data['notifications'] : [];

        return new self(
            $data['name'],
            SourceFactory::fromArray($data['source']),
            Tmp::fromArray($data['tmp']),
            TargetFactory::fromArray($data['target']),
            RetentionFactory::fromArray($data['retention']),
            NotificationFactory::fromArray($data['notifications']),
            $data['log'] ?? null
        );
    }
}