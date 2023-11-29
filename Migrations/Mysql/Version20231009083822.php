<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20231009083822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE jvmtech_anonymizer_domain_model_anonymizationstatus (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, fromdatetime DATETIME DEFAULT NULL, todatetime DATETIME NOT NULL, executeddatetime DATETIME NOT NULL, anonymizedrecords INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE jvmtech_anonymizer_domain_model_anonymizationstatus');
    }
}
