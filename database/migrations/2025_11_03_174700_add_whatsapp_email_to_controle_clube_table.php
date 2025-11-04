<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->string('whatsapp', 20)->nullable()->after('observacoes');
            $table->string('email', 255)->nullable()->after('whatsapp');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('controle_clube', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'email']);
        });
    }
};
