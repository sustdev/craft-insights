<?php

namespace sustdev\insights\migrations;

use craft\db\Migration;
use craft\helpers\StringHelper;

/**
 * Generates the shared secret on install and keeps it in a plugin
 * table. Deliberately not in plugin settings: those end up in project
 * config and therefore in git.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%insights}}', [
            'id' => $this->primaryKey(),
            'secret' => $this->string()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->insert('{{%insights}}', [
            'secret' => StringHelper::randomString(40),
        ]);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%insights}}');

        return true;
    }
}
