<?php

declare(strict_types=1);

namespace MatchBot\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240716104857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update campaigns that have had funding allocation changes in SF';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Charity set Charity.updateFromSFRequiredSince = NOW() WHERE Charity.id IN (
                SELECT charity_id from Campaign WHERE Campaign.salesforceId IN (
                    'a056900002SETkhAAH',
                    'a056900002SEVhXAAX',
                    'a056900002SEVkqAAH',
                        
                    'a056900002SEVoiAAH',
                    'a056900002SEVpMAAX',
                    'a056900002SEVvtAAH',
                          
                    'a056900002SEW18AAH',
                    'a056900002SEW8YAAX',
                    'a056900002SMAFHAA5'                              
                )                            
            ) LIMIT 9;
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE Charity set Charity.updateFromSFRequiredSince = NULL WHERE Charity.id IN (
                SELECT charity_id from Campaign WHERE Campaign.salesforceId IN (
                    'a056900002SETkhAAH',
                    'a056900002SEVhXAAX',
                    'a056900002SEVkqAAH',
                                                                                
                    'a056900002SEVoiAAH',
                    'a056900002SEVpMAAX',
                    'a056900002SEVvtAAH',
                                                                                
                    'a056900002SEW18AAH',
                    'a056900002SEW8YAAX',
                    'a056900002SMAFHAA5'                              
                )                           
            ) LIMIT 9 ;
            SQL
        );
    }
}
