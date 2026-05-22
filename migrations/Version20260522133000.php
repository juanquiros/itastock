<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;use Doctrine\Migrations\AbstractMigration;
final class Version20260522133000 extends AbstractMigration
{
 public function getDescription(): string { return 'Sprint 2B audit logs + application mode'; }
 public function up(Schema $schema): void {
  $this->addSql("ALTER TABLE fiscal_rules ADD application_mode VARCHAR(30) DEFAULT 'APPLY' NOT NULL");
  $this->addSql('CREATE TABLE fiscal_rule_audit_logs (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, fiscal_rule_id INT DEFAULT NULL, user_id INT DEFAULT NULL, action VARCHAR(40) NOT NULL, rule_name VARCHAR(160) DEFAULT NULL, before_data JSON DEFAULT NULL, after_data JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_FRA_BUSINESS (business_id), INDEX IDX_FRA_RULE (fiscal_rule_id), INDEX IDX_FRA_USER (user_id), INDEX IDX_FRA_ACTION (action), INDEX IDX_FRA_CREATED (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
  $this->addSql('ALTER TABLE fiscal_rule_audit_logs ADD CONSTRAINT FK_FRA_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id)');
  $this->addSql('ALTER TABLE fiscal_rule_audit_logs ADD CONSTRAINT FK_FRA_RULE FOREIGN KEY (fiscal_rule_id) REFERENCES fiscal_rules (id) ON DELETE SET NULL');
  $this->addSql('ALTER TABLE fiscal_rule_audit_logs ADD CONSTRAINT FK_FRA_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
 }
 public function down(Schema $schema): void { $this->addSql('ALTER TABLE fiscal_rule_audit_logs DROP FOREIGN KEY FK_FRA_BUSINESS'); $this->addSql('ALTER TABLE fiscal_rule_audit_logs DROP FOREIGN KEY FK_FRA_RULE'); $this->addSql('ALTER TABLE fiscal_rule_audit_logs DROP FOREIGN KEY FK_FRA_USER'); $this->addSql('DROP TABLE fiscal_rule_audit_logs'); $this->addSql('ALTER TABLE fiscal_rules DROP application_mode'); }
}
