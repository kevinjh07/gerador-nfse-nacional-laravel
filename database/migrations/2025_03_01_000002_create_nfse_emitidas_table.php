<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNfseEmitidasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('nfse_emitidas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('numero');
            $table->string('serie', 10);
            $table->string('id_dps', 60);
            $table->string('chave_acesso', 50)->nullable();
            $table->string('protocolo', 50)->nullable();
            $table->unsignedTinyInteger('ambiente');
            $table->dateTime('data_emissao');
            $table->date('competencia');
            $table->decimal('valor_servico', 15, 2);
            $table->string('prestador_cnpj', 14);
            $table->string('tomador_documento', 14);
            $table->string('tomador_nome', 255);
            $table->string('descricao_servico', 500)->nullable();
            $table->timestamps();

            $table->index(['serie', 'numero']);
            $table->index('chave_acesso');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('nfse_emitidas');
    }
}
