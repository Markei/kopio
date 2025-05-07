
# Kopio

An easy backup application for making backups of MySQL/MariaDB, PostgreSQL, Mongo databases, SFTP locations and filesystem locations. It can store backups on local filesystem, SFTP-locations, AWS S3 bucktets and Azure Blob storage.

## Requirements

PHP 8.2

## How to use

Create a directory to store profiles. Create a profile for each combination of source and target. Secure the profile (and target directories) because they can contain passwords.
Use cron to automate the execution of backups and cleanups.

    ./kopio.phar app:backup /path/to/your/profile/directory
    ./kopio.phar app:cleanup /path/to/your/profile/directory

## Backup profile file

    name: Name of the profile
    source:
        # add one of the possible sources
        mysql:
            host:
            port:
            username:
            password:
            database:
            executable:
            enableSsl:
        postgresql:
            host:
            port:
            username:
            password:
            database:
            executable:
        filesystem:
            locations:
              - name:
                path:
        sftp:
            host:
            port:
            path:
            username:
            password:
            pathPrivateKey:
            passphrasePrivateKey:
            useAgent:
            fingerprint:
        mongodb:
            uri:
            executable:
    tmp:
        path: /tmp/backup
        mode: 0600
    target:
        # add one of the possible targets
        filesystem:
            path:
            useCopy: false
        sftp:
            host:
            port:
            path:
            username:
            password:
            pathPrivateKey:
            passphrasePrivateKey:
            useAgent:
            fingerprint:
        s3:
            region:
            version:
            endpoint:
            accessKey:
            secret:
            bucket:
            path:
        azureBlob:
            dsn:
            container:
            path:
    retention:
        simple:
            count: 10
    notifications:
        -   on: success
            email:
                from:
                to:
                subject:
    log: /var/log/kopio/profileName.log

