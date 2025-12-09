<?php

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A file with the full schema kept it git may make it easier for us to see changes etc.
 */
#[AsCommand(
    name: 'matchbot:write-schema-files',
    description: "Writes a schema file for reference. Inspired by Ruby On Rails's schema.rb file",
)]
class WriteSchemaFile extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct(null);
    }

    #[\Override]
    public function configure(): void
    {
        $this->addOption(
            'check',
            description: 'Checks if existing schema files match database, instead of writing new files',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hasError = false;
        $connection = $this->em->getConnection();

        /** @var list<string> $tables */
        $tables = $connection->executeQuery('SHOW TABLES')->fetchFirstColumn();

        $schemaDir = dirname(__DIR__, 3) . '/schema';

        $fileNames = scandir($schemaDir);
        \assert(is_array($fileNames));

        $fileNamesOnDisk = array_filter($fileNames, fn(string $fileName) => str_ends_with($fileName, '.sql'));

        $checkModeOn = $input->getOption('check');
        \assert(is_bool($checkModeOn));

        if ($checkModeOn) {
            foreach ($fileNamesOnDisk as $fileName) {
                $tableName = substr($fileName, 0, -4);
                if (!in_array($tableName, $tables, true)) {
                    $error = sprintf(
                        "%s/%s is on disc but table %s is not in database, please delete or add to db",
                        $schemaDir,
                        $fileName,
                        $tableName
                    );
                    $io->error($error);
                    $hasError = true;
                }
            }
        }

        foreach ($tables as $table) {
            if (!in_array("$table.sql", $fileNamesOnDisk, true) && $checkModeOn) {
                $error = sprintf("Did not find expected file %s/%s.sql", $schemaDir, $table);
                $io->error($error);
                $hasError = true;
            }

            $description = $connection->executeQuery("SHOW CREATE TABLE `$table`")->fetchAllAssociative();
            $createTableStatement = $description[0]['Create Table'] ?? $description[0]['Create View'];
            \assert(is_string($createTableStatement));

            /* AUTO_INCREMENT varies depending on data (not metadata) in db, not suitable to commit) */
            $createTableStatement = (string)\preg_replace('/AUTO_INCREMENT=\d+ /', '', $createTableStatement);

            /* Charset and collation vary between local docker setup and circleci. Leave out of files for now to allow
            comparisons

            Local has       `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci``
            Circle CI has   `ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci`
            */
            $createTableStatement = (string)\preg_replace(
                "/\) ENGINE=InnoDB DEFAULT CHARSET.*/",
                ")\n",
                $createTableStatement
            );
            $createTableStatement = (string)\preg_replace('/ COLLATE utf8mb[34]_[a-z_]+/', '', $createTableStatement);

            $filename = "{$schemaDir}/{$table}.sql";

            $sql = <<<"SQL"
            -- Auto generated file, do not edit.
            -- run ./matchbot matchbot:write-schema-files to update
            
            -- Note that CHARSET and COLLATE details have been removed to allow sucessful comparisons between schemas
            -- on dev machines and CircleCI. Do not use these files to create a database. They are provided for
            -- information only.
            
            $createTableStatement
            SQL;

            if ($checkModeOn) {
                try {
                    Assert::assertSame($sql, file_get_contents($filename));
                    $io->writeln("$filename matches db schema");
                } catch (ExpectationFailedException $exception) { // @phpstan-ignore catch.internalClass
                    $io->error("$filename content not as expected based on DB schema");

                    /** @psalm-suppress InternalMethod */
                    $io->writeln(
                        $exception->getComparisonFailure()?->getDiff() // @phpstan-ignore method.internalClass
                            ?? throw new \Exception('Missing diff')
                    );
                    $hasError = true;
                }
            } else {
                $io->writeln("Writing $filename");
                file_put_contents($filename, $sql);
            }
        }

        if ($checkModeOn && ! $hasError) {
            $io->success("SQL files in $schemaDir/ match DB schema");
        }

        if ($hasError) {
            $io->error(
                "Differences found between files and schema.\n" .
                "Run ./matchbot matchbot:write-schema-files to update files from DB schema"
            );
        }

        return $hasError ? 1 : 0;
    }
}
