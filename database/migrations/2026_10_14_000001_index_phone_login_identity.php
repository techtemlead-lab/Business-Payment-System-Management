<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up():void
    {
        DB::statement("CREATE INDEX users_phone_login_lookup ON users ((CASE WHEN regexp_replace(phone, '[^0-9]', '', 'g') LIKE '00%' THEN substr(regexp_replace(phone, '[^0-9]', '', 'g'), 3) WHEN char_length(regexp_replace(phone, '[^0-9]', '', 'g')) = 11 AND regexp_replace(phone, '[^0-9]', '', 'g') LIKE '01%' THEN '88' || regexp_replace(phone, '[^0-9]', '', 'g') ELSE regexp_replace(phone, '[^0-9]', '', 'g') END)) WHERE phone IS NOT NULL");
    }
    public function down():void {DB::statement('DROP INDEX IF EXISTS users_phone_login_lookup');}
};
