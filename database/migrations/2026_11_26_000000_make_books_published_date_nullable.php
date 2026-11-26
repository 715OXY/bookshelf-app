<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * MySQL標準DDLで出版日のNULL許可だけを変更し、既存値と制約を保持する。
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $table = $connection->getQueryGrammar()->wrapTable('books');

        $connection->statement('ALTER TABLE '.$table.' MODIFY COLUMN `published_date` DATE NULL');
    }

    /**
     * NULLがない場合だけNOT NULLへ戻す。データの削除・日付補完は行わない。
     *
     * @throws RuntimeException 出版日が未登録の書籍が残っている場合
     */
    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        if ($connection->table('books')->whereNull('published_date')->exists()) {
            throw new RuntimeException('出版日がNULLの書籍が残っているため、NOT NULLへの復旧を中止しました。データを削除・補完せず、復旧方針を確認してください。');
        }

        $table = $connection->getQueryGrammar()->wrapTable('books');

        $connection->statement('ALTER TABLE '.$table.' MODIFY COLUMN `published_date` DATE NOT NULL');
    }
};
