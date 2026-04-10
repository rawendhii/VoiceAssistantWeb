<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260407193345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE voice_command DROP name, DROP trigger_text, DROP action');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D7BE11475A93713B ON voice_command (keyword)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_D7BE11475A93713B ON voice_command');
        $this->addSql('ALTER TABLE voice_command ADD name VARCHAR(255) NOT NULL, ADD trigger_text VARCHAR(255) NOT NULL, ADD action VARCHAR(255) NOT NULL');
    }
}
