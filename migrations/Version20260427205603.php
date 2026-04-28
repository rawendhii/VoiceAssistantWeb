<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427205603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add device token to desktop actions and add indexes for history/action performance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE desktop_actions ADD device_token VARCHAR(100) NOT NULL DEFAULT 'rawen_laptop_voice_assistant'");
        $this->addSql("ALTER TABLE desktop_actions CHANGE status status VARCHAR(30) NOT NULL");

        $this->addSql('ALTER TABLE desktop_actions RENAME INDEX idx_desktop_action_user TO IDX_C38768CDA76ED395');

        $this->addSql('CREATE INDEX IDX_COMMAND_HISTORY_STATUS ON command_history (status)');
        $this->addSql('CREATE INDEX IDX_COMMAND_HISTORY_EXECUTED_AT ON command_history (executed_at)');
        $this->addSql('CREATE INDEX IDX_DESKTOP_ACTIONS_STATUS ON desktop_actions (status)');
        $this->addSql('CREATE INDEX IDX_DESKTOP_ACTIONS_CREATED_AT ON desktop_actions (created_at)');
        $this->addSql('CREATE INDEX IDX_DESKTOP_ACTIONS_DEVICE_TOKEN ON desktop_actions (device_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_COMMAND_HISTORY_STATUS ON command_history');
        $this->addSql('DROP INDEX IDX_COMMAND_HISTORY_EXECUTED_AT ON command_history');
        $this->addSql('DROP INDEX IDX_DESKTOP_ACTIONS_STATUS ON desktop_actions');
        $this->addSql('DROP INDEX IDX_DESKTOP_ACTIONS_CREATED_AT ON desktop_actions');
        $this->addSql('DROP INDEX IDX_DESKTOP_ACTIONS_DEVICE_TOKEN ON desktop_actions');

        $this->addSql('ALTER TABLE desktop_actions RENAME INDEX idx_c38768cda76ed395 TO IDX_DESKTOP_ACTION_USER');

        $this->addSql("ALTER TABLE desktop_actions DROP device_token");
        $this->addSql("ALTER TABLE desktop_actions CHANGE status status VARCHAR(30) DEFAULT 'PENDING' NOT NULL");
    }
}