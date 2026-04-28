<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260424152654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE command_history DROP FOREIGN KEY FK_C8D023A8A76ED395');
        $this->addSql('ALTER TABLE command_history ADD CONSTRAINT FK_C8D023A8A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE managed_files DROP FOREIGN KEY FK_2BE42997A76ED395');
        $this->addSql('ALTER TABLE managed_files ADD CONSTRAINT FK_2BE42997A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE command_history DROP FOREIGN KEY FK_C8D023A8A76ED395');
        $this->addSql('ALTER TABLE command_history ADD CONSTRAINT FK_C8D023A8A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE managed_files DROP FOREIGN KEY FK_2BE42997A76ED395');
        $this->addSql('ALTER TABLE managed_files ADD CONSTRAINT FK_2BE42997A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
    }
}
