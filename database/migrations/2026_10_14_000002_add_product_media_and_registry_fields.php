<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {Schema::table('products',function(Blueprint $t){$t->decimal('cost_price',14,2)->nullable();$t->string('image_path')->nullable();$t->string('video_source',20)->nullable();$t->string('video_youtube_id',11)->nullable();$t->string('video_path')->nullable();$t->string('video_mime',40)->nullable();$t->boolean('featured')->default(false);$t->unsignedBigInteger('registry_version')->default(0);});}
    public function down():void {Schema::table('products',fn(Blueprint $t)=>$t->dropColumn(['cost_price','image_path','video_source','video_youtube_id','video_path','video_mime','featured','registry_version']));}
};
