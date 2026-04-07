<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('libro_oficios', function (Blueprint $table) {
            $table->id();
            $table->string('oficio_peticion')->nullable();
            $table->date('fecha_oficio')->nullable();
            $table->timestamps();
        });

        Schema::create('fuentes', function (Blueprint $table) {
            $table->id();
            $table->text('url');
            $table->string('titulo')->nullable();
            $table->text('descripcion')->nullable();
            $table->longText('texto')->nullable();
            $table->string('estado_captura')->nullable();
            $table->string('ruta_archivo')->nullable();
            $table->timestamp('capturado_en')->nullable();
            $table->string('hash_contenido')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('fuente_oficio', function (Blueprint $table) {
            $table->foreignId('fuente_id')->constrained('fuentes')->cascadeOnDelete();
            $table->foreignId('oficio_id')->constrained('libro_oficios')->cascadeOnDelete();
            $table->primary(['fuente_id', 'oficio_id']);
        });

        Schema::create('etiquetas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        Schema::create('etiqueta_fuente', function (Blueprint $table) {
            $table->foreignId('fuente_id')->constrained('fuentes')->cascadeOnDelete();
            $table->foreignId('etiqueta_id')->constrained('etiquetas')->cascadeOnDelete();
            $table->primary(['fuente_id', 'etiqueta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etiqueta_fuente');
        Schema::dropIfExists('etiquetas');
        Schema::dropIfExists('fuente_oficio');
        Schema::dropIfExists('fuentes');
        Schema::dropIfExists('libro_oficios');
        Schema::dropIfExists('users');
    }
};