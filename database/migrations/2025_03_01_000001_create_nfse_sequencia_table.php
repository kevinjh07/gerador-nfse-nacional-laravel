<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateNfseSequenciaTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('nfse_sequencia', function (Blueprint $table) {
            $table->string('serie', 10)->primary();
            $table->unsignedInteger('proximo_numero')->default(1);
            $table->timestamps();
        });

        $inicial = (int) (env('NFSE_NUMERO_DPS', 0) ?: 0);
        if ($inicial < 0) {
            $inicial = 0;
        }
        DB::table('nfse_sequencia')->insert([
            'serie' => env('NFSE_SERIE_DPS', '900'),
            'proximo_numero' => $inicial + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('nfse_sequencia');
    }
}
