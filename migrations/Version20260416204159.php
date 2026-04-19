<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416204159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE command_history (id INT AUTO_INCREMENT NOT NULL, executed_text LONGTEXT NOT NULL, status VARCHAR(50) NOT NULL, executed_at DATETIME NOT NULL, user_id INT NOT NULL, command_id INT DEFAULT NULL, INDEX IDX_C8D023A8A76ED395 (user_id), INDEX IDX_C8D023A833E1689A (command_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE command_history ADD CONSTRAINT FK_C8D023A8A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id)');
        $this->addSql('ALTER TABLE command_history ADD CONSTRAINT FK_C8D023A833E1689A FOREIGN KEY (command_id) REFERENCES voice_commands (id)');
        $this->addSql('ALTER TABLE managed_files DROP FOREIGN KEY `FK_2BE42997A76ED395`');
        $this->addSql('ALTER TABLE managed_files CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE managed_files ADD CONSTRAINT FK_2BE42997A76ED395 FOREIGN KEY (user_id) REFERENCES `users` (id)');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY `FK_1483A5E9D60322AC`');
        $this->addSql('ALTER TABLE users DROP created_at');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9D60322AC FOREIGN KEY (role_id) REFERENCES roles (id)');
        $this->addSql('ALTER TABLE voice_commands DROP created_at, CHANGE active active TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE command_history DROP FOREIGN KEY FK_C8D023A8A76ED395');
        $this->addSql('ALTER TABLE command_history DROP FOREIGN KEY FK_C8D023A833E1689A');
        $this->addSql('DROP TABLE command_history');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('ALTER TABLE managed_files DROP FOREIGN KEY FK_2BE42997A76ED395');
        $this->addSql('ALTER TABLE managed_files CHANGE created_at created_at DATETIME DEFAULT \'current_timestamp()\' NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT \'current_timestamp()\' NOT NULL');
        $this->addSql('ALTER TABLE managed_files ADD CONSTRAINT `FK_2BE42997A76ED395` FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `users` DROP FOREIGN KEY FK_1483A5E9D60322AC');
        $this->addSql('ALTER TABLE `users` ADD created_at DATETIME DEFAULT \'current_timestamp()\'');
        $this->addSql('ALTER TABLE `users` ADD CONSTRAINT `FK_1483A5E9D60322AC` FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE voice_commands ADD created_at DATETIME DEFAULT \'current_timestamp()\', CHANGE active active TINYINT DEFAULT 1 NOT NULL');
    }
}
